<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../helpers/payment_settings.php';
require_once __DIR__ . '/../../helpers/payment_gateway.php';
auth_require_method('POST');
$member = member_user();
auth_require_csrf();
$input = auth_json_input();
$planId = positive_id($input['plan_id'] ?? null);
if ($planId === null) json_error('Choose a valid membership plan.', 422);
$paymentMethod = strtoupper((string) ($input['payment_method'] ?? 'RECEPTION'));
if (!in_array($paymentMethod, ['CARD', 'RECEPTION'], true)) json_error('Choose a valid payment method.', 422);

try {
    $settings = payment_settings_load();
    $cardPaymentAvailable = $settings['online_enabled'] && payment_gateway_is_configured();
    if ($paymentMethod === 'CARD' && !$cardPaymentAvailable) {
        json_error('Card payment is not available yet. Choose reception payment.', 503);
    }
} catch (Throwable $exception) {
    error_log('Member payment availability lookup failed: ' . $exception->getMessage());
    json_error('Payment options are temporarily unavailable.', 503);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $account = $pdo->prepare('SELECT id FROM users WHERE id = :id AND status = \'ACTIVE\' FOR UPDATE');
    $account->execute(['id' => $member['id']]);
    if (!$account->fetch()) { $pdo->rollBack(); json_error('Your account is not active.', 403); }
    $planQuery = $pdo->prepare('SELECT id, duration_days, price FROM plans WHERE id = :id AND is_active = 1 FOR UPDATE');
    $planQuery->execute(['id' => $planId]);
    $plan = $planQuery->fetch();
    if (!$plan) { $pdo->rollBack(); json_error('Choose an available membership plan.', 404); }
    $pending = $pdo->prepare('SELECT id FROM memberships WHERE member_id = :id AND status = \'PENDING\' LIMIT 1 FOR UPDATE');
    $pending->execute(['id' => $member['id']]);
    if ($pending->fetch()) { $pdo->rollBack(); json_error('You already have a membership request waiting for review.', 409); }
    $current = $pdo->prepare(
        'SELECT end_date FROM memberships WHERE member_id = :id AND status IN (\'ACTIVE\', \'EXPIRING\') '
        . 'AND end_date >= UTC_DATE() ORDER BY end_date DESC LIMIT 1 FOR UPDATE'
    );
    $current->execute(['id' => $member['id']]);
    $currentEnd = $current->fetchColumn();
    $starts = $currentEnd ? (new DateTimeImmutable((string) $currentEnd, new DateTimeZone('UTC')))->modify('+1 day') : new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $ends = $starts->modify('+' . ((int) $plan['duration_days'] - 1) . ' days');
    $insert = $pdo->prepare(
        'INSERT INTO memberships (member_id, plan_id, start_date, end_date, status) '
        . 'VALUES (:member_id, :plan_id, :starts, :ends, \'PENDING\')'
    );
    $insert->execute(['member_id' => $member['id'], 'plan_id' => $planId, 'starts' => $starts->format('Y-m-d'), 'ends' => $ends->format('Y-m-d')]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    json_success([
        'membership_id' => $id,
        'starts_on' => $starts->format('Y-m-d'),
        'ends_on' => $ends->format('Y-m-d'),
        'online_available' => $paymentMethod === 'CARD',
        'payment_method' => $paymentMethod,
    ], $paymentMethod === 'CARD' ? 'Membership request submitted. Continue to secure card checkout.' : 'Membership request submitted. Complete payment at reception for approval.', 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Member renewal request failed: ' . $exception->getMessage());
    json_error('Your membership request could not be submitted.', 500);
}
