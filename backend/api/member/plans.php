<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../helpers/payment_settings.php';
require_once __DIR__ . '/../../helpers/payment_gateway.php';
auth_require_method('GET');
member_user();

try {
    $plans = db()->query(
        'SELECT id, title, description, duration_days, price, currency, includes_info '
        . 'FROM plans WHERE is_active = 1 ORDER BY duration_days, price'
    )->fetchAll();
    foreach ($plans as &$plan) {
        $plan['id'] = (int) $plan['id'];
        $plan['duration_days'] = (int) $plan['duration_days'];
        $plan['promo_label'] = $plan['duration_days'] === 90 ? 'FREE MONTH OFFER' : null;
        $plan['original_price'] = $plan['duration_days'] === 90
            ? number_format((float) $plan['price'] + 30, 2, '.', '')
            : null;
    }
    unset($plan);
    $settings = payment_settings_load();
    $cardPaymentAvailable = $settings['online_enabled'] && payment_gateway_is_configured();
    json_success(['plans' => $plans, 'card_payment_available' => $cardPaymentAvailable]);
} catch (Throwable $exception) {
    error_log('Member plan lookup failed: ' . $exception->getMessage());
    json_error('Membership plans are temporarily unavailable.', 500);
}
