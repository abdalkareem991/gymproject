<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('GET');
admin_user();

/** Parse a strict UTC calendar date for report filtering. */
function admin_report_date(mixed $value, string $field): DateTimeImmutable
{
    $date = parse_utc_date($value);
    if (!$date) {
        json_error('Choose a valid ' . $field . ' date.', 422);
    }
    return $date;
}

$type = $_GET['type'] ?? 'payments';
if (!in_array($type, ['payments', 'attendance'], true)) {
    json_error('Choose a supported report type.', 422);
}
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$from = admin_report_date($_GET['from'] ?? $today->modify('-29 days')->format('Y-m-d'), 'start');
$to = admin_report_date($_GET['to'] ?? $today->format('Y-m-d'), 'end');
if (!is_valid_utc_date_range($from, $to)) {
    json_error('The report date range must be valid and no longer than 366 days.', 422);
}
$endExclusive = $to->modify('+1 day');

try {
    if ($type === 'payments') {
        $sql = 'SELECT p.id, p.paid_at AS payment_date, '
            . 'SUBSTRING_INDEX(u.full_name, \' \', 1) AS first_name, '
            . 'TRIM(SUBSTRING(u.full_name, CHAR_LENGTH(SUBSTRING_INDEX(u.full_name, \' \', 1)) + 1)) AS last_name, u.email, '
            . 'p.amount, p.currency, p.method, p.status, p.transaction_reference, p.notes '
            . 'FROM payments p JOIN users u ON u.id = p.member_id '
            . 'WHERE p.paid_at >= :start AND p.paid_at < :end ORDER BY p.paid_at, p.id';
        $header = [
            'Payment ID', 'Payment Date UTC', 'Member First Name', 'Member Last Name',
            'Email', 'Amount', 'Currency', 'Method', 'Status', 'Reference', 'Notes',
        ];
    } else {
        $sql = 'SELECT a.id, a.check_in_at, a.check_out_at, a.entry_method, a.notes, '
            . 'SUBSTRING_INDEX(member.full_name, \' \', 1) AS member_first_name, '
            . 'TRIM(SUBSTRING(member.full_name, CHAR_LENGTH(SUBSTRING_INDEX(member.full_name, \' \', 1)) + 1)) AS member_last_name, '
            . 'member.email AS member_email, '
            . 'SUBSTRING_INDEX(staff.full_name, \' \', 1) AS staff_first_name, '
            . 'TRIM(SUBSTRING(staff.full_name, CHAR_LENGTH(SUBSTRING_INDEX(staff.full_name, \' \', 1)) + 1)) AS staff_last_name '
            . 'FROM attendance_logs a JOIN users member ON member.id = a.member_id '
            . 'LEFT JOIN users staff ON staff.id = a.checked_in_by '
            . 'WHERE a.check_in_at >= :start AND a.check_in_at < :end ORDER BY a.check_in_at, a.id';
        $header = [
            'Attendance ID', 'Check-in UTC', 'Check-out UTC', 'Member First Name',
            'Member Last Name', 'Member Email', 'Entry Method', 'Staff First Name',
            'Staff Last Name', 'Notes',
        ];
    }

    $statement = db()->prepare($sql);
    $statement->execute([
        'start' => $from->format('Y-m-d 00:00:00'),
        'end' => $endExclusive->format('Y-m-d 00:00:00'),
    ]);
} catch (Throwable $exception) {
    error_log('Administrator CSV export failed: ' . $exception->getMessage());
    json_error('The report could not be generated right now.', 500);
}

header('Content-Type: text/csv; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header(
    'Content-Disposition: attachment; filename="plus-ultra-' . $type . '-'
    . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv"'
);
header('Cache-Control: no-store, max-age=0');
$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $header, ',', '"', '');

while ($row = $statement->fetch(PDO::FETCH_NUM)) {
    $safeRow = array_map('safe_csv_text', $row);
    fputcsv($output, $safeRow, ',', '"', '');
}
fclose($output);
exit;

