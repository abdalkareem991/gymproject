<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('GET');
$user = member_user();

try {
    $query = db()->prepare(
        'SELECT m.id, m.start_date AS starts_on, m.end_date AS ends_on, m.status, '
        . 'p.title AS plan_name, p.description AS plan_description, p.currency '
        . 'FROM memberships m JOIN plans p ON p.id = m.plan_id '
        . 'WHERE m.member_id = :member_id AND m.status IN (\'ACTIVE\', \'EXPIRING\', \'PENDING\', \'EXPIRED\') '
        . 'ORDER BY CASE m.status WHEN \'ACTIVE\' THEN 0 WHEN \'EXPIRING\' THEN 1 '
        . 'WHEN \'PENDING\' THEN 2 ELSE 3 END, m.end_date DESC LIMIT 1'
    );
    $query->execute(['member_id' => $user['id']]);
    $membership = $query->fetch() ?: null;
    if ($membership) {
        if (in_array($membership['status'], ['ACTIVE', 'EXPIRING'], true)
            && $membership['ends_on'] < gmdate('Y-m-d')) {
            $membership['status'] = 'EXPIRED';
        }
        $membership['id'] = (int) $membership['id'];
        $start = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $end = new DateTimeImmutable($membership['ends_on'], new DateTimeZone('UTC'));
        $membership['days_remaining'] = $end < $start
            ? 0
            : (int) $start->diff($end)->format('%a') + 1;
    }

    $activity = db()->prepare(
        'SELECT COUNT(CASE WHEN c.starts_at > UTC_TIMESTAMP() AND b.status = \'BOOKED\' THEN 1 END) AS upcoming_bookings, '
        . 'COUNT(CASE WHEN b.status = \'ATTENDED\' THEN 1 END) AS attended_sessions '
        . 'FROM class_bookings b JOIN fitness_classes c ON c.id = b.class_id WHERE b.member_id = :member_id'
    );
    $activity->execute(['member_id' => $user['id']]);
    $activitySummary = $activity->fetch() ?: ['upcoming_bookings' => 0, 'attended_sessions' => 0];
    $payments = db()->prepare(
        'SELECT pay.id, pay.membership_id, pay.amount, pay.currency, pay.method, pay.status, pay.paid_at, p.title AS plan_name '
        . 'FROM payments pay LEFT JOIN memberships m ON m.id = pay.membership_id LEFT JOIN plans p ON p.id = m.plan_id '
        . 'WHERE pay.member_id = :member_id ORDER BY pay.paid_at DESC, pay.id DESC LIMIT 100'
    );
    $payments->execute(['member_id' => $user['id']]);
    $bookings = db()->prepare(
        'SELECT b.id AS booking_id, b.status AS booking_status, c.id AS class_id, c.title, c.starts_at, c.ends_at, '
        . 'ct.name AS class_type, SUBSTRING_INDEX(t.full_name, \' \', 1) AS trainer_first_name, '
        . 'TRIM(SUBSTRING(t.full_name, CHAR_LENGTH(SUBSTRING_INDEX(t.full_name, \' \', 1)) + 1)) AS trainer_last_name '
        . 'FROM class_bookings b JOIN fitness_classes c ON c.id = b.class_id '
        . 'JOIN class_types ct ON ct.id = c.class_type_id JOIN users t ON t.id = c.trainer_id '
        . 'WHERE b.member_id = :member_id AND b.status <> \'CANCELLED\' '
        . 'AND (c.starts_at > UTC_TIMESTAMP() OR b.status IN (\'ATTENDED\', \'CANCELLED\')) ORDER BY c.starts_at DESC LIMIT 30'
    );
    $bookings->execute(['member_id' => $user['id']]);

    json_success([
        'user' => $user,
        'membership' => $membership,
        'activity' => $activitySummary,
        'payments' => $payments->fetchAll(),
        'bookings' => $bookings->fetchAll(),
    ]);
} catch (Throwable $exception) {
    error_log('Member dashboard failed: ' . $exception->getMessage());
    json_error('Your member information is temporarily unavailable.', 500);
}
