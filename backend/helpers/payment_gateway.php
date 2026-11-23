<?php
declare(strict_types=1);

/** Provider contract for hosted card checkout integrations. */
interface PaymentGateway
{
    public function providerName(): string;

    /** @return array{checkout_url:string, provider_reference:string} */
    public function createCheckout(array $order): array;

    /** @return array{order_reference:string, provider_reference:string, status:string, amount:string, currency:string}|null */
    public function verifyWebhook(string $payload, array $headers): ?array;
}

/** No provider is registered until the gym selects one and its integration is added. */
function payment_gateway_driver(): ?PaymentGateway
{
    $provider = strtoupper(trim((string) getenv('PAYMENT_GATEWAY_PROVIDER')));
    $allowedProviders = ['PAYTABS', 'HYPERPAY'];
    if (!in_array($provider, $allowedProviders, true)) {
        return null;
    }

    $adapterPath = __DIR__ . '/payment-gateways/' . strtolower($provider) . '.php';
    if (!is_file($adapterPath)) {
        return null;
    }
    require_once $adapterPath;
    $className = 'GymPaymentGateway_' . preg_replace('/[^A-Z0-9]/', '', $provider);
    if (!class_exists($className)) {
        return null;
    }

    $driver = new $className();
    return $driver instanceof PaymentGateway ? $driver : null;
}

function payment_gateway_is_configured(): bool
{
    return payment_gateway_driver() instanceof PaymentGateway;
}
