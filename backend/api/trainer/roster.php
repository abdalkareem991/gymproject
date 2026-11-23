<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('GET');
$user = trainer_user();
$classId = classes_id($_GET['class_id'] ?? null);

try {
    $classQuery = db()->prepare(
        'SELECT id, trainer_id, title, starts_at, ends_at, status, capacity '
        . 'FROM fitness_classes WHERE id = :id LIMIT 1'
    );
    $classQuery->execute(['id' => $classId]);
    $class = $classQuery->fetch();
    if (!$class || !trainer_can_manage($user, $class)) {
        json_error('Class not found or access denied.', 404);
    }

    $rosterQuery = db()->prepare(
        'SELECT b.id AS booking_id, b.status AS booking_status, b.booked_at, '
        . 'u.id AS user_id, u.member_code, u.phone, SUBSTRING_INDEX(u.full_name, \' \', 1) AS first_name, '
        . 'TRIM(SUBSTRING(u.full_name, CHAR_LENGTH(SUBSTRING_INDEX(u.full_name, \' \', 1)) + 1)) AS last_name, u.email, '
        . 'CASE WHEN EXISTS (SELECT 1 FROM memberships m WHERE m.member_id = u.id '
        . 'AND m.status IN (\'ACTIVE\', \'EXPIRING\') AND m.start_date <= UTC_DATE() AND m.end_date >= UTC_DATE()) '
        . 'THEN \'ACTIVE\' ELSE \'INACTIVE\' END AS subscription_status '
        . 'FROM class_bookings b JOIN users u ON u.id = b.member_id '
        . 'WHERE b.class_id = :class_id AND b.status <> \'CANCELLED\' ORDER BY u.full_name'
    );
    $rosterQuery->execute(['class_id' => $classId]);
    $roster = $rosterQuery->fetchAll();
    foreach ($roster as &$booking) {
        $booking['booking_id'] = (int) $booking['booking_id'];
        $booking['user_id'] = (int) $booking['user_id'];
    }
    unset($booking);
    $class['id'] = (int) $class['id'];
    $class['capacity'] = (int) $class['capacity'];
    json_success(['class' => $class, 'roster' => $roster]);
} catch (Throwable $exception) {
    error_log('Class roster lookup failed: ' . $exception->getMessage());
    json_error('The class roster is temporarily unavailable.', 500);
}
