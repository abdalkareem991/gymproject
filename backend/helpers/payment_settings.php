<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/** Load the persisted manual payment options without exposing gateway secrets. */
function payment_settings_load(?PDO $pdo = null): array
{
    $pdo ??= db();
    $settings = $pdo->query(
        'SELECT cash_enabled, bank_transfer_enabled, online_enabled FROM payment_settings WHERE id = 1 LIMIT 1'
    )->fetch();

    if (!$settings) {
        throw new RuntimeException('Payment settings have not been initialized.');
    }

    return [
        'cash_enabled' => (bool) $settings['cash_enabled'],
        'bank_transfer_enabled' => (bool) $settings['bank_transfer_enabled'],
        'online_enabled' => (bool) $settings['online_enabled'],
        'currency' => 'JOD',
        'gateway_provider' => null,
        'gateway_configured' => false,
    ];
}

/** Return payment methods enabled for manual collection. */
function payment_settings_methods(array $settings): array
{
    $methods = [];
    if ($settings['cash_enabled']) {
        $methods[] = 'CASH';
    }
    if ($settings['bank_transfer_enabled']) {
        $methods[] = 'BANK_TRANSFER';
    }
    return $methods;
}

/** Check the configured allowlist before recording a manual payment. */
function payment_method_is_enabled(string $method, ?PDO $pdo = null): bool
{
    return in_array($method, payment_settings_methods(payment_settings_load($pdo)), true);
}

/** Prevent manual activation while a member has an active hosted checkout. */
function payment_membership_has_pending_checkout(int $membershipId, ?PDO $pdo = null): bool
{
    $pdo ??= db();
    $query = $pdo->prepare(
        'SELECT id FROM payment_checkouts WHERE membership_id = :membership_id AND status = \'PENDING\' LIMIT 1'
    );
    $query->execute(['membership_id' => $membershipId]);
    return (bool) $query->fetchColumn();
}
