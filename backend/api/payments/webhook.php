<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../auth/common.php';
require_once __DIR__ . '/../../helpers/validation.php';
require_once __DIR__ . '/../../helpers/payment_gateway.php';

auth_require_method('POST');
$gateway = payment_gateway_driver();
if (!$gateway) {
    json_error('Payment provider is not configured.', 503);
}
$payload = file_get_contents('php://input');
if (!is_string($payload) || $payload === '' || strlen($payload) > 1048576) {
    json_error('Invalid payment notification.', 400);
}
$headers = function_exists('getallheaders') ? getallheaders() : [];
try {
    $event = $gateway->verifyWebhook($payload, is_array($headers) ? $headers : []);
    if (!$event || !in_array($event['status'] ?? '', ['COMPLETED', 'FAILED'], true)) {
        json_error('The payment notification could not be verified.', 400);
    }
    $orderReference = trim((string) ($event['order_reference'] ?? ''));
    if ($orderReference === '') {
        json_error('The payment notification is missing its order reference.', 400);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $checkoutQuery = $pdo->prepare(
        'SELECT id, membership_id, member_id, provider, provider_reference, order_reference, amount, currency, status '
        . 'FROM payment_checkouts WHERE order_reference = :reference FOR UPDATE'
    );
    $checkoutQuery->execute(['reference' => $orderReference]);
    $checkout = $checkoutQuery->fetch();
    $eventProviderReference = trim((string) ($event['provider_reference'] ?? ''));
    $eventAmount = $event['amount'] ?? null;
    if (!$checkout || $checkout['provider'] !== $gateway->providerName()
        || ($checkout['provider_reference'] !== null && (string) $checkout['provider_reference'] !== $eventProviderReference)
        || $eventProviderReference === ''
        || !is_valid_nonnegative_amount($eventAmount)
        || number_format((float) $checkout['amount'], 2, '.', '') !== number_format((float) $eventAmount, 2, '.', '')
        || $checkout['currency'] !== strtoupper((string) ($event['currency'] ?? ''))) {
        $pdo->rollBack();
        json_error('The payment details do not match the pending checkout.', 409);
    }
    if ($checkout['status'] === 'COMPLETED') {
        $pdo->commit();
        json_success(['processed' => true, 'duplicate' => true]);
    }
    if ($checkout['status'] !== 'PENDING') {
        $pdo->commit();
        json_success(['processed' => false, 'duplicate' => true]);
    }

    if ($event['status'] === 'FAILED') {
        $failed = $pdo->prepare('UPDATE payment_checkouts SET status = \'FAILED\', provider_reference = :provider_reference WHERE id = :id AND status = \'PENDING\'');
        $failed->execute(['provider_reference' => $eventProviderReference, 'id' => $checkout['id']]);
        $pdo->commit();
        json_success(['processed' => true, 'status' => 'FAILED']);
    }

    $membershipQuery = $pdo->prepare(
        'SELECT id, status FROM memberships WHERE id = :id AND member_id = :member_id FOR UPDATE'
    );
    $membershipQuery->execute(['id' => $checkout['membership_id'], 'member_id' => $checkout['member_id']]);
    $membership = $membershipQuery->fetch();
    if (!$membership || $membership['status'] !== 'PENDING') {
        $pdo->rollBack();
        json_error('The membership is no longer awaiting payment.', 409);
    }
    $payment = $pdo->prepare(
        'INSERT INTO payments (member_id, membership_id, amount, currency, method, payment_type, status, transaction_reference, paid_at) '
        . 'VALUES (:member_id, :membership_id, :amount, :currency, \'CARD\', \'SUBSCRIPTION\', \'COMPLETED\', :reference, UTC_TIMESTAMP())'
    );
    $payment->execute([
        'member_id' => $checkout['member_id'], 'membership_id' => $checkout['membership_id'],
        'amount' => $checkout['amount'], 'currency' => $checkout['currency'], 'reference' => $eventProviderReference,
    ]);
    if ($checkout['provider_reference'] === null) {
        $reference = $pdo->prepare('UPDATE payment_checkouts SET provider_reference = :provider_reference WHERE id = :id');
        $reference->execute(['provider_reference' => $eventProviderReference, 'id' => $checkout['id']]);
    }
    $activate = $pdo->prepare('UPDATE memberships SET status = \'ACTIVE\' WHERE id = :id AND status = \'PENDING\'');
    $activate->execute(['id' => $checkout['membership_id']]);
    if ($activate->rowCount() !== 1) {
        throw new RuntimeException('Membership activation was not applied.');
    }
    $complete = $pdo->prepare('UPDATE payment_checkouts SET status = \'COMPLETED\' WHERE id = :id AND status = \'PENDING\'');
    $complete->execute(['id' => $checkout['id']]);
    $pdo->commit();
    json_success(['processed' => true, 'status' => 'COMPLETED']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Payment webhook processing failed: ' . $exception->getMessage());
    json_error('Payment notification processing failed.', 500);
}
