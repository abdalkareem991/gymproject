<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/db.php';

/** Fail the integration run with a readable assertion message. */
function integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Send one JSON request through Apache while retaining this test's session cookie. */
function integration_request(string $url, string $cookieFile, string $method = 'GET', ?array $body = null, ?string $csrf = null): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }

    $raw = curl_exec($handle);
    if ($raw === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    $decoded = json_decode($raw, true);
    integration_assert(is_array($decoded), 'Endpoint returned invalid JSON: ' . substr($raw, 0, 300));
    return ['status' => $status, 'body' => $decoded];
}

function integration_expect_status(array $response, int $expected, string $case): void
{
    integration_assert(
        $response['status'] === $expected,
        $case . ': expected HTTP ' . $expected . ', received ' . $response['status']
        . ' with body ' . json_encode($response['body'], JSON_UNESCAPED_SLASHES)
    );
}

$baseUrl = rtrim(getenv('SMART_GYM_BASE_URL') ?: 'http://localhost/gymproject', '/');
$email = 'integration-' . bin2hex(random_bytes(8)) . '@example.test';
$password = 'Integration-Test-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'gym-auth-');
$pdo = null;
$passed = 0;

try {
    integration_assert($cookieFile !== false, 'Could not create a temporary cookie jar.');
    $pdo = db();

    $session = integration_request($baseUrl . '/api/auth/session.php', $cookieFile);
    integration_expect_status($session, 200, 'Initial session');
    $csrf = $session['body']['data']['csrf_token'] ?? null;
    integration_assert(is_string($csrf) && strlen($csrf) >= 32, 'Initial CSRF token was missing or too short.');
    integration_assert(array_key_exists('user', $session['body']['data']) && $session['body']['data']['user'] === null, 'New session must not be authenticated.');
    echo "PASS: initial session and CSRF token\n";
    $passed++;

    $noCsrf = integration_request($baseUrl . '/api/auth/register.php', $cookieFile, 'POST', [
        'first_name' => 'Integration', 'last_name' => 'Tester', 'email' => $email, 'password' => $password,
    ]);
    integration_expect_status($noCsrf, 403, 'Registration without CSRF');
    echo "PASS: state-changing requests require CSRF\n";
    $passed++;

    $registration = integration_request($baseUrl . '/api/auth/register.php', $cookieFile, 'POST', [
        'first_name' => 'Integration',
        'last_name' => 'Tester',
        'email' => $email,
        'password' => $password,
        'role' => 'ADMIN',
    ], $csrf);
    integration_expect_status($registration, 201, 'Registration');
    $registeredUser = $registration['body']['data']['user'] ?? [];
    integration_assert(($registeredUser['role'] ?? null) === 'MEMBER', 'Public registration must assign the MEMBER role.');
    integration_assert(!array_key_exists('password_hash', $registeredUser), 'Registration response leaked password_hash.');
    $userId = (int) ($registeredUser['id'] ?? 0);
    integration_assert($userId > 0, 'Registration did not return a valid user identifier.');
    $stored = $pdo->prepare('SELECT password_hash, role FROM users WHERE id = :id');
    $stored->execute(['id' => $userId]);
    $account = $stored->fetch();
    integration_assert(is_array($account) && password_verify($password, $account['password_hash']), 'Password was not stored as a valid hash.');
    integration_assert($account['role'] === 'MEMBER', 'Database role was not restricted to MEMBER.');
    echo "PASS: registration, password hashing, role restriction, and response privacy\n";
    $passed++;

    $duplicate = integration_request($baseUrl . '/api/auth/register.php', $cookieFile, 'POST', [
        'first_name' => 'Duplicate', 'last_name' => 'Tester', 'email' => $email, 'password' => $password,
    ], $registration['body']['data']['csrf_token'] ?? null);
    integration_expect_status($duplicate, 409, 'Duplicate registration');
    echo "PASS: duplicate account rejected\n";
    $passed++;

    $authenticated = integration_request($baseUrl . '/api/auth/session.php', $cookieFile);
    integration_expect_status($authenticated, 200, 'Authenticated session');
    integration_assert(($authenticated['body']['data']['user']['id'] ?? null) === $userId, 'Registration did not establish the session.');
    echo "PASS: registration establishes authenticated session\n";
    $passed++;

    $logout = integration_request($baseUrl . '/api/auth/logout.php', $cookieFile, 'POST', [], $registration['body']['data']['csrf_token'] ?? null);
    integration_expect_status($logout, 200, 'Logout');
    $afterLogout = integration_request($baseUrl . '/api/auth/session.php', $cookieFile);
    integration_expect_status($afterLogout, 200, 'Session after logout');
    integration_assert(array_key_exists('user', $afterLogout['body']['data']) && $afterLogout['body']['data']['user'] === null, 'Logout did not clear the authenticated session.');
    echo "PASS: logout clears the session\n";
    $passed++;

    $loginCsrf = $afterLogout['body']['data']['csrf_token'] ?? null;
    $badLogin = integration_request($baseUrl . '/api/auth/login.php', $cookieFile, 'POST', [
        'email' => $email, 'password' => 'incorrect-password',
    ], $loginCsrf);
    integration_expect_status($badLogin, 401, 'Invalid credentials');
    echo "PASS: invalid credentials rejected\n";
    $passed++;

    $goodLogin = integration_request($baseUrl . '/api/auth/login.php', $cookieFile, 'POST', [
        'email' => strtoupper($email), 'password' => $password,
    ], $loginCsrf);
    integration_expect_status($goodLogin, 200, 'Login');
    integration_assert(($goodLogin['body']['data']['user']['role'] ?? null) === 'MEMBER', 'Login did not return the current user role.');
    integration_assert(!array_key_exists('password_hash', $goodLogin['body']['data']['user'] ?? []), 'Login response leaked password_hash.');
    echo "PASS: valid login and public role response\n";
    $passed++;

    $confirmed = integration_request($baseUrl . '/api/auth/session.php', $cookieFile);
    integration_expect_status($confirmed, 200, 'Session after login');
    integration_assert(($confirmed['body']['data']['user']['id'] ?? null) === $userId, 'Successful login did not establish an authenticated session.');
    echo "PASS: login establishes authenticated session\n";
    $passed++;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    try {
        if ($pdo instanceof PDO) {
            $cleanup = $pdo->prepare('DELETE FROM users WHERE email = :email');
            $cleanup->execute(['email' => $email]);
            $verifyCleanup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
            $verifyCleanup->execute(['email' => $email]);
            integration_assert((int) $verifyCleanup->fetchColumn() === 0, 'Temporary integration account was not removed.');
        }
    } catch (Throwable $cleanupException) {
        fwrite(STDERR, 'CLEANUP FAILED: ' . $cleanupException->getMessage() . "\n");
        $exitCode = 1;
    }
    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }
}

if (($exitCode ?? 0) !== 0) {
    exit(1);
}
echo sprintf("\n%d integration checks passed. Temporary account cleanup verified.\n", $passed);
