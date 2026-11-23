<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../helpers/payment_settings.php';
auth_require_method('POST');
$staff = staff_user();
auth_require_csrf();
$input = auth_json_input();
$userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$membershipId = ($input['membership_id'] ?? '') === '' ? null : filter_var($input['membership_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$amountInput = $input['amount'] ?? null;
$method = strtoupper(trim((string) ($input['method'] ?? 'CASH')));
$notes = trim((string) ($input['notes'] ?? ''));
if (!$userId || (($input['membership_id'] ?? '') !== '' && !$membershipId)) {
    json_error('Select a valid member and membership.', 422);
}
if (!is_valid_payment_amount($amountInput)) {
    json_error('Enter an amount greater than 0 with up to two decimal places.', 422);
}
try {
    if (!payment_method_is_enabled($method)) {
        json_error('This manual payment method is disabled. Refresh the payment options and try again.', 422);
    }
} catch (Throwable $exception) {
    error_log('Payment method validation failed: ' . $exception->getMessage());
    json_error('Payment options are unavailable. Apply the payment settings migration and try again.', 500);
}
$amountInput = (string) $amountInput;
if (mb_strlen($notes) > 500) {
    json_error('Notes must be 500 characters or fewer.', 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $member = $pdo->prepare(
        'SELECT id FROM users WHERE id = :id AND role = \'MEMBER\' AND status = \'ACTIVE\' FOR UPDATE'
    );
    $member->execute(['id' => $userId]);
    if (!$member->fetch()) {
        $pdo->rollBack();
        json_error('This active member account could not be found.', 404);
    }
    $currency = 'JOD';
    if ($membershipId !== null) {
        $membership = $pdo->prepare(
            'SELECT m.id, m.status, p.currency, p.price FROM memberships m JOIN plans p ON p.id = m.plan_id '
            . 'WHERE m.id = :membership_id AND m.member_id = :member_id FOR UPDATE'
        );
        $membership->execute(['membership_id' => $membershipId, 'member_id' => $userId]);
        $membershipRow = $membership->fetch();
        if (!$membershipRow) {
            $pdo->rollBack();
            json_error('The selected membership does not belong to this member.', 422);
        }
        if ($membershipRow['status'] === 'PENDING' && payment_membership_has_pending_checkout((int) $membershipId, $pdo)) {
            $pdo->rollBack();
            json_error('This membership has an online checkout in progress. Wait for its result before recording a manual payment.', 409);
        }
        $currency = $membershipRow['currency'];
        if ($membershipRow['status'] === 'PENDING') {
            $paid = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE membership_id = :id AND status = \'COMPLETED\'');
            $paid->execute(['id' => $membershipId]);
            if ((float) $paid->fetchColumn() + (float) $amountInput < (float) $membershipRow['price']) {
                $pdo->rollBack();
                json_error('The payment must cover the remaining membership price before activation.', 422);
            }
            $activate = $pdo->prepare('UPDATE memberships SET status = \'ACTIVE\' WHERE id = :id AND status = \'PENDING\'');
            $activate->execute(['id' => $membershipId]);
            if ($activate->rowCount() !== 1) {
                $pdo->rollBack();
                json_error('The membership could not be activated. Refresh and try again.', 409);
            }
        }
    }
    $insert = $pdo->prepare(
        'INSERT INTO payments (member_id, membership_id, amount, currency, method, payment_type, status, received_by, paid_at, notes) '
        . 'VALUES (:member_id, :membership_id, :amount, :currency, :method, \'SUBSCRIPTION\', \'COMPLETED\', :staff_id, UTC_TIMESTAMP(), :notes)'
    );
    $insert->execute([
        'member_id' => $userId,
        'membership_id' => $membershipId,
        'amount' => number_format((float) $amountInput, 2, '.', ''),
        'currency' => $currency,
        'method' => $method,
        'staff_id' => $staff['id'],
        'notes' => $notes === '' ? null : $notes,
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->commit();
    json_success([
        'payment_id' => $paymentId,
        'amount' => number_format((float) $amountInput, 2, '.', ''),
        'currency' => $currency,
        'method' => $method,
    ], $membershipId !== null && isset($membershipRow['status']) && $membershipRow['status'] === 'PENDING'
        ? 'Payment recorded and membership activated.'
        : 'Payment recorded.', 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Staff payment failed: ' . $exception->getMessage());
    json_error('Payment could not be recorded right now.', 500);
}
