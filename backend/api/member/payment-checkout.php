<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../helpers/payment_settings.php';
require_once __DIR__ . '/../../helpers/payment_gateway.php';

auth_require_method('POST');
$member = member_user();
auth_require_csrf();
$input = auth_json_input();
$membershipId = positive_id($input['membership_id'] ?? null);
if ($membershipId === null) {
    json_error('Choose a valid pending membership.', 422);
}

try {
    $settings = payment_settings_load();
    $gateway = payment_gateway_driver();
    if (!$settings['online_enabled'] || !$gateway) {
        json_error('Online card payments are not configured yet. Complete payment at reception.', 503);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $membershipQuery = $pdo->prepare(
        'SELECT m.id, m.status, m.member_id, p.title AS plan_name, p.price, p.currency '
        . 'FROM memberships m JOIN plans p ON p.id = m.plan_id '
        . 'WHERE m.id = :membership_id AND m.member_id = :member_id FOR UPDATE'
    );
    $membershipQuery->execute(['membership_id' => $membershipId, 'member_id' => $member['id']]);
    $membership = $membershipQuery->fetch();
    if (!$membership || $membership['status'] !== 'PENDING') {
        json_error('This pending membership is unavailable for payment.', 404);
    }
    if ($membership['currency'] !== 'JOD') {
        json_error('Online membership checkout currently supports JOD plans only.', 422);
    }
    $existing = $pdo->prepare(
        'SELECT order_reference, checkout_url FROM payment_checkouts '
        . 'WHERE membership_id = :membership_id AND status = \'PENDING\' '
        . 'ORDER BY id DESC LIMIT 1'
    );
    $existing->execute(['membership_id' => $membershipId]);
    $pendingCheckout = $existing->fetch();
    if ($pendingCheckout) {
        $pdo->commit();
        if (filter_var($pendingCheckout['checkout_url'], FILTER_VALIDATE_URL)) {
            json_success(['checkout_url' => $pendingCheckout['checkout_url'], 'membership_id' => $membershipId]);
        }
        json_error('A card checkout is already being prepared for this membership. Try again shortly.', 409);
    }

    $orderReference = 'PU-' . bin2hex(random_bytes(16));
    $amount = number_format((float) $membership['price'], 2, '.', '');
    $reserve = $pdo->prepare(
        'INSERT INTO payment_checkouts (membership_id, member_id, provider, order_reference, amount, currency) '
        . 'VALUES (:membership_id, :member_id, :provider, :order_reference, :amount, :currency)'
    );
    $reserve->execute([
        'membership_id' => $membershipId,
        'member_id' => $member['id'],
        'provider' => $gateway->providerName(),
        'order_reference' => $orderReference,
        'amount' => $amount,
        'currency' => $membership['currency'],
    ]);
    $checkoutId = (int) $pdo->lastInsertId();
    $pdo->commit();
    $checkout = $gateway->createCheckout([
        'order_reference' => $orderReference,
        'membership_id' => $membershipId,
        'member_id' => (int) $member['id'],
        'member_name' => $member['full_name'],
        'member_email' => $member['email'],
        'plan_name' => $membership['plan_name'],
        'amount' => $amount,
        'currency' => $membership['currency'],
        'return_url' => payment_public_url('member.html?payment=return'),
        'webhook_url' => payment_public_url('api/payments/webhook.php'),
    ]);
    $checkoutUrl = filter_var($checkout['checkout_url'] ?? null, FILTER_VALIDATE_URL);
    $providerReference = trim((string) ($checkout['provider_reference'] ?? ''));
    if (!$checkoutUrl || strtolower((string) parse_url($checkoutUrl, PHP_URL_SCHEME)) !== 'https'
        || $providerReference === '' || mb_strlen($providerReference) > 150) {
        throw new RuntimeException('The payment provider returned an invalid checkout response.');
    }
    $update = $pdo->prepare(
        'UPDATE payment_checkouts SET provider_reference = :provider_reference, checkout_url = :checkout_url '
        . 'WHERE id = :id AND status = \'PENDING\' AND provider_reference IS NULL'
    );
    $update->execute(['provider_reference' => $providerReference, 'checkout_url' => $checkoutUrl, 'id' => $checkoutId]);
    if ($update->rowCount() !== 1) {
        $current = $pdo->prepare('SELECT status, provider_reference FROM payment_checkouts WHERE id = :id');
        $current->execute(['id' => $checkoutId]);
        $currentCheckout = $current->fetch();
        if (!$currentCheckout || $currentCheckout['status'] !== 'COMPLETED' || $currentCheckout['provider_reference'] !== $providerReference) {
            throw new RuntimeException('The reserved checkout could not be updated.');
        }
        $saveUrl = $pdo->prepare('UPDATE payment_checkouts SET checkout_url = :checkout_url WHERE id = :id');
        $saveUrl->execute(['checkout_url' => $checkoutUrl, 'id' => $checkoutId]);
    }
    json_success(['checkout_url' => $checkoutUrl, 'membership_id' => $membershipId]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($pdo, $checkoutId) && !$pdo->inTransaction()) {
        try {
            $failed = $pdo->prepare('UPDATE payment_checkouts SET status = \'FAILED\' WHERE id = :id AND status = \'PENDING\' AND provider_reference IS NULL');
            $failed->execute(['id' => $checkoutId]);
        } catch (Throwable) {
        }
    }
    error_log('Member payment checkout failed: ' . $exception->getMessage());
    json_error('Online checkout could not be started. Complete payment at reception or try again.', 502);
}

function payment_public_url(string $path): string
{
    $configuredBase = trim((string) getenv('APP_PUBLIC_URL'));
    if ($configuredBase !== '') {
        return rtrim($configuredBase, '/') . '/' . ltrim($path, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $apiPosition = strpos($scriptName, '/backend/api/');
    $basePath = $apiPosition === false ? '' : substr($scriptName, 0, $apiPosition);
    return $scheme . '://' . $host . rtrim($basePath, '/') . '/' . ltrim($path, '/');
}
