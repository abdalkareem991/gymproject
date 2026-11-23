<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/helpers/validation.php';
require_once __DIR__ . '/../backend/api/auth/common.php';
require_once __DIR__ . '/../backend/api/classes/common.php';

/** Assert exact equality and include useful values on failure. */
function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message === '' ? 'Values differ.' : $message)
            . ' Expected ' . var_export($expected, true)
            . ', received ' . var_export($actual, true) . '.'
        );
    }
}

/** Assert a boolean condition. */
function assert_true(bool $condition, string $message = 'Expected condition to be true.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Assert a boolean condition is false. */
function assert_false(bool $condition, string $message = 'Expected condition to be false.'): void
{
    assert_true(!$condition, $message);
}

$tests = [
    'parses timezone-aware ISO date-times into UTC' => static function (): void {
        $date = parse_utc_datetime('2026-04-02T13:45:00+03:00');
        assert_same('2026-04-02 10:45:00', $date?->format('Y-m-d H:i:s'));
        assert_same('UTC', $date?->getTimezone()->getName());
    },
    'accepts ISO date-times with minute precision' => static function (): void {
        $date = parse_utc_datetime('2026-04-02T10:05Z');
        assert_same('2026-04-02 10:05:00', $date?->format('Y-m-d H:i:s'));
    },
    'rejects missing timezones and invalid calendar rollovers' => static function (): void {
        assert_same(null, parse_utc_datetime('2026-04-02T10:05:00'));
        assert_same(null, parse_utc_datetime('2026-02-30T10:05:00Z'));
        assert_same(null, parse_utc_datetime('2026-04-02T10:05:00+99:00'));
        assert_same(null, parse_utc_datetime(['2026-04-02T10:05:00Z']));
    },
    'accepts strict UTC calendar dates including leap day' => static function (): void {
        assert_same('2024-02-29', parse_utc_date('2024-02-29')?->format('Y-m-d'));
        assert_same(null, parse_utc_date('2025-02-29'));
        assert_same(null, parse_utc_date('2026-1-02'));
        assert_same(null, parse_utc_date(['2026-01-02']));
    },
    'limits report ranges to 366 inclusive UTC dates' => static function (): void {
        $start = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        assert_true(is_valid_utc_date_range($start, $start));
        assert_true(is_valid_utc_date_range($start, $start->modify('+365 days')));
        assert_false(is_valid_utc_date_range($start, $start->modify('+366 days')));
        assert_false(is_valid_utc_date_range($start, $start->modify('-1 day')));
    },
    'validates positive database identifiers' => static function (): void {
        assert_same(19, positive_id('19'));
        assert_same(19, positive_id(19));
        assert_same(null, positive_id('0'));
        assert_same(null, positive_id('-4'));
        assert_same(null, positive_id('19x'));
        assert_same(null, positive_id(1.5));
        assert_same(null, positive_id([]));
    },
    'accepts payment amounts within DECIMAL precision' => static function (): void {
        assert_true(is_valid_payment_amount('0.01'));
        assert_true(is_valid_payment_amount('12'));
        assert_true(is_valid_payment_amount('99999999.99'));
        assert_false(is_valid_payment_amount('0'));
        assert_false(is_valid_payment_amount('0.00'));
        assert_false(is_valid_payment_amount('-2.50'));
        assert_false(is_valid_payment_amount('1.234'));
        assert_false(is_valid_payment_amount('100000000.00'));
        assert_false(is_valid_payment_amount('1e2'));
        assert_false(is_valid_payment_amount([]));
    },
    'accepts free and paid plan prices within DECIMAL precision' => static function (): void {
        assert_true(is_valid_nonnegative_amount('0'));
        assert_true(is_valid_nonnegative_amount('0.00'));
        assert_true(is_valid_nonnegative_amount('99999999.99'));
        assert_false(is_valid_nonnegative_amount('-0.01'));
        assert_false(is_valid_nonnegative_amount('1.234'));
        assert_false(is_valid_nonnegative_amount('100000000.00'));
    },
    'validates future class start and positive duration' => static function (): void {
        $now = new DateTimeImmutable('2026-04-02T10:00:00Z');
        $start = new DateTimeImmutable('2026-04-02T10:05:00Z');
        assert_true(is_valid_class_time_range($start, $start->modify('+4 hours'), $now));
        assert_false(is_valid_class_time_range($start, $start->modify('+4 hours +1 second'), $now));
        assert_false(is_valid_class_time_range($start, $start, $now));
        assert_false(is_valid_class_time_range($now, $now->modify('+1 hour'), $now));
        assert_false(is_valid_class_time_range($start, $start->modify('-1 minute'), $now));
    },
    'neutralizes spreadsheet formulas while preserving regular CSV text' => static function (): void {
        assert_same("'=SUM(A1:A2)", safe_csv_text('=SUM(A1:A2)'));
        assert_same("'  @cmd", safe_csv_text('  @cmd'));
        assert_same('member@example.com', safe_csv_text('member@example.com'));
        assert_same('', safe_csv_text(null));
    },
    'checks roles using exact matches' => static function (): void {
        assert_true(auth_role_allowed('ADMIN', ['ADMIN', 'STAFF']));
        assert_false(auth_role_allowed('admin', ['ADMIN']));
        assert_false(auth_role_allowed('MEMBER', ['ADMIN', 'STAFF']));
    },
    'compares CSRF tokens safely' => static function (): void {
        assert_true(auth_csrf_matches('secret-token', 'secret-token'));
        assert_false(auth_csrf_matches('secret-token', 'other-token'));
        assert_false(auth_csrf_matches('', ''));
        assert_false(auth_csrf_matches('secret-token', null));
        assert_false(auth_csrf_matches([], 'secret-token'));
    },
    'exposes only public account fields' => static function (): void {
        $public = auth_public_user([
            'id' => '8',
            'first_name' => 'Ava',
            'last_name' => 'Member',
            'email' => 'ava@example.test',
            'role' => 'MEMBER',
            'status' => 'ACTIVE',
            'password_hash' => 'secret',
        ]);
        assert_same([
            'id' => 8,
            'first_name' => 'Ava',
            'last_name' => 'Member',
            'email' => 'ava@example.test',
            'role' => 'MEMBER',
        ], $public);
    },
];

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: {$name}\n";
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n  {$exception->getMessage()}\n");
    }
}

$total = count($tests);
echo sprintf("\n%d tests, %d passed, %d failed.\n", $total, $total - $failures, $failures);
exit($failures === 0 ? 0 : 1);
