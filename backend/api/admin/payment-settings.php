<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../helpers/payment_settings.php';
require_once __DIR__ . '/../../helpers/payment_gateway.php';

$admin = admin_user();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    try {
        $settings = payment_settings_load();
        $gateway = payment_gateway_driver();
        $settings['gateway_configured'] = $gateway instanceof PaymentGateway;
        $settings['gateway_provider'] = $gateway?->providerName();
        if (!$settings['gateway_configured']) {
            $settings['online_enabled'] = false;
        }
        json_success(['settings' => $settings]);
    } catch (Throwable $exception) {
        error_log('Payment settings lookup failed: ' . $exception->getMessage());
        json_error('Payment settings are unavailable. Initialize the payment settings and try again.', 500);
    }
}

if ($method !== 'PATCH') {
    header('Allow: GET, PATCH');
    json_error('Method not allowed.', 405);
}

auth_require_csrf();
$input = auth_json_input();
foreach (['cash_enabled', 'bank_transfer_enabled'] as $field) {
    if (!array_key_exists($field, $input) || !is_bool($input[$field])) {
        json_error('Choose whether cash and bank transfer are enabled.', 422);
    }
}
if (!$input['cash_enabled'] && !$input['bank_transfer_enabled']) {
    json_error('At least one manual payment method must remain enabled.', 422);
}
if (array_key_exists('online_enabled', $input) && !is_bool($input['online_enabled'])) {
    json_error('Choose whether online card payments are enabled.', 422);
}
$onlineEnabled = $input['online_enabled'] ?? false;
if ($onlineEnabled && !payment_gateway_is_configured()) {
    json_error('Online payments cannot be enabled until a payment provider is selected and configured.', 409);
}

try {
    $update = db()->prepare(
        'UPDATE payment_settings SET cash_enabled = :cash_enabled, bank_transfer_enabled = :bank_transfer_enabled, '
        . 'online_enabled = :online_enabled, updated_by = :updated_by, updated_at = UTC_TIMESTAMP() WHERE id = 1'
    );
    $update->execute([
        'cash_enabled' => $input['cash_enabled'] ? 1 : 0,
        'bank_transfer_enabled' => $input['bank_transfer_enabled'] ? 1 : 0,
        'online_enabled' => $onlineEnabled ? 1 : 0,
        'updated_by' => $admin['id'],
    ]);
    $settings = payment_settings_load();
    $gateway = payment_gateway_driver();
    $settings['gateway_configured'] = $gateway instanceof PaymentGateway;
    $settings['gateway_provider'] = $gateway?->providerName();
    if (!$settings['gateway_configured']) {
        $settings['online_enabled'] = false;
    }
    json_success(['settings' => $settings], 'Payment settings saved.');
} catch (Throwable $exception) {
    error_log('Payment settings update failed: ' . $exception->getMessage());
    json_error('Payment settings could not be saved. Check the payment settings table and try again.', 500);
}
