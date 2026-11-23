<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
$staff = staff_user();

function staff_member_rows(PDO $pdo, string $where, array $params, int $limit = 100): array
{
    $sql = 'SELECT u.id, u.member_code, u.full_name, '
        . 'SUBSTRING_INDEX(u.full_name, \' \', 1) AS first_name, '
        . 'TRIM(SUBSTRING(u.full_name, CHAR_LENGTH(SUBSTRING_INDEX(u.full_name, \' \', 1)) + 1)) AS last_name, '
        . 'u.email, u.phone, u.status AS account_status, m.id AS membership_id, '
        . 'CASE WHEN m.id IS NULL THEN NULL WHEN m.status = \'PENDING\' THEN \'PENDING\' '
        . 'WHEN m.status IN (\'ACTIVE\', \'EXPIRING\') AND m.start_date <= UTC_DATE() AND m.end_date >= UTC_DATE() '
        . 'THEN \'ACTIVE\' ELSE \'INACTIVE\' END AS membership_status, '
        . 'm.start_date AS starts_on, m.end_date AS ends_on, p.title AS plan_name, p.duration_days, p.price, p.currency, '
        . 'COALESCE((SELECT SUM(pay.amount) FROM payments pay WHERE pay.membership_id = m.id AND pay.status = \'COMPLETED\'), 0) AS paid_total, '
        . 'EXISTS(SELECT 1 FROM attendance_logs a WHERE a.member_id = u.id AND a.check_out_at IS NULL) AS is_checked_in '
        . 'FROM users u LEFT JOIN memberships m ON m.id = (SELECT m2.id FROM memberships m2 WHERE m2.member_id = u.id '
        . 'AND m2.status IN (\'ACTIVE\', \'PENDING\', \'EXPIRING\') '
        . 'ORDER BY (m2.status = \'PENDING\') DESC, (m2.status IN (\'ACTIVE\', \'EXPIRING\')) DESC, m2.end_date DESC LIMIT 1) '
        . 'LEFT JOIN plans p ON p.id = m.plan_id WHERE u.role = \'MEMBER\' AND ' . $where
        . ' ORDER BY u.full_name LIMIT ' . $limit;
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['membership_id'] = $row['membership_id'] === null ? null : (int) $row['membership_id'];
        $row['is_checked_in'] = (bool) $row['is_checked_in'];
        $row['duration_days'] = $row['duration_days'] === null ? null : (int) $row['duration_days'];
        $row['days_remaining'] = $row['ends_on'] === null ? null : max(0, (int) floor((strtotime($row['ends_on']) - strtotime(gmdate('Y-m-d'))) / 86400) + 1);
        $row['balance_due'] = $row['membership_id'] === null ? null : number_format(max(0, (float) $row['price'] - (float) $row['paid_total']), 2, '.', '');
        unset($row['paid_total']);
    }
    unset($row);
    return $rows;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    try {
        $pdo = db();
        if (isset($_GET['plans'])) {
            $plans = $pdo->query('SELECT id, title, duration_days, price, currency FROM plans WHERE is_active = 1 ORDER BY duration_days, title')->fetchAll();
            foreach ($plans as &$plan) $plan['id'] = (int) $plan['id'];
            unset($plan);
            json_success(['plans' => $plans]);
        }
        if (isset($_GET['directory'])) {
            json_success(['members' => staff_member_rows($pdo, 'u.status = \'ACTIVE\'', [])]);
        }
        $term = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($term) < 2) json_success(['members' => []]);
        if (mb_strlen($term) > 100) json_error('Search query is too long.', 422);
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $params = ['email' => $like, 'phone' => $like, 'name' => $like, 'code' => $like, 'id' => positive_id($term) ?? 0];
        json_success(['members' => staff_member_rows($pdo, '(u.status = \'ACTIVE\' OR u.status <> \'ACTIVE\') AND '
            . '(u.email LIKE :email OR u.phone LIKE :phone OR u.full_name LIKE :name OR u.member_code LIKE :code OR u.id = :id)', $params, 30)]);
    } catch (Throwable $exception) {
        error_log('Staff member lookup failed: ' . $exception->getMessage());
        json_error('Member data is temporarily unavailable.', 500);
    }
}

auth_require_method('POST');
auth_require_csrf();
$input = auth_json_input();
$name = trim((string) ($input['full_name'] ?? ''));
$email = mb_strtolower(trim((string) ($input['email'] ?? '')));
$phone = trim((string) ($input['phone'] ?? ''));
$password = (string) ($input['password'] ?? '');
$planId = positive_id($input['plan_id'] ?? null);
if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254
    || mb_strlen($phone) > 32 || mb_strlen($password) < 10 || mb_strlen($password) > 200 || $planId === null) {
    json_error('Enter a valid name, email, password and membership plan.', 422);
}

try {
    $pdo = db();
    $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = :email OR (:phone <> \'\' AND phone = :phone_check) LIMIT 1');
    $duplicate->execute(['email' => $email, 'phone' => $phone, 'phone_check' => $phone]);
    if ($duplicate->fetch()) json_error('An account already uses this email address or phone number.', 409);
    $planQuery = $pdo->prepare('SELECT id, duration_days FROM plans WHERE id = :id AND is_active = 1');
    $planQuery->execute(['id' => $planId]);
    $plan = $planQuery->fetch();
    if (!$plan) json_error('Choose an available membership plan.', 422);
    $pdo->beginTransaction();
    $insertUser = $pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES (:name, :email, :phone, :password, \'MEMBER\', \'ACTIVE\')');
    $insertUser->execute(['name' => $name, 'email' => $email, 'phone' => $phone === '' ? null : $phone, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $start = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $end = $start->modify('+' . ((int) $plan['duration_days'] - 1) . ' days');
    $insertMembership = $pdo->prepare('INSERT INTO memberships (member_id, plan_id, start_date, end_date, status, created_by) VALUES (:member_id, :plan_id, :start_date, :end_date, \'PENDING\', :staff_id)');
    $insertMembership->execute(['member_id' => $userId, 'plan_id' => $planId, 'start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'), 'staff_id' => $staff['id']]);
    $membershipId = (int) $pdo->lastInsertId();
    $pdo->commit();
    json_success(['user_id' => $userId, 'membership_id' => $membershipId], 'Member registered. Complete payment to activate the membership.', 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Staff walk-in registration failed: ' . $exception->getMessage());
    json_error('Walk-in registration could not be completed right now.', 500);
}
