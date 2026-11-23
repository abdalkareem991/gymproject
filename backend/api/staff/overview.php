<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('GET');
staff_user();

try {
    $attendance = db()->query(
        'SELECT a.id, a.check_in_at, a.check_out_at, '
        . 'SUBSTRING_INDEX(u.full_name, \' \', 1) AS first_name, '
        . 'TRIM(SUBSTRING(u.full_name, CHAR_LENGTH(SUBSTRING_INDEX(u.full_name, \' \', 1)) + 1)) AS last_name, u.email, '
        . 'm.status AS membership_status, p.title AS plan_name '
        . 'FROM attendance_logs a JOIN users u ON u.id = a.member_id '
        . 'LEFT JOIN memberships m ON m.id = a.membership_id LEFT JOIN plans p ON p.id = m.plan_id '
        . 'WHERE a.check_in_at >= UTC_DATE() ORDER BY a.check_in_at DESC LIMIT 25'
    )->fetchAll();
    $payments = db()->query(
        'SELECT p.id, p.amount, p.currency, p.method, p.paid_at, '
        . 'SUBSTRING_INDEX(u.full_name, \' \', 1) AS first_name, '
        . 'TRIM(SUBSTRING(u.full_name, CHAR_LENGTH(SUBSTRING_INDEX(u.full_name, \' \', 1)) + 1)) AS last_name, u.email '
        . 'FROM payments p JOIN users u ON u.id = p.member_id '
        . 'WHERE p.method IN (\'CASH\', \'BANK_TRANSFER\') AND p.status = \'COMPLETED\' AND p.paid_at >= UTC_DATE() '
        . 'ORDER BY p.paid_at DESC LIMIT 20'
    )->fetchAll();
    $checkins = db()->query('SELECT COUNT(*) FROM attendance_logs WHERE check_in_at >= UTC_DATE()')->fetchColumn();
    $inside = db()->query('SELECT COUNT(DISTINCT member_id) FROM attendance_logs WHERE check_out_at IS NULL')->fetchColumn();
    $activeMembers = db()->query(
        'SELECT COUNT(DISTINCT m.member_id) FROM memberships m JOIN users u ON u.id = m.member_id '
        . 'WHERE u.role = \'MEMBER\' AND u.status = \'ACTIVE\' AND m.status IN (\'ACTIVE\', \'EXPIRING\') '
        . 'AND m.start_date <= UTC_DATE() AND m.end_date >= UTC_DATE()'
    )->fetchColumn();
    $pendingMemberships = db()->query('SELECT COUNT(*) FROM memberships WHERE status = \'PENDING\'')->fetchColumn();
    $revenueQuery = db()->query(
        'SELECT currency, COALESCE(SUM(amount), 0) AS total FROM payments '
        . 'WHERE method IN (\'CASH\', \'BANK_TRANSFER\') AND status = \'COMPLETED\' AND paid_at >= UTC_DATE() '
        . 'GROUP BY currency ORDER BY currency'
    );
    $cashTotals = array_map(static fn (array $row): array => [
        'currency' => $row['currency'],
        'total' => number_format((float) $row['total'], 2, '.', ''),
    ], $revenueQuery->fetchAll());
    json_success([
        'checkins_today' => (int) $checkins,
        'members_inside' => (int) $inside,
        'active_members' => (int) $activeMembers,
        'pending_memberships' => (int) $pendingMemberships,
        'cash_today' => $cashTotals,
        'attendance' => $attendance,
        'payments' => $payments,
    ]);
} catch (Throwable $exception) {
    error_log('Staff overview failed: ' . $exception->getMessage());
    json_error('Reception data is temporarily unavailable.', 500);
}
