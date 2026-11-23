<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('POST');
$staff = staff_user();
auth_require_csrf();
$input = auth_json_input();
$userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$notes = trim((string) ($input['notes'] ?? ''));
if (!$userId) {
    json_error('Select a valid member.', 422);
}
if (mb_strlen($notes) > 500) {
    json_error('Notes must be 500 characters or fewer.', 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $memberQuery = $pdo->prepare(
        'SELECT id FROM users WHERE id = :id AND role = \'MEMBER\' AND status = \'ACTIVE\' FOR UPDATE'
    );
    $memberQuery->execute(['id' => $userId]);
    if (!$memberQuery->fetch()) {
        $pdo->rollBack();
        json_error('This active member account could not be found.', 404);
    }
    $membershipQuery = $pdo->prepare(
        'SELECT id FROM memberships WHERE member_id = :member_id AND status IN (\'ACTIVE\', \'EXPIRING\') '
        . 'AND start_date <= UTC_DATE() AND end_date >= UTC_DATE() '
        . 'ORDER BY end_date DESC LIMIT 1 FOR UPDATE'
    );
    $membershipQuery->execute(['member_id' => $userId]);
    $membership = $membershipQuery->fetch();
    if (!$membership) {
        $pdo->rollBack();
        json_error('The member has no active membership today.', 409);
    }
    $openQuery = $pdo->prepare(
        'SELECT id FROM attendance_logs WHERE member_id = :member_id AND check_out_at IS NULL '
        . 'ORDER BY check_in_at DESC LIMIT 1 FOR UPDATE'
    );
    $openQuery->execute(['member_id' => $userId]);
    if ($openQuery->fetch()) {
        $pdo->rollBack();
        json_error('This member already has an open check-in today.', 409);
    }
    $insert = $pdo->prepare(
        'INSERT INTO attendance_logs (member_id, membership_id, checked_in_by, entry_method, notes) '
        . 'VALUES (:member_id, :membership_id, :staff_id, \'STAFF_MANUAL\', :notes)'
    );
    $insert->execute([
        'member_id' => $userId,
        'membership_id' => $membership['id'],
        'staff_id' => $staff['id'],
        'notes' => $notes === '' ? null : $notes,
    ]);
    $attendanceId = (int) $pdo->lastInsertId();
    $pdo->commit();
    json_success(['attendance_id' => $attendanceId], 'Member checked in successfully.', 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Staff check-in failed: ' . $exception->getMessage());
    json_error('Check-in could not be recorded right now.', 500);
}
