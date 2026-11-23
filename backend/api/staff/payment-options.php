<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../helpers/payment_settings.php';

auth_require_method('GET');
staff_user();

try {
    $settings = payment_settings_load();
    json_success([
        'methods' => payment_settings_methods($settings),
        'currency' => $settings['currency'],
    ]);
} catch (Throwable $exception) {
    error_log('Staff payment options lookup failed: ' . $exception->getMessage());
    json_error('Payment options are unavailable. Apply the payment settings migration and try again.', 500);
}
