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

    $memberQuery = $pdo->prepare(
        'SELECT id FROM users WHERE id = :user_id AND role = \'MEMBER\' AND status = \'ACTIVE\' FOR UPDATE'
    );
    $memberQuery->execute(['user_id' => $member['id']]);
    if (!$memberQuery->fetch()) {
        $pdo->rollBack();
        json_error('Your member account is no longer active.', 403);
    }

    $classQuery = $pdo->prepare(
        'SELECT id, capacity, status, starts_at, ends_at FROM fitness_classes WHERE id = :id FOR UPDATE'
    );
    $classQuery->execute(['id' => $classId]);
    $class = $classQuery->fetch();
    if (!$class || $class['status'] !== 'SCHEDULED' || strtotime($class['starts_at'] . ' UTC') <= time()) {
        $pdo->rollBack();
        json_error('This class is not available for booking.', 409);
    }

    $membership = classes_active_membership($pdo, (int) $member['id'], true);
    if (!$membership) {
        $pdo->rollBack();
        json_error('An active membership is required to book a class.', 403);
    }
    $classDate = substr($class['starts_at'], 0, 10);
    if ($classDate < $membership['start_date'] || $classDate > $membership['end_date']) {
        $pdo->rollBack();
        json_error('The selected class falls outside your membership dates.', 409);
    }

    $scheduleConflict = $pdo->prepare(
        'SELECT c.id FROM class_bookings b JOIN fitness_classes c ON c.id = b.class_id '
        . 'WHERE b.member_id = :member_id AND b.status = \'BOOKED\' AND c.status = \'SCHEDULED\' '
        . 'AND c.id <> :class_id AND c.starts_at < :requested_end AND c.ends_at > :requested_start LIMIT 1'
    );
    $scheduleConflict->execute([
        'member_id' => $member['id'],
        'class_id' => $classId,
        'requested_start' => $class['starts_at'],
        'requested_end' => $class['ends_at'],
    ]);
    if ($scheduleConflict->fetch()) {
        $pdo->rollBack();
        json_error('You already have a booking that overlaps this class.', 409);
    }

    $bookingQuery = $pdo->prepare(
        'SELECT id, status FROM class_bookings WHERE class_id = :class_id AND member_id = :member_id LIMIT 1 FOR UPDATE'
    );
    $bookingQuery->execute(['class_id' => $classId, 'member_id' => $member['id']]);
    $booking = $bookingQuery->fetch();
    if ($booking && $booking['status'] !== 'CANCELLED') {
        $pdo->rollBack();
        json_error('You already have a booking for this class.', 409);
    }

    if ($membership['monthly_class_limit'] !== null) {
        $classStart = new DateTimeImmutable($class['starts_at'], new DateTimeZone('UTC'));
        $monthStart = $classStart->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $monthStart->modify('+1 month');
        $membershipStart = new DateTimeImmutable($membership['start_date'] . ' 00:00:00', new DateTimeZone('UTC'));
        $membershipEndExclusive = (new DateTimeImmutable($membership['end_date'] . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day');
        $windowStart = $monthStart > $membershipStart ? $monthStart : $membershipStart;
        $windowEnd = $nextMonth < $membershipEndExclusive ? $nextMonth : $membershipEndExclusive;
        $monthlyUsage = $pdo->prepare(
            'SELECT COUNT(*) FROM class_bookings b JOIN fitness_classes c ON c.id = b.class_id '
            . 'WHERE b.member_id = :member_id AND b.status = \'BOOKED\' '
            . 'AND c.starts_at >= :window_start AND c.starts_at < :window_end'
        );
        $monthlyUsage->execute([
            'member_id' => $member['id'],
            'window_start' => $windowStart->format('Y-m-d H:i:s'),
            'window_end' => $windowEnd->format('Y-m-d H:i:s'),
        ]);
        if ((int) $monthlyUsage->fetchColumn() >= (int) $membership['monthly_class_limit']) {
            $pdo->rollBack();
            json_error('You have reached the monthly group class limit for this membership.', 409);
        }
    }

    if ($membership['term_class_limit'] !== null) {
        $termUsage = $pdo->prepare(
            'SELECT COUNT(*) FROM class_bookings b JOIN fitness_classes c ON c.id = b.class_id '
            . 'WHERE b.member_id = :member_id AND b.status = \'BOOKED\' '
            . 'AND c.starts_at >= :membership_start AND c.starts_at < :membership_end'
        );
        $termUsage->execute([
            'member_id' => $member['id'],
            'membership_start' => $membership['start_date'] . ' 00:00:00',
            'membership_end' => (new DateTimeImmutable($membership['end_date'] . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s'),
        ]);
        if ((int) $termUsage->fetchColumn() >= (int) $membership['term_class_limit']) {
            $pdo->rollBack();
            json_error('You have reached the total group class limit for this membership.', 409);
        }
    }

    $countQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM class_bookings WHERE class_id = :class_id AND status <> \'CANCELLED\''
    );
    $countQuery->execute(['class_id' => $classId]);
    if ((int) $countQuery->fetchColumn() >= (int) $class['capacity']) {
        $pdo->rollBack();
        json_error('This class is full.', 409);
    }

    $save = $pdo->prepare(
        'INSERT INTO class_bookings (class_id, member_id, status, booked_at, cancelled_at) '
        . 'VALUES (:class_id, :member_id, \'BOOKED\', UTC_TIMESTAMP(), NULL) '
        . 'ON DUPLICATE KEY UPDATE status = \'BOOKED\', booked_at = UTC_TIMESTAMP(), cancelled_at = NULL'
    );
    $save->execute(['class_id' => $classId, 'member_id' => $member['id']]);
    $pdo->commit();
    json_success(['class_id' => $classId, 'status' => 'BOOKED'], 'Your class booking is confirmed.', 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Class booking failed: ' . $exception->getMessage());
    json_error('We could not book this class right now.', 500);
}
