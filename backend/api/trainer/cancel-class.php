<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('POST');
$user = trainer_user();
auth_require_csrf();
$input = auth_json_input();
$classId = classes_id($input['class_id'] ?? null);

try {
    $pdo = db();
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT id, trainer_id, starts_at, status FROM fitness_classes WHERE id = :id FOR UPDATE');
    $query->execute(['id' => $classId]);
    $class = $query->fetch();
    if (!$class || !trainer_can_manage($user, $class)) {
        $pdo->rollBack();
        json_error('Class not found or access denied.', 404);
    }
    if ($class['status'] !== 'SCHEDULED' || strtotime($class['starts_at'] . ' UTC') <= time()) {
        $pdo->rollBack();
        json_error('Only future scheduled classes can be cancelled.', 409);
    }

    $update = $pdo->prepare('UPDATE fitness_classes SET status = \'CANCELLED\' WHERE id = :id');
    $update->execute(['id' => $classId]);
    $pdo->commit();
    json_success(['class_id' => $classId, 'status' => 'CANCELLED'], 'Class cancelled. Members will see the update on their schedule.');
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Class cancellation failed: ' . $exception->getMessage());
    json_error('We could not cancel this class right now.', 500);
}
