<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
auth_require_method('POST');
auth_require_csrf();
$input = auth_json_input();

$firstName = trim((string) ($input['first_name'] ?? ''));
$lastName = trim((string) ($input['last_name'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$phone = trim((string) ($input['phone'] ?? ''));
$password = $input['password'] ?? null;
$errors = [];

if ($firstName === '' || mb_strlen($firstName) > 100) {
    $errors['first_name'] = 'Enter a first name (up to 100 characters).';
}
if ($lastName === '' || mb_strlen($lastName) > 100) {
    $errors['last_name'] = 'Enter a last name (up to 100 characters).';
}
if (mb_strlen($firstName . ' ' . $lastName) > 150) {
    $errors['last_name'] = 'The full name must be 150 characters or fewer.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    $errors['email'] = 'Enter a valid email address.';
}
if ($phone !== '' && (mb_strlen($phone) > 32 || !preg_match('/^[+0-9().\-\s]+$/', $phone))) {
    $errors['phone'] = 'Enter a valid phone number.';
}
if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72) {
    $errors['password'] = 'Use a password between 10 and 72 characters.';
}
if ($errors !== []) {
    json_error('Please correct the highlighted fields.', 422, $errors);
}

try {
    $statement = db()->prepare(
        'INSERT INTO users (full_name, email, phone, password_hash, role, status) '
        . 'VALUES (:full_name, :email, :phone, :password_hash, \'MEMBER\', \'ACTIVE\')'
    );
    $statement->execute([
        'full_name' => $firstName . ' ' . $lastName,
        'email' => $email,
        'phone' => $phone === '' ? null : $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $user = [
        'id' => (int) db()->lastInsertId(),
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'role' => 'MEMBER',
    ];
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    json_success(['user' => $user, 'csrf_token' => $_SESSION['csrf_token']], 'Your account is ready.', 201);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        json_error('An account with this email or phone number already exists.', 409);
    }
    error_log('Registration failed: ' . $exception->getMessage());
    json_error('We could not create your account right now.', 500);
}
