<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/validation.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Apache serves these physical PHP routes directly. Answer preflight requests
// before route-specific method/session/CSRF checks; same-origin clients do not
// need permissive cross-origin credential headers.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

/** Configure a same-origin session cookie before starting the session. */
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('smart_gym_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    header('Vary: Cookie');
}

/** Parse a JSON request body into an associative array. */
function auth_json_input(): array
{
    $raw = file_get_contents('php://input');
    try {
        $data = json_decode($raw === false ? '' : $raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_error('Request body must contain valid JSON.', 400);
    }

    if (!is_array($data)) {
        json_error('Request body must be a JSON object.', 400);
    }

    return $data;
}

/** Require the synchronizer token issued by session.php for state changes. */
function auth_require_csrf(): void
{
    auth_start_session();
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!auth_csrf_matches($expected, $provided)) {
        json_error('Your session token has expired. Refresh the page and try again.', 403);
    }
}

/** Return only fields safe for the browser. */
function auth_public_user(array $user): array
{
    $fullName = trim((string) ($user['full_name'] ?? ''));
    $parts = preg_split('/\s+/', $fullName, 2) ?: [];
    return [
        'id' => (int) $user['id'],
        'first_name' => $parts[0] ?? '',
        'last_name' => $parts[1] ?? '',
        'full_name' => $fullName,
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

/** Check whether a role is one of the roles permitted for an endpoint. */
function auth_role_allowed(string $role, array $allowedRoles): bool
{
    return in_array($role, $allowedRoles, true);
}

/** Compare a CSRF token without accepting missing, empty, or non-string values. */
function auth_csrf_matches(mixed $expected, mixed $provided): bool
{
    return is_string($expected)
        && is_string($provided)
        && $expected !== ''
        && hash_equals($expected, $provided);
}

/** Load the current account and enforce its live role before a protected action. */
function auth_require_roles(array $allowedRoles, string $message): array
{
    auth_start_session();
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || !isset($sessionUser['id'])) {
        json_error('Please sign in to continue.', 401);
    }

    try {
        $query = db()->prepare(
            'SELECT id, full_name, email, role, status FROM users WHERE id = :id LIMIT 1'
        );
        $query->execute(['id' => $sessionUser['id']]);
        $user = $query->fetch();
    } catch (Throwable $exception) {
        error_log('Account authorization lookup failed: ' . $exception->getMessage());
        json_error('Access is temporarily unavailable.', 500);
    }

    if (!$user || $user['status'] !== 'ACTIVE') {
        $_SESSION = [];
        session_destroy();
        json_error('Your account is no longer active. Sign in again.', 401);
    }
    if (!auth_role_allowed($user['role'], $allowedRoles)) {
        json_error($message, 403);
    }

    $_SESSION['user'] = auth_public_user($user);
    return $_SESSION['user'];
}

/** Enforce the expected method for an endpoint. */
function auth_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        json_error('Method not allowed.', 405);
    }
}
