<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('GET');
$member = classes_member();

try {
    $statement = db()->prepare(
        'SELECT c.id, c.trainer_id, c.title, c.description, c.starts_at, c.ends_at, c.capacity, c.status, ct.name AS class_type, '
        . 'SUBSTRING_INDEX(trainer.full_name, \' \', 1) AS trainer_first_name, '
        . 'TRIM(SUBSTRING(trainer.full_name, CHAR_LENGTH(SUBSTRING_INDEX(trainer.full_name, \' \', 1)) + 1)) AS trainer_last_name, '
        . 'COUNT(DISTINCT CASE WHEN b.status <> \'CANCELLED\' THEN b.id END) AS booked_count, '
        . 'my_booking.status AS my_booking_status '
        . 'FROM fitness_classes c JOIN class_types ct ON ct.id = c.class_type_id JOIN users trainer ON trainer.id = c.trainer_id '
        . 'LEFT JOIN class_bookings b ON b.class_id = c.id '
        . 'LEFT JOIN class_bookings my_booking ON my_booking.class_id = c.id AND my_booking.member_id = :member_id '
        . 'WHERE (c.status = \'SCHEDULED\' AND c.starts_at > UTC_TIMESTAMP()) '
        . 'OR (my_booking.id IS NOT NULL) '
        . 'GROUP BY c.id, c.title, c.description, c.starts_at, c.ends_at, c.capacity, c.status, '
        . 'trainer.full_name, ct.name, my_booking.status '
        . 'ORDER BY c.starts_at ASC LIMIT 100'
    );
    $statement->execute(['member_id' => $member['id']]);
    $classes = $statement->fetchAll();
    foreach ($classes as &$class) {
        $class['id'] = (int) $class['id'];
        $class['trainer_id'] = (int) $class['trainer_id'];
        $class['capacity'] = (int) $class['capacity'];
        $class['booked_count'] = (int) $class['booked_count'];
        $class['seats_left'] = max(0, $class['capacity'] - $class['booked_count']);
    }
    unset($class);
    json_success(['classes' => $classes]);
} catch (Throwable $exception) {
    error_log('Class schedule lookup failed: ' . $exception->getMessage());
    json_error('Class schedules are temporarily unavailable.', 500);
}
