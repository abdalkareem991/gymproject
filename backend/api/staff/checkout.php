<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('POST');
$staff = staff_user();
auth_require_csrf();
$input = auth_json_input();
$userId = positive_id($input['user_id'] ?? null);
if ($userId === null) {
    json_error('Select a valid member.', 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $member = $pdo->prepare(
        'SELECT id FROM users WHERE id = :id AND role = \'MEMBER\' AND status = \'ACTIVE\' FOR UPDATE'
    );
    $member->execute(['id' => $userId]);
    if (!$member->fetch()) {
        $pdo->rollBack();
        json_error('This active member account could not be found.', 404);
    }

    $openVisit = $pdo->prepare(
        'SELECT id FROM attendance_logs WHERE member_id = :member_id AND check_out_at IS NULL '
        . 'ORDER BY check_in_at DESC LIMIT 1 FOR UPDATE'
    );
    $openVisit->execute(['member_id' => $userId]);
    $visit = $openVisit->fetch();
    if (!$visit) {
        $pdo->rollBack();
        json_error('This member has no open visit to check out.', 409);
    }

    $update = $pdo->prepare(
        'UPDATE attendance_logs SET check_out_at = GREATEST(UTC_TIMESTAMP(), check_in_at) '
        . 'WHERE id = :id AND check_out_at IS NULL'
    );
    $update->execute(['id' => $visit['id']]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        json_error('This visit was already closed.', 409);
    }
    $pdo->commit();
    json_success(['attendance_id' => (int) $visit['id']], 'Member checked out successfully.');
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Staff check-out failed: ' . $exception->getMessage());
    json_error('Check-out could not be recorded right now.', 500);
}
