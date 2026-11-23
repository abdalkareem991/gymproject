<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('POST');
$user = trainer_user();
auth_require_csrf();
$input = auth_json_input();
$bookingId = classes_id($input['booking_id'] ?? null, 'booking_id');
$status = $input['status'] ?? '';
if (!in_array($status, ['ATTENDED', 'NO_SHOW'], true)) {
    json_error('Attendance must be marked as attended or no-show.', 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $query = $pdo->prepare(
        'SELECT c.trainer_id, c.starts_at, c.status AS class_status, b.status AS booking_status '
        . 'FROM class_bookings b JOIN fitness_classes c ON c.id = b.class_id '
        . 'WHERE b.id = :booking_id FOR UPDATE'
    );
    $query->execute(['booking_id' => $bookingId]);
    $booking = $query->fetch();
    if (!$booking || !trainer_can_manage($user, $booking)) {
        $pdo->rollBack();
        json_error('Booking not found or access denied.', 404);
    }
    if ($booking['class_status'] === 'CANCELLED' || strtotime($booking['starts_at'] . ' UTC') > time()) {
        $pdo->rollBack();
        json_error('Attendance can be recorded after the class has started.', 409);
    }
    if ($booking['booking_status'] !== 'BOOKED') {
        $pdo->rollBack();
        json_error('Attendance has already been recorded for this booking.', 409);
    }

    $update = $pdo->prepare('UPDATE class_bookings SET status = :status WHERE id = :id AND status = \'BOOKED\'');
    $update->execute(['status' => $status, 'id' => $bookingId]);
    $pdo->commit();
    json_success(['booking_id' => $bookingId, 'status' => $status], 'Attendance saved.');
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Class attendance update failed: ' . $exception->getMessage());
    json_error('We could not save attendance right now.', 500);
}
