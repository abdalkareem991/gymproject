<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('GET');
auth_start_session();

$sessionUser = $_SESSION['user'] ?? null;
if (is_array($sessionUser) && isset($sessionUser['id'])) {
    try {
        $query = db()->prepare(
            'SELECT id, full_name, email, role, status FROM users WHERE id = :id LIMIT 1'
        );
        $query->execute(['id' => $sessionUser['id']]);
        $currentUser = $query->fetch();
    } catch (Throwable $exception) {
        error_log('Session account refresh failed: ' . $exception->getMessage());
        json_error('Your session is temporarily unavailable.', 500);
    }

    if (!$currentUser || $currentUser['status'] !== 'ACTIVE') {
        session_regenerate_id(true);
        $_SESSION = [];
        unset($sessionUser);
    } else {
        if (($sessionUser['role'] ?? null) !== $currentUser['role']) {
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
        }
        $_SESSION['user'] = auth_public_user($currentUser);
        $sessionUser = $_SESSION['user'];
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

json_success([
    'csrf_token' => $_SESSION['csrf_token'],
    'user' => $_SESSION['user'] ?? null,
]);
