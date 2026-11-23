<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
$admin = admin_user();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    try {
        $plans = db()->query(
            'SELECT id, title AS name, description, duration_days, price, currency, monthly_class_limit, term_class_limit '
            . 'FROM plans WHERE is_active = 1 ORDER BY price, title'
        )->fetchAll();
        foreach ($plans as &$plan) {
            $plan['id'] = (int) $plan['id'];
            $plan['duration_days'] = (int) $plan['duration_days'];
        }
        unset($plan);
        json_success(['plans' => $plans]);
    } catch (Throwable $exception) {
        error_log('Plan catalog lookup failed: ' . $exception->getMessage());
        json_error('The plan catalog is temporarily unavailable.', 500);
    }
}

if (!in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    json_error('Method not allowed.', 405);
}

auth_require_csrf();
$input = auth_json_input();
$planId = positive_id($input['plan_id'] ?? null);

if ($method === 'DELETE') {
    if ($planId === null) json_error('Choose a valid membership plan.', 422);
    try {
        $deactivate = db()->prepare('UPDATE plans SET is_active = 0 WHERE id = :id AND is_active = 1');
        $deactivate->execute(['id' => $planId]);
        if ($deactivate->rowCount() === 0) json_error('Active membership plan not found.', 404);
        json_success(['plan_id' => $planId], 'Membership plan archived.');
    } catch (Throwable $exception) {
        error_log('Plan archival failed: ' . $exception->getMessage());
        json_error('The plan could not be archived right now.', 500);
    }
}

$name = trim((string) ($input['name'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$duration = filter_var($input['duration_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
$price = $input['price'] ?? null;
$monthlyLimit = ($input['monthly_class_limit'] ?? '') === '' ? null : filter_var($input['monthly_class_limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
$termLimit = ($input['term_class_limit'] ?? '') === '' ? null : filter_var($input['term_class_limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
$currency = strtoupper(trim((string) ($input['currency'] ?? 'JOD')));

if ($name === '' || mb_strlen($name) > 120) {
    json_error('Enter a plan name up to 120 characters.', 422);
}
if ($duration === false) {
    json_error('Plan duration is outside the supported range.', 422);
}
if ($monthlyLimit === false || $termLimit === false) {
    json_error('Class limits must be positive whole numbers or left blank for unlimited access.', 422);
}
if (!is_valid_nonnegative_amount($price)) {
    json_error('Enter a valid plan price with up to two decimal places.', 422);
}
if (!preg_match('/^[A-Z]{3}$/', $currency)) {
    json_error('Currency must be a three-letter code such as JOD.', 422);
}
if (mb_strlen($description) > 10000) {
    json_error('The plan description must be 10,000 characters or fewer.', 422);
}

try {
    $values = [
        'name' => $name,
        'description' => $description === '' ? null : $description,
        'duration_days' => $duration,
        'price' => number_format((float) $price, 2, '.', ''),
        'currency' => $currency,
        'monthly_class_limit' => $monthlyLimit,
        'term_class_limit' => $termLimit,
    ];
    if ($method === 'PATCH') {
        if ($planId === null) json_error('Choose a valid membership plan.', 422);
        $update = db()->prepare(
            'UPDATE plans SET title = :name, description = :description, duration_days = :duration_days, '
            . 'price = :price, currency = :currency, monthly_class_limit = :monthly_class_limit, '
            . 'term_class_limit = :term_class_limit WHERE id = :id AND is_active = 1'
        );
        $update->execute([...$values, 'id' => $planId]);
        if ($update->rowCount() === 0) {
            $exists = db()->prepare('SELECT id FROM plans WHERE id = :id AND is_active = 1');
            $exists->execute(['id' => $planId]);
            if (!$exists->fetch()) json_error('Active membership plan not found.', 404);
        }
        json_success(['plan_id' => $planId], 'Membership plan updated.');
    } else {
        $insert = db()->prepare(
            'INSERT INTO plans (title, description, duration_days, price, currency, monthly_class_limit, term_class_limit) '
            . 'VALUES (:name, :description, :duration_days, :price, :currency, :monthly_class_limit, :term_class_limit)'
        );
        $insert->execute($values);
        json_success(['plan_id' => (int) db()->lastInsertId()], 'Membership plan created.', 201);
    }
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        json_error('A plan with this name already exists.', 409);
    }
    error_log('Plan creation failed: ' . $exception->getMessage());
    json_error('The plan could not be created right now.', 500);
}
