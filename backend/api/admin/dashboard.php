<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('GET');
admin_user();

try {
    $pdo = db();
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $tomorrow = $today->modify('+1 day');
    $monthStart = $today->modify('first day of this month')->modify('-5 months');
    $nextMonth = $today->modify('first day of next month');
    $attendanceStart = $today->modify('-13 days');
    $weekStart = $today->modify('monday this week');
    $weekEnd = $weekStart->modify('+7 days');

    $totalMembers = (int) $pdo->query(
        'SELECT COUNT(*) FROM users WHERE role = \'MEMBER\' AND status = \'ACTIVE\''
    )->fetchColumn();
    $activeMemberships = (int) $pdo->query(
        'SELECT COUNT(DISTINCT m.member_id) FROM memberships m JOIN users u ON u.id = m.member_id '
        . 'WHERE u.role = \'MEMBER\' AND u.status = \'ACTIVE\' AND m.status IN (\'ACTIVE\', \'EXPIRING\') '
        . 'AND m.start_date <= UTC_DATE() AND m.end_date >= UTC_DATE()'
    )->fetchColumn();
    $pendingMembershipRequests = (int) $pdo->query(
        'SELECT COUNT(*) FROM memberships WHERE status = \'PENDING\''
    )->fetchColumn();
    $activeClassesTodayQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM fitness_classes WHERE status = \'SCHEDULED\' '
        . 'AND starts_at >= :start AND starts_at < :end'
    );
    $activeClassesTodayQuery->execute([
        'start' => $today->format('Y-m-d H:i:s'),
        'end' => $tomorrow->format('Y-m-d H:i:s'),
    ]);
    $totalRevenueJod = (float) $pdo->query(
        'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'COMPLETED\' AND currency = \'JOD\''
    )->fetchColumn();
    $classesQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM fitness_classes WHERE status = \'SCHEDULED\' '
        . 'AND starts_at >= :week_start AND starts_at < :week_end'
    );
    $classesQuery->execute([
        'week_start' => $weekStart->format('Y-m-d H:i:s'),
        'week_end' => $weekEnd->format('Y-m-d H:i:s'),
    ]);

    $paymentsTodayQuery = $pdo->prepare(
        'SELECT currency, SUM(amount) AS total FROM payments '
        . 'WHERE status = \'COMPLETED\' AND paid_at >= :start AND paid_at < :end '
        . 'GROUP BY currency ORDER BY currency'
    );
    $paymentsTodayQuery->execute([
        'start' => $today->format('Y-m-d H:i:s'),
        'end' => $tomorrow->format('Y-m-d H:i:s'),
    ]);
    $paymentsToday = array_map(
        static fn (array $row): array => [
            'currency' => $row['currency'],
            'total' => number_format((float) $row['total'], 2, '.', ''),
        ],
        $paymentsTodayQuery->fetchAll()
    );

    $months = [];
    $cursor = $monthStart;
    while ($cursor < $nextMonth) {
        $months[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }
    $revenueQuery = $pdo->prepare(
        'SELECT DATE_FORMAT(paid_at, \'%Y-%m\') AS month_key, currency, SUM(amount) AS total '
        . 'FROM payments WHERE status = \'COMPLETED\' AND paid_at >= :start AND paid_at < :end '
        . 'GROUP BY month_key, currency ORDER BY month_key, currency'
    );
    $revenueQuery->execute([
        'start' => $monthStart->format('Y-m-d H:i:s'),
        'end' => $nextMonth->format('Y-m-d H:i:s'),
    ]);
    $revenueRows = $revenueQuery->fetchAll();
    $currencies = array_values(array_unique(array_column($revenueRows, 'currency')));
    foreach ($paymentsToday as $cash) {
        if (!in_array($cash['currency'], $currencies, true)) {
            $currencies[] = $cash['currency'];
        }
    }
    sort($currencies);
    $revenueSeries = [];
    foreach ($currencies as $currency) {
        $revenueSeries[$currency] = array_fill_keys($months, 0.0);
    }
    foreach ($revenueRows as $row) {
        $revenueSeries[$row['currency']][$row['month_key']] = (float) $row['total'];
    }
    foreach ($revenueSeries as &$series) {
        $series = array_values($series);
    }
    unset($series);

    $growthQuery = $pdo->prepare(
        'SELECT DATE_FORMAT(created_at, \'%Y-%m\') AS month_key, COUNT(*) AS total '
        . 'FROM users WHERE role = \'MEMBER\' AND status = \'ACTIVE\' '
        . 'AND created_at >= :start AND created_at < :end GROUP BY month_key ORDER BY month_key'
    );
    $growthQuery->execute([
        'start' => $monthStart->format('Y-m-d H:i:s'),
        'end' => $nextMonth->format('Y-m-d H:i:s'),
    ]);
    $growthByMonth = array_fill_keys($months, 0);
    foreach ($growthQuery->fetchAll() as $row) {
        $growthByMonth[$row['month_key']] = (int) $row['total'];
    }

    $planQuery = $pdo->query(
        'SELECT p.title AS name, COUNT(DISTINCT m.member_id) AS total FROM memberships m '
        . 'JOIN plans p ON p.id = m.plan_id JOIN users u ON u.id = m.member_id '
        . 'WHERE u.role = \'MEMBER\' AND u.status = \'ACTIVE\' '
        . 'AND m.status IN (\'ACTIVE\', \'EXPIRING\') AND m.start_date <= UTC_DATE() AND m.end_date >= UTC_DATE() '
        . 'GROUP BY p.id, p.title ORDER BY total DESC, p.title'
    );

    $attendanceQuery = $pdo->prepare(
        'SELECT DATE(check_in_at) AS day_key, COUNT(*) AS total FROM attendance_logs '
        . 'WHERE check_in_at >= :start AND check_in_at < :end '
        . 'GROUP BY day_key ORDER BY day_key'
    );
    $attendanceQuery->execute([
        'start' => $attendanceStart->format('Y-m-d H:i:s'),
        'end' => $tomorrow->format('Y-m-d H:i:s'),
    ]);
    $days = [];
    $attendanceByDay = [];
    for ($day = $attendanceStart; $day < $tomorrow; $day = $day->modify('+1 day')) {
        $dayKey = $day->format('Y-m-d');
        $days[] = $dayKey;
        $attendanceByDay[$dayKey] = 0;
    }
    foreach ($attendanceQuery->fetchAll() as $row) {
        $attendanceByDay[$row['day_key']] = (int) $row['total'];
    }

    $recentPayments = $pdo->query(
        'SELECT p.amount, p.currency, p.method, p.status, p.paid_at, p.paid_at AS created_at, '
        . 'SUBSTRING_INDEX(u.full_name, \' \', 1) AS first_name, '
        . 'TRIM(SUBSTRING(u.full_name, CHAR_LENGTH(SUBSTRING_INDEX(u.full_name, \' \', 1)) + 1)) AS last_name, '
        . 'u.email FROM payments p JOIN users u ON u.id = p.member_id ORDER BY p.paid_at DESC LIMIT 8'
    )->fetchAll();
    $recentMembers = $pdo->query(
        'SELECT SUBSTRING_INDEX(full_name, \' \', 1) AS first_name, '
        . 'TRIM(SUBSTRING(full_name, CHAR_LENGTH(SUBSTRING_INDEX(full_name, \' \', 1)) + 1)) AS last_name, '
        . 'email, created_at FROM users WHERE role = \'MEMBER\' ORDER BY created_at DESC LIMIT 6'
    )->fetchAll();

    json_success([
        'metrics' => [
            'total_members' => $totalMembers,
            'active_memberships' => $activeMemberships,
            'pending_membership_requests' => $pendingMembershipRequests,
            'active_classes_today' => (int) $activeClassesTodayQuery->fetchColumn(),
            'total_revenue_jod' => number_format($totalRevenueJod, 2, '.', ''),
            'classes_this_week' => (int) $classesQuery->fetchColumn(),
            'payments_today' => $paymentsToday,
        ],
        'revenue' => [
            'months' => $months,
            'currencies' => $currencies,
            'series' => $revenueSeries,
        ],
        'member_growth' => [
            'months' => $months,
            'totals' => array_values($growthByMonth),
        ],
        'membership_plans' => $planQuery->fetchAll(),
        'attendance' => [
            'days' => $days,
            'totals' => array_values($attendanceByDay),
        ],
        'recent_payments' => $recentPayments,
        'recent_members' => $recentMembers,
    ]);
} catch (Throwable $exception) {
    error_log('Administrator dashboard query failed: ' . $exception->getMessage());
    json_error('Dashboard data is temporarily unavailable.', 500);
}
