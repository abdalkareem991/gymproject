<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
$admin = admin_user();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    try {
        $team = db()->query(
            'SELECT id, SUBSTRING_INDEX(full_name, \' \', 1) AS first_name, '
            . 'TRIM(SUBSTRING(full_name, CHAR_LENGTH(SUBSTRING_INDEX(full_name, \' \', 1)) + 1)) AS last_name, '
            . 'email, role, status, created_at FROM users '
            . 'WHERE role IN (\'STAFF\', \'TRAINER\') ORDER BY role, full_name LIMIT 200'
        )->fetchAll();
        foreach ($team as &$person) {
            $person['id'] = (int) $person['id'];
        }
        unset($person);
        $users = db()->query(
            'SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status, u.created_at, '
            . 'm.id AS membership_id, m.status AS membership_status, m.start_date, m.end_date, '
            . 'p.title AS plan_name, '
            . '(SELECT pay.amount FROM payments pay WHERE pay.membership_id = m.id AND pay.status = \'COMPLETED\' '
            . 'ORDER BY pay.paid_at DESC, pay.id DESC LIMIT 1) AS price_paid, '
            . '(SELECT pay.currency FROM payments pay WHERE pay.membership_id = m.id AND pay.status = \'COMPLETED\' '
            . 'ORDER BY pay.paid_at DESC, pay.id DESC LIMIT 1) AS price_currency, '
            . 'CASE WHEN m.id IS NULL THEN NULL ELSE DATEDIFF(m.end_date, UTC_DATE()) END AS days_remaining '
            . 'FROM users u LEFT JOIN memberships m ON m.id = ('
            . 'SELECT candidate.id FROM memberships candidate WHERE candidate.member_id = u.id '
            . 'ORDER BY CASE WHEN candidate.status IN (\'ACTIVE\', \'EXPIRING\') '
            . 'AND candidate.start_date <= UTC_DATE() AND candidate.end_date >= UTC_DATE() THEN 0 ELSE 1 END, '
            . 'candidate.created_at DESC, candidate.id DESC LIMIT 1) '
            . 'LEFT JOIN plans p ON p.id = m.plan_id ORDER BY u.created_at DESC, u.id DESC'
        )->fetchAll();
        foreach ($users as &$person) {
            $person['id'] = (int) $person['id'];
            $person['membership_id'] = $person['membership_id'] === null ? null : (int) $person['membership_id'];
            $person['days_remaining'] = $person['days_remaining'] === null ? null : (int) $person['days_remaining'];
        }
        unset($person);
        json_success(['team' => $team, 'users' => $users]);
    } catch (Throwable $exception) {
        error_log('Team directory lookup failed: ' . $exception->getMessage());
        json_error('The team directory is temporarily unavailable.', 500);
    }
}

if ($method === 'PATCH') {
    auth_require_csrf();
    $input = auth_json_input();
    $userId = positive_id($input['user_id'] ?? null);
    $role = $input['role'] ?? null;
    $status = $input['status'] ?? null;
    if ($userId === null || ($role === null && $status === null)) {
        json_error('Choose a valid user and at least one account update.', 422);
    }
    if ($role !== null && !in_array($role, ['ADMIN', 'STAFF', 'TRAINER', 'MEMBER'], true)) {
        json_error('Choose a supported account role.', 422);
    }
    if ($status !== null && !in_array($status, ['ACTIVE', 'INACTIVE', 'SUSPENDED', 'DISABLED'], true)) {
        json_error('Choose a supported account status.', 422);
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $lookup = $pdo->prepare('SELECT id, role, status FROM users WHERE id = :id FOR UPDATE');
        $lookup->execute(['id' => $userId]);
        $target = $lookup->fetch();
        if (!$target) {
            $pdo->rollBack();
            json_error('User account not found.', 404);
        }
        $nextRole = $role ?? $target['role'];
        $nextStatus = $status ?? $target['status'];
        if ($userId === (int) $admin['id'] && ($nextRole !== 'ADMIN' || $nextStatus !== 'ACTIVE')) {
            $pdo->rollBack();
            json_error('You cannot deactivate or demote your own administrator account.', 409);
        }
        if ($target['role'] === 'ADMIN' && $target['status'] === 'ACTIVE' && ($nextRole !== 'ADMIN' || $nextStatus !== 'ACTIVE')) {
            $activeAdmins = $pdo->query("SELECT id FROM users WHERE role = 'ADMIN' AND status = 'ACTIVE' ORDER BY id FOR UPDATE")->fetchAll();
            $count = count($activeAdmins);
            if ($count <= 1) {
                $pdo->rollBack();
                json_error('At least one active administrator account must remain.', 409);
            }
        }
        $update = $pdo->prepare('UPDATE users SET role = :role, status = :status WHERE id = :id');
        $update->execute(['role' => $nextRole, 'status' => $nextStatus, 'id' => $userId]);
        $pdo->commit();
        json_success(['user_id' => $userId, 'role' => $nextRole, 'status' => $nextStatus], 'User account updated.');
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Administrator user update failed: ' . $exception->getMessage());
        json_error('The user account could not be updated right now.', 500);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST, PATCH');
    json_error('Method not allowed.', 405);
}

auth_require_csrf();
$input = auth_json_input();
$firstName = trim((string) ($input['first_name'] ?? ''));
$lastName = trim((string) ($input['last_name'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$phone = trim((string) ($input['phone'] ?? ''));
$password = $input['password'] ?? null;
$role = $input['role'] ?? null;

if ($firstName === '' || mb_strlen($firstName) > 100 || $lastName === '' || mb_strlen($lastName) > 100) {
    json_error('Enter first and last names up to 100 characters each.', 422);
}
if (mb_strlen($firstName . ' ' . $lastName) > 150) {
    json_error('The full name must be 150 characters or fewer.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    json_error('Enter a valid email address.', 422);
}
if ($phone !== '' && (mb_strlen($phone) > 32 || !preg_match('/^[+0-9().\-\s]+$/', $phone))) {
    json_error('Enter a valid phone number.', 422);
}
if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
    json_error('Use an initial password between 12 and 72 characters.', 422);
}
if (!is_string($role) || !in_array($role, ['STAFF', 'TRAINER'], true)) {
    json_error('Only staff and trainer accounts can be created here.', 422);
}

try {
    $insert = db()->prepare(
        'INSERT INTO users (full_name, email, phone, password_hash, role, status) '
        . 'VALUES (:full_name, :email, :phone, :password_hash, :role, \'ACTIVE\')'
    );
    $insert->execute([
        'full_name' => $firstName . ' ' . $lastName,
        'email' => $email,
        'phone' => $phone === '' ? null : $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);
    json_success([
        'user' => [
            'id' => (int) db()->lastInsertId(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'role' => $role,
            'status' => 'ACTIVE',
        ],
    ], ucfirst(strtolower($role)) . ' account created.', 201);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        json_error('An account with this email or phone number already exists.', 409);
    }
    error_log('Team account creation failed: ' . $exception->getMessage());
    json_error('The team account could not be created right now.', 500);
}
