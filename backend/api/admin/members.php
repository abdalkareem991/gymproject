<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
$user = admin_user();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    auth_require_method('GET');
    $term = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($term) < 2) json_success(['members' => []]);
    if (mb_strlen($term) > 100) json_error('Search query is too long.', 422);
    try {
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $query = db()->prepare(
            'SELECT id, SUBSTRING_INDEX(full_name, \' \', 1) AS first_name, '
            . 'TRIM(SUBSTRING(full_name, CHAR_LENGTH(SUBSTRING_INDEX(full_name, \' \', 1)) + 1)) AS last_name, '
            . 'email, phone, status, created_at FROM users '
            . 'WHERE role = \'MEMBER\' AND (email LIKE :email OR phone LIKE :phone OR full_name LIKE :full_name) '
            . 'ORDER BY full_name LIMIT 30'
        );
        $query->execute(['email' => $like, 'phone' => $like, 'full_name' => $like]);
        $members = $query->fetchAll();
        foreach ($members as &$member) $member['id'] = (int) $member['id'];
        unset($member);
        json_success(['members' => $members]);
    } catch (Throwable $exception) {
        error_log('Administrator member lookup failed: ' . $exception->getMessage());
        json_error('Member search is temporarily unavailable.', 500);
    }
}

if (!in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    json_error('Method not allowed.', 405);
}
auth_require_csrf();
$input = auth_json_input();

try {
    if ($method === 'POST') {
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $password = $input['password'] ?? null;
        if ($firstName === '' || mb_strlen($firstName) > 100 || $lastName === '' || mb_strlen($lastName) > 100) {
            json_error('Enter first and last names up to 100 characters each.', 422);
        }
        if (mb_strlen($firstName . ' ' . $lastName) > 150) json_error('The full name must be 150 characters or fewer.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) json_error('Enter a valid email address.', 422);
        if ($phone !== '' && (mb_strlen($phone) > 32 || !preg_match('/^[+0-9().\\-\\s]+$/', $phone))) json_error('Enter a valid phone number.', 422);
        if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72) json_error('Use a password between 10 and 72 characters.', 422);

        $insert = db()->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role, status) '
            . 'VALUES (:full_name, :email, :phone, :password_hash, \'MEMBER\', \'ACTIVE\')'
        );
        $insert->execute([
            'full_name' => $firstName . ' ' . $lastName,
            'email' => $email,
            'phone' => $phone === '' ? null : $phone,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        json_success(['member_id' => (int) db()->lastInsertId()], 'Member account created.', 201);
    }

    $memberId = positive_id($input['member_id'] ?? null);
    if ($memberId === null) json_error('Choose a valid member account.', 422);
    if ($method === 'PATCH') {
        $status = $input['status'] ?? '';
        if (!in_array($status, ['ACTIVE', 'INACTIVE', 'SUSPENDED', 'DISABLED'], true)) json_error('Choose a supported member status.', 422);
        $update = db()->prepare('UPDATE users SET status = :status WHERE id = :id AND role = \'MEMBER\'');
        $update->execute(['status' => $status, 'id' => $memberId]);
        if ($update->rowCount() === 0) {
            $exists = db()->prepare('SELECT status FROM users WHERE id = :id AND role = \'MEMBER\'');
            $exists->execute(['id' => $memberId]);
            $current = $exists->fetch();
            if (!$current) json_error('Member account not found.', 404);
            if ($current['status'] !== $status) json_error('Member status could not be updated.', 409);
        }
        json_success(['member_id' => $memberId, 'status' => $status], 'Member status updated.');
    }

    // Account records are referenced by financial and attendance history, so deletion is a safe deactivation.
    $deactivate = db()->prepare('UPDATE users SET status = \'INACTIVE\' WHERE id = :id AND role = \'MEMBER\'');
    $deactivate->execute(['id' => $memberId]);
    if ($deactivate->rowCount() === 0) {
        $exists = db()->prepare('SELECT id FROM users WHERE id = :id AND role = \'MEMBER\'');
        $exists->execute(['id' => $memberId]);
        if (!$exists->fetch()) json_error('Member account not found.', 404);
    }
    json_success(['member_id' => $memberId, 'status' => 'INACTIVE'], 'Member account deactivated.');
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') json_error('An account with this email or phone number already exists.', 409);
    error_log('Administrator member change failed: ' . $exception->getMessage());
    json_error('The member account could not be updated right now.', 500);
} catch (Throwable $exception) {
    error_log('Administrator member change failed: ' . $exception->getMessage());
    json_error('The member account could not be updated right now.', 500);
}
