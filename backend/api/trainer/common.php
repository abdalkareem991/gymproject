<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/common.php';

/** Require an active trainer or administrator account. */
function trainer_user(): array
{
    return auth_require_roles(['TRAINER', 'ADMIN'], 'This area is restricted to trainers and administrators.');
}

/** Check whether the current account owns a class or has the administrator role. */
function trainer_can_manage(array $user, array $class): bool
{
    return $user['role'] === 'ADMIN' || (int) $class['trainer_id'] === (int) $user['id'];
}

/** Return a trainer account after validating its active role. */
function trainer_find(PDO $pdo, int $trainerId, bool $lock = false): array|false
{
    $query = $pdo->prepare(
        'SELECT id, full_name FROM users WHERE id = :id AND role = \'TRAINER\' AND status = \'ACTIVE\' LIMIT 1'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $query->execute(['id' => $trainerId]);
    return $query->fetch();
}
