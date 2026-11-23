<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/common.php';

/** Require a currently active member for class booking features. */
function classes_member(): array
{
    return auth_require_roles(['MEMBER'], 'Class booking is available to member accounts.');
}

/** Return a valid positive database identifier or stop with a JSON validation error. */
function classes_id(mixed $value, string $field = 'class_id'): int
{
    $id = positive_id($value);
    if ($id === null) {
        json_error('Choose a valid ' . str_replace('_', ' ', $field) . '.', 422);
    }
    return $id;
}

/** Return the latest active membership and its class allowance for the current UTC date. */
function classes_active_membership(PDO $pdo, int $userId, bool $lock = false): array|false
{
    $statement = $pdo->prepare(
        'SELECT m.id, m.start_date, m.end_date, p.monthly_class_limit, p.term_class_limit '
        . 'FROM memberships m JOIN plans p ON p.id = m.plan_id '
        . 'WHERE m.member_id = :member_id AND m.status IN (\'ACTIVE\', \'EXPIRING\') '
        . 'AND m.start_date <= UTC_DATE() AND m.end_date >= UTC_DATE() '
        . 'ORDER BY m.end_date DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute(['member_id' => $userId]);
    return $statement->fetch();
}

/** Parse a timezone-aware ISO date-time and normalize it for MySQL UTC storage. */
function classes_parse_datetime(mixed $value, string $field): DateTimeImmutable
{
    $date = parse_utc_datetime($value);
    if (!$date) {
        json_error('Enter a valid timezone-aware ' . str_replace('_', ' ', $field) . '.', 422);
    }
    return $date;
}
