# Hosted card payment provider integration

The member checkout and database lifecycle are provider-neutral. No gateway is registered by default, so card payments stay disabled until a real adapter and server credentials are supplied.

## Adapter contract

Create one PHP adapter in this directory and implement `PaymentGateway` from `../payment_gateway.php` using the class name `GymPaymentGateway_PAYTABS` or `GymPaymentGateway_HYPERPAY`. Select it with `PAYMENT_GATEWAY_PROVIDER`. Keep API secrets in the server environment; never send them to browser code or commit them.

- `providerName()` returns the provider identifier stored with the checkout.
- `createCheckout($order)` creates a hosted checkout and returns its HTTPS URL and provider reference. The gym never handles or stores raw card numbers or CVV values.
- `verifyWebhook($payload, $headers)` verifies the provider signature against the raw request and returns the verified order reference, provider reference, status, amount, and currency. Reject unverified callbacks.

The checkout endpoint reserves a `payment_checkouts` row before redirecting. The webhook validates the order, amount, currency, and provider before atomically recording a completed `CARD` payment and activating the membership. Configure the provider webhook URL as `/api/payments/webhook.php`. Set `online_enabled` only after credentials, HTTPS callback delivery, and webhook verification have been tested in the provider sandbox.

Until an adapter is installed, the member dashboard shows card payment as unavailable and continues to offer payment at reception. Do not enable online payments by changing the database flag alone.
