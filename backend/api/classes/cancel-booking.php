<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('POST');
$member = classes_member();
auth_require_csrf();
$input = auth_json_input();
$classId = classes_id($input['class_id'] ?? null);

try {
    $pdo = db();
    $pdo->beginTransaction();
    $classQuery = $pdo->prepare(
        'SELECT id, status, starts_at FROM fitness_classes WHERE id = :id FOR UPDATE'
    );
    $classQuery->execute(['id' => $classId]);
    $class = $classQuery->fetch();
    if (!$class || $class['status'] !== 'SCHEDULED' || strtotime($class['starts_at'] . ' UTC') <= time()) {
        $pdo->rollBack();
        json_error('This class can no longer be cancelled.', 409);
    }

    $booking = $pdo->prepare(
        'UPDATE class_bookings SET status = \'CANCELLED\', cancelled_at = UTC_TIMESTAMP() '
        . 'WHERE class_id = :class_id AND member_id = :member_id AND status = \'BOOKED\''
    );
    $booking->execute(['class_id' => $classId, 'member_id' => $member['id']]);
    if ($booking->rowCount() !== 1) {
        $pdo->rollBack();
        json_error('You do not have an active booking for this class.', 409);
    }

    $pdo->commit();
    json_success(['class_id' => $classId, 'status' => 'CANCELLED'], 'Your booking has been cancelled.');
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Class booking cancellation failed: ' . $exception->getMessage());
    json_error('We could not cancel your booking right now.', 500);
}
