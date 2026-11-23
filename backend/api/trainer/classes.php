<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
$user = trainer_user();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    try {
        $where = $user['role'] === 'ADMIN' ? '' : 'WHERE c.trainer_id = :trainer_id';
        $query = db()->prepare(
            'SELECT c.id, c.trainer_id, c.title, c.description, ct.name AS class_type, c.starts_at, c.ends_at, '
            . 'c.capacity, c.status, SUBSTRING_INDEX(t.full_name, \' \', 1) AS trainer_first_name, '
            . 'TRIM(SUBSTRING(t.full_name, CHAR_LENGTH(SUBSTRING_INDEX(t.full_name, \' \', 1)) + 1)) AS trainer_last_name, '
            . 'COUNT(DISTINCT CASE WHEN b.status <> \'CANCELLED\' THEN b.id END) AS booked_count '
            . 'FROM fitness_classes c JOIN users t ON t.id = c.trainer_id '
            . 'JOIN class_types ct ON ct.id = c.class_type_id '
            . 'LEFT JOIN class_bookings b ON b.class_id = c.id ' . $where . ' '
            . 'GROUP BY c.id, c.trainer_id, c.title, c.description, ct.name, c.starts_at, c.ends_at, '
            . 'c.capacity, c.status, t.full_name ORDER BY c.starts_at DESC LIMIT 100'
        );
        if ($user['role'] === 'ADMIN') {
            $query->execute();
        } else {
            $query->execute(['trainer_id' => $user['id']]);
        }
        $classes = $query->fetchAll();
        foreach ($classes as &$class) {
            $class['id'] = (int) $class['id'];
            $class['trainer_id'] = (int) $class['trainer_id'];
            $class['capacity'] = (int) $class['capacity'];
            $class['booked_count'] = (int) $class['booked_count'];
        }
        unset($class);

        $trainers = [];
        if ($user['role'] === 'ADMIN') {
            $trainers = db()->query(
                'SELECT id, SUBSTRING_INDEX(full_name, \' \', 1) AS first_name, '
                . 'TRIM(SUBSTRING(full_name, CHAR_LENGTH(SUBSTRING_INDEX(full_name, \' \', 1)) + 1)) AS last_name '
                . 'FROM users WHERE role = \'TRAINER\' AND status = \'ACTIVE\' ORDER BY full_name'
            )->fetchAll();
            foreach ($trainers as &$trainer) {
                $trainer['id'] = (int) $trainer['id'];
            }
            unset($trainer);
        }
        json_success(['classes' => $classes, 'trainers' => $trainers]);
    } catch (Throwable $exception) {
        error_log('Trainer class schedule lookup failed: ' . $exception->getMessage());
        json_error('The trainer schedule is temporarily unavailable.', 500);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    json_error('Method not allowed.', 405);
}

auth_require_csrf();
$input = auth_json_input();
$title = trim((string) ($input['title'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$capacity = filter_var($input['capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
if ($title === '' || mb_strlen($title) > 150) {
    json_error('Enter a class title up to 150 characters.', 422);
}
if (mb_strlen($description) > 5000) {
    json_error('The description must be 5,000 characters or fewer.', 422);
}
if ($capacity === false) {
    json_error('Capacity must be between 1 and 500.', 422);
}
$startsAt = classes_parse_datetime($input['starts_at'] ?? null, 'starts_at');
$endsAt = classes_parse_datetime($input['ends_at'] ?? null, 'ends_at');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($startsAt <= $now) {
    json_error('Classes must be scheduled in the future.', 422);
}
if (!is_valid_class_time_range($startsAt, $endsAt, $now)) {
    json_error('Choose an end time after the start time; a class cannot exceed four hours.', 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $trainerId = (int) $user['id'];
    if ($user['role'] === 'ADMIN') {
        $trainerId = classes_id($input['trainer_id'] ?? null, 'trainer_id');
    }

    $trainer = trainer_find($pdo, $trainerId, true);
    if (!$trainer) {
        $pdo->rollBack();
        json_error('Choose an active trainer account.', 422);
    }

    $pdo->exec("INSERT IGNORE INTO class_types (name, description) VALUES ('General Training', 'General gym training session')");
    $classTypeId = (int) $pdo->query("SELECT id FROM class_types WHERE name = 'General Training' LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT IGNORE INTO rooms (name, capacity, description) VALUES ('Main Gym', 500, 'Main training floor')");
    $roomId = (int) $pdo->query("SELECT id FROM rooms WHERE name = 'Main Gym' LIMIT 1")->fetchColumn();
    $roomLock = $pdo->prepare('SELECT id FROM rooms WHERE id = :id FOR UPDATE');
    $roomLock->execute(['id' => $roomId]);

    $overlap = $pdo->prepare(
        'SELECT id FROM fitness_classes WHERE status = \'SCHEDULED\' '
        . 'AND starts_at < :ends_at AND ends_at > :starts_at '
        . 'AND (trainer_id = :trainer_id OR room_id = :room_id) LIMIT 1'
    );
    $overlap->execute([
        'trainer_id' => $trainerId,
        'room_id' => $roomId,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'ends_at' => $endsAt->format('Y-m-d H:i:s'),
    ]);
    if ($overlap->fetch()) {
        $pdo->rollBack();
        json_error('This trainer or room already has a class during that time.', 409);
    }

    $insert = $pdo->prepare(
        'INSERT INTO fitness_classes (class_type_id, trainer_id, room_id, title, description, starts_at, ends_at, capacity) '
        . 'VALUES (:class_type_id, :trainer_id, :room_id, :title, :description, :starts_at, :ends_at, :capacity)'
    );
    $insert->execute([
        'class_type_id' => $classTypeId,
        'trainer_id' => $trainerId,
        'room_id' => $roomId,
        'title' => $title,
        'description' => $description === '' ? null : $description,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'ends_at' => $endsAt->format('Y-m-d H:i:s'),
        'capacity' => $capacity,
    ]);
    $classId = (int) $pdo->lastInsertId();
    $pdo->commit();
    json_success(['class_id' => $classId], 'Class scheduled successfully.', 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Class scheduling failed: ' . $exception->getMessage());
    json_error('We could not schedule this class right now.', 500);
}
