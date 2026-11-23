<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../helpers/payment_settings.php';
$admin = admin_user();
$methodRequest = $_SERVER['REQUEST_METHOD'] ?? '';

if ($methodRequest === 'GET') {
    try {
        $requests = db()->query(
            'SELECT m.id, m.member_id, m.plan_id, m.start_date, m.end_date, m.created_at, '
            . 'u.full_name, u.email, u.phone, p.title AS plan_name, p.price, p.currency '
            . 'FROM memberships m JOIN users u ON u.id = m.member_id JOIN plans p ON p.id = m.plan_id '
            . 'WHERE m.status = \'PENDING\' ORDER BY m.created_at ASC LIMIT 500'
        )->fetchAll();
        foreach ($requests as &$request) {
            $request['id'] = (int) $request['id'];
            $request['member_id'] = (int) $request['member_id'];
            $request['plan_id'] = (int) $request['plan_id'];
        }
        unset($request);
        json_success(['requests' => $requests]);
    } catch (Throwable $exception) {
        error_log('Pending membership lookup failed: ' . $exception->getMessage());
        json_error('Pending membership requests are temporarily unavailable.', 500);
    }
}

if ($methodRequest === 'PATCH') {
    auth_require_csrf();
    $input = auth_json_input();
    $membershipId = positive_id($input['membership_id'] ?? null);
    $decision = $input['decision'] ?? '';
    $method = $input['method'] ?? 'CASH';
    $amount = $input['amount'] ?? null;
    if ($membershipId === null || !in_array($decision, ['APPROVED', 'REJECTED'], true)) {
        json_error('Choose a valid membership request and decision.', 422);
    }
    if ($decision === 'APPROVED' && (!is_valid_nonnegative_amount($amount) || !is_string($method))) {
        json_error('Enter a valid payment amount and method to activate the membership.', 422);
    }
    if ($decision === 'APPROVED') {
        try {
            if (!payment_method_is_enabled($method)) {
                json_error('This manual payment method is disabled. Refresh payment settings and try again.', 422);
            }
        } catch (Throwable $exception) {
            error_log('Membership review payment method validation failed: ' . $exception->getMessage());
            json_error('Payment options are unavailable. Apply the payment settings migration and try again.', 500);
        }
    }
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $query = $pdo->prepare(
            'SELECT m.id, m.member_id, m.plan_id, m.start_date, m.end_date, u.status AS user_status, u.role AS user_role, '
            . 'p.currency FROM memberships m JOIN users u ON u.id = m.member_id JOIN plans p ON p.id = m.plan_id '
            . 'WHERE m.id = :id AND m.status = \'PENDING\' FOR UPDATE'
        );
        $query->execute(['id' => $membershipId]);
        $membership = $query->fetch();
        if (!$membership) {
            $pdo->rollBack();
            json_error('Pending membership request not found.', 404);
        }
        if (payment_membership_has_pending_checkout($membershipId, $pdo)) {
            $pdo->rollBack();
            json_error('This membership has an online checkout in progress. Wait for its result before reviewing it.', 409);
        }
        if ($decision === 'REJECTED') {
            $update = $pdo->prepare('UPDATE memberships SET status = \'CANCELLED\' WHERE id = :id');
            $update->execute(['id' => $membershipId]);
        } else {
            if ($membership['user_status'] !== 'ACTIVE' || $membership['user_role'] !== 'MEMBER') {
                $pdo->rollBack();
                json_error('Activate the user account before approving this membership.', 409);
            }
            $update = $pdo->prepare('UPDATE memberships SET status = \'ACTIVE\', created_by = :admin_id WHERE id = :id');
            $update->execute(['admin_id' => $admin['id'], 'id' => $membershipId]);
            $payment = $pdo->prepare(
                'INSERT INTO payments (member_id, membership_id, amount, currency, method, payment_type, status, received_by, paid_at) '
                . 'VALUES (:member_id, :membership_id, :amount, :currency, :method, \'SUBSCRIPTION\', \'COMPLETED\', :admin_id, UTC_TIMESTAMP())'
            );
            $payment->execute([
                'member_id' => $membership['member_id'],
                'membership_id' => $membershipId,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'currency' => $membership['currency'],
                'method' => $method,
                'admin_id' => $admin['id'],
            ]);
        }
        $pdo->commit();
        json_success(['membership_id' => $membershipId, 'status' => $decision === 'APPROVED' ? 'ACTIVE' : 'CANCELLED'], 'Membership request updated.');
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Pending membership review failed: ' . $exception->getMessage());
        json_error('The membership request could not be updated right now.', 500);
    }
}

if ($methodRequest !== 'POST') {
    header('Allow: GET, POST, PATCH');
    json_error('Method not allowed.', 405);
}
auth_require_method('POST');
auth_require_csrf();
$input = auth_json_input();
$memberId = positive_id($input['user_id'] ?? null);
$planId = positive_id($input['plan_id'] ?? null);
$startsOn = parse_utc_date($input['starts_on'] ?? null);
$amount = $input['amount'] ?? null;
$method = $input['method'] ?? 'CASH';

if ($memberId === null || $planId === null || !$startsOn) {
    json_error('Choose an active member, plan, and valid start date.', 422);
}
if ($startsOn < new DateTimeImmutable('today', new DateTimeZone('UTC'))) {
    json_error('Memberships cannot start in the past.', 422);
}
if (!is_valid_nonnegative_amount($amount)) {
    json_error('Enter a valid payment amount with up to two decimal places.', 422);
}
if (!is_string($method)) {
    json_error('Choose an enabled manual payment method.', 422);
}
try {
    if (!payment_method_is_enabled($method)) {
        json_error('Choose an enabled manual payment method.', 422);
    }
} catch (Throwable $exception) {
    error_log('Membership payment method validation failed: ' . $exception->getMessage());
    json_error('Payment options are unavailable. Apply the payment settings migration and try again.', 500);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $memberQuery = $pdo->prepare(
        'SELECT id FROM users WHERE id = :id AND role = \'MEMBER\' AND status = \'ACTIVE\' FOR UPDATE'
    );
    $memberQuery->execute(['id' => $memberId]);
    if (!$memberQuery->fetch()) {
        $pdo->rollBack();
        json_error('This active member account could not be found.', 404);
    }

    $planQuery = $pdo->prepare(
        'SELECT id, duration_days, price, currency FROM plans WHERE id = :id AND is_active = 1 FOR UPDATE'
    );
    $planQuery->execute(['id' => $planId]);
    $plan = $planQuery->fetch();
    if (!$plan) {
        $pdo->rollBack();
        json_error('Choose an active membership plan.', 404);
    }

    $endsOn = $startsOn->modify('+' . ((int) $plan['duration_days'] - 1) . ' days');
    $existingQuery = $pdo->prepare(
        'SELECT id FROM memberships WHERE member_id = :member_id AND status IN (\'PENDING\', \'ACTIVE\', \'EXPIRING\') '
        . 'AND start_date <= :end_date AND end_date >= :start_date LIMIT 1 FOR UPDATE'
    );
    $existingQuery->execute([
        'member_id' => $memberId,
        'start_date' => $startsOn->format('Y-m-d'),
        'end_date' => $endsOn->format('Y-m-d'),
    ]);
    if ($existingQuery->fetch()) {
        $pdo->rollBack();
        json_error('This member already has a membership covering the selected start date.', 409);
    }

    $membershipInsert = $pdo->prepare(
        'INSERT INTO memberships (member_id, plan_id, start_date, end_date, status, created_by) '
        . 'VALUES (:member_id, :plan_id, :start_date, :end_date, \'ACTIVE\', :created_by)'
    );
    $membershipInsert->execute([
        'member_id' => $memberId,
        'plan_id' => $planId,
        'start_date' => $startsOn->format('Y-m-d'),
        'end_date' => $endsOn->format('Y-m-d'),
        'created_by' => $admin['id'],
    ]);
    $membershipId = (int) $pdo->lastInsertId();

    $paymentInsert = $pdo->prepare(
        'INSERT INTO payments (member_id, membership_id, amount, currency, method, payment_type, status, received_by, paid_at) '
        . 'VALUES (:member_id, :membership_id, :amount, :currency, :method, \'SUBSCRIPTION\', \'COMPLETED\', :received_by, UTC_TIMESTAMP())'
    );
    $paymentInsert->execute([
        'member_id' => $memberId,
        'membership_id' => $membershipId,
        'amount' => number_format((float) $amount, 2, '.', ''),
        'currency' => $plan['currency'],
        'method' => $method,
        'received_by' => $admin['id'],
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->commit();

    json_success([
        'membership_id' => $membershipId,
        'payment_id' => $paymentId,
        'starts_on' => $startsOn->format('Y-m-d'),
        'ends_on' => $endsOn->format('Y-m-d'),
        'currency' => $plan['currency'],
    ], 'Membership activated and payment recorded.', 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Membership activation failed: ' . $exception->getMessage());
    json_error('The membership could not be activated right now.', 500);
}
