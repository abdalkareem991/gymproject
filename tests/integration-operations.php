<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/db.php';

function operations_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function operations_request(string $baseUrl, string $path, string $cookieFile, string $method = 'GET', ?array $payload = null, ?string $csrf = null): array
{
    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    $handle = curl_init($baseUrl . '/' . ltrim($path, '/'));
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $body = json_decode($raw, true);
    operations_assert(is_array($body), 'Endpoint returned invalid JSON: ' . substr($raw, 0, 300));
    return ['status' => $status, 'body' => $body];
}

function operations_expect(array $response, int $status, string $name): array
{
    operations_assert(
        $response['status'] === $status,
        $name . ': expected HTTP ' . $status . ', received ' . $response['status']
        . ' with body ' . json_encode($response['body'], JSON_UNESCAPED_SLASHES)
    );
    return $response['body']['data'] ?? [];
}

function operations_login(string $baseUrl, string $email, string $password, string $cookieFile): array
{
    $initial = operations_expect(operations_request($baseUrl, 'api/auth/session.php', $cookieFile), 200, 'Initial session');
    $login = operations_expect(
        operations_request($baseUrl, 'api/auth/login.php', $cookieFile, 'POST', [
            'email' => $email,
            'password' => $password,
        ], $initial['csrf_token']),
        200,
        'Login ' . $email
    );
    return ['csrf' => $login['csrf_token'], 'user' => $login['user']];
}

$baseUrl = rtrim(getenv('SMART_GYM_BASE_URL') ?: 'http://localhost/gymproject', '/');
$suffix = bin2hex(random_bytes(7));
$password = 'Operation-Test-' . bin2hex(random_bytes(8));
$adminEmail = 'ops-admin-' . $suffix . '@example.test';
$memberEmail = 'ops-member-' . $suffix . '@example.test';
$trainerEmail = 'ops-trainer-' . $suffix . '@example.test';
$staffEmail = 'ops-staff-' . $suffix . '@example.test';
$planName = 'Integration Plan ' . $suffix;
$cookieFiles = [];
$ids = ['admin' => null, 'member' => null, 'trainer' => null, 'staff' => null, 'plan' => null, 'membership' => null, 'class' => null];
$pdo = null;
$passed = 0;
$exitCode = 0;

try {
    $pdo = db();
    foreach (['admin', 'member', 'staff'] as $roleKey) {
        $email = ${$roleKey . 'Email'};
        $role = strtoupper($roleKey);
        $insert = $pdo->prepare(
            'INSERT INTO users (first_name, last_name, email, password_hash, role, status) '
            . 'VALUES (:first_name, :last_name, :email, :password_hash, :role, \'ACTIVE\')'
        );
        $insert->execute([
            'first_name' => 'Test',
            'last_name' => ucfirst($roleKey),
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        $ids[$roleKey] = (int) $pdo->lastInsertId();
    }

    foreach (['admin', 'member', 'staff'] as $key) {
        $cookieFiles[$key] = tempnam(sys_get_temp_dir(), 'gym-ops-' . $key . '-');
        operations_assert(is_string($cookieFiles[$key]), 'Could not create a cookie jar for ' . $key . '.');
    }
    $admin = operations_login($baseUrl, $adminEmail, $password, $cookieFiles['admin']);
    operations_assert($admin['user']['role'] === 'ADMIN', 'Test administrator role was not returned.');
    echo "PASS: admin session and role\n";
    $passed++;

    $trainer = operations_expect(operations_request($baseUrl, 'api/admin/team.php', $cookieFiles['admin'], 'POST', [
        'first_name' => 'Test',
        'last_name' => 'Trainer',
        'email' => $trainerEmail,
        'phone' => '',
        'password' => $password,
        'role' => 'TRAINER',
    ], $admin['csrf']), 201, 'Create trainer account');
    $ids['trainer'] = (int) $trainer['user']['id'];
    operations_assert($trainer['user']['role'] === 'TRAINER' && !array_key_exists('password_hash', $trainer['user']), 'Team account response leaked credentials or assigned the wrong role.');
    $teamPassword = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $teamPassword->execute(['id' => $ids['trainer']]);
    operations_assert(password_verify($password, (string) $teamPassword->fetchColumn()), 'Team password was not securely hashed.');
    echo "PASS: administrator provisions a trainer with a hashed password\n";
    $passed++;

    $plan = operations_expect(operations_request($baseUrl, 'api/admin/plans.php', $cookieFiles['admin'], 'POST', [
        'name' => $planName,
        'description' => 'Temporary plan used by operational integration tests.',
        'duration_days' => 30,
        'price' => '20.00',
        'currency' => 'JOD',
    ], $admin['csrf']), 201, 'Create plan');
    $ids['plan'] = (int) $plan['plan_id'];
    operations_assert($ids['plan'] > 0, 'Plan creation did not return an identifier.');
    echo "PASS: administrator creates a membership plan\n";
    $passed++;

    $memberSearch = operations_expect(
        operations_request($baseUrl, 'api/admin/members.php?q=' . rawurlencode($memberEmail), $cookieFiles['admin']),
        200,
        'Search member'
    );
    operations_assert(count($memberSearch['members']) === 1 && $memberSearch['members'][0]['id'] === $ids['member'], 'Member lookup returned the wrong account.');
    echo "PASS: administrator can find a member\n";
    $passed++;

    $today = (string) $pdo->query('SELECT UTC_DATE()')->fetchColumn();
    $activation = operations_expect(operations_request($baseUrl, 'api/admin/memberships.php', $cookieFiles['admin'], 'POST', [
        'user_id' => $ids['member'],
        'plan_id' => $ids['plan'],
        'starts_on' => $today,
        'amount' => '20.00',
        'method' => 'CASH',
    ], $admin['csrf']), 201, 'Activate membership');
    $ids['membership'] = (int) $activation['membership_id'];
    $paymentQuery = $pdo->prepare('SELECT status, membership_id, amount FROM payments WHERE id = :id');
    $paymentQuery->execute(['id' => $activation['payment_id']]);
    $payment = $paymentQuery->fetch();
    operations_assert($payment && $payment['status'] === 'COMPLETED' && (int) $payment['membership_id'] === $ids['membership'], 'Membership payment was not atomically recorded.');
    echo "PASS: membership activation and payment are linked\n";
    $passed++;

    $classStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 hours');
    $classEnd = $classStart->modify('+1 hour');
    $createdClass = operations_expect(operations_request($baseUrl, 'api/trainer/classes.php', $cookieFiles['admin'], 'POST', [
        'trainer_id' => $ids['trainer'],
        'title' => 'Operations test class ' . $suffix,
        'description' => '',
        'starts_at' => $classStart->format('Y-m-d\TH:i:s\Z'),
        'ends_at' => $classEnd->format('Y-m-d\TH:i:s\Z'),
        'capacity' => 5,
    ], $admin['csrf']), 201, 'Create test class');
    $ids['class'] = (int) $createdClass['class_id'];
    echo "PASS: administrator can schedule a class for an active trainer\n";
    $passed++;

    $member = operations_login($baseUrl, $memberEmail, $password, $cookieFiles['member']);
    $staff = operations_login($baseUrl, $staffEmail, $password, $cookieFiles['staff']);
    operations_expect(operations_request($baseUrl, 'api/staff/checkin.php', $cookieFiles['staff'], 'POST', [
        'user_id' => $ids['member'],
        'notes' => 'Operational check-in test.',
    ], $staff['csrf']), 201, 'Staff check-in');
    $duplicateCheckIn = operations_request($baseUrl, 'api/staff/checkin.php', $cookieFiles['staff'], 'POST', [
        'user_id' => $ids['member'],
        'notes' => '',
    ], $staff['csrf']);
    operations_assert($duplicateCheckIn['status'] === 409, 'A second open check-in must be rejected.');
    operations_expect(operations_request($baseUrl, 'api/staff/checkout.php', $cookieFiles['staff'], 'POST', [
        'user_id' => $ids['member'],
    ], $staff['csrf']), 200, 'Staff check-out');
    $attendanceQuery = $pdo->prepare(
        'SELECT check_in_at, check_out_at FROM attendance_logs WHERE user_id = :user_id ORDER BY id DESC LIMIT 1'
    );
    $attendanceQuery->execute(['user_id' => $ids['member']]);
    $attendance = $attendanceQuery->fetch();
    operations_assert($attendance && $attendance['check_out_at'] !== null, 'Check-out timestamp was not saved.'); 
    echo "PASS: front desk check-in, duplicate protection, and check-out\n";
    $passed++;

    $memberDashboard = operations_expect(operations_request($baseUrl, 'api/member/dashboard.php', $cookieFiles['member']), 200, 'Member dashboard');
    operations_assert(($memberDashboard['membership']['status'] ?? null) === 'ACTIVE', 'The active membership status was not returned.');
    $booking = operations_request($baseUrl, 'api/classes/book.php', $cookieFiles['member'], 'POST', [
        'class_id' => $ids['class'],
    ], $member['csrf']);
    operations_assert($booking['status'] === 201, 'An active membership must be able to book a class.');
    echo "PASS: an active membership can book a class\n";
    $passed++;

    operations_expect(operations_request($baseUrl, 'api/staff/checkin.php', $cookieFiles['staff'], 'POST', [
        'user_id' => $ids['member'],
        'notes' => 'Active membership access test.',
    ], $staff['csrf']), 201, 'Staff check-in for active membership');
    operations_expect(operations_request($baseUrl, 'api/staff/checkout.php', $cookieFiles['staff'], 'POST', [
        'user_id' => $ids['member'],
    ], $staff['csrf']), 200, 'Staff check-out for active membership');
    echo "PASS: an active membership allows front desk check-in and check-out\n";
    $passed++;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($pdo instanceof PDO) {
        try {
            $pdo->beginTransaction();
            if ($ids['membership']) {
                $pdo->prepare('DELETE FROM payments WHERE membership_id = :id')->execute(['id' => $ids['membership']]);
                $pdo->prepare('DELETE FROM attendance_logs WHERE membership_id = :id')->execute(['id' => $ids['membership']]);
                $pdo->prepare('DELETE FROM memberships WHERE id = :id')->execute(['id' => $ids['membership']]);
            }
            if ($ids['class']) {
                $pdo->prepare('DELETE FROM class_bookings WHERE class_id = :id')->execute(['id' => $ids['class']]);
                $pdo->prepare('DELETE FROM fitness_classes WHERE id = :id')->execute(['id' => $ids['class']]);
            }
            if ($ids['plan']) {
                $pdo->prepare('DELETE FROM plans WHERE id = :id')->execute(['id' => $ids['plan']]);
            }
            foreach (['admin', 'member', 'trainer', 'staff'] as $key) {
                if ($ids[$key]) {
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $ids[$key]]);
                }
            }
            $pdo->commit();
        } catch (Throwable $cleanupException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fwrite(STDERR, 'CLEANUP FAILED: ' . $cleanupException->getMessage() . PHP_EOL);
            $exitCode = 1;
        }
    }
    foreach ($cookieFiles as $cookieFile) {
        if (is_string($cookieFile) && is_file($cookieFile)) {
            unlink($cookieFile);
        }
    }
}

if ($exitCode !== 0) {
    exit(1);
}
echo PHP_EOL . sprintf("%d operational integration checks passed; fixtures cleaned up.\n", $passed);
