<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('POST');
auth_require_csrf();
$input = auth_json_input();
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = $input['password'] ?? null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '' || strlen($password) > 1024) {
    json_error('Enter a valid email and password.', 422);
}

try {
    $statement = db()->prepare(
        'SELECT id, full_name, email, password_hash, role, status FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();
    // The provided MariaDB dump seeds its administrator with a temporary plain-text password.
    // Accept that legacy value once, then immediately replace it with a password_hash value.
    $legacyPassword = $user && !str_starts_with((string) $user['password_hash'], '$')
        && hash_equals((string) $user['password_hash'], $password);
    if (!$user || (!password_verify($password, $user['password_hash']) && !$legacyPassword) || $user['status'] !== 'ACTIVE') {
        json_error('Invalid email or password.', 401);
    }

    if ($legacyPassword || password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $updateHash = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $updateHash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
    }
    db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $user['id']]);
    session_regenerate_id(true);
    $_SESSION['user'] = auth_public_user($user);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    json_success(['user' => $_SESSION['user'], 'csrf_token' => $_SESSION['csrf_token']], 'Welcome back.');
} catch (PDOException $exception) {
    error_log('Login failed: ' . $exception->getMessage());
    json_error('Sign in is temporarily unavailable.', 500);
}
