<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api.php';

$db = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $adminCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
    json_response([
        'ok' => true,
        'locked' => $adminCount > 0,
        'csrf_token' => csrf_token(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

enforce_rate_limit('setup_admin', 3, 3600);

$data = read_json_input();
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$confirmPassword = $data['confirm_password'] ?? '';

if (!verify_csrf_token($data['csrf_token'] ?? null)) {
    json_error('Your session expired. Refresh and try again.', 419);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Enter a valid email address.');
}

if (strlen($password) < 8) {
    json_error('Password must be at least 8 characters.');
}

if ($password !== $confirmPassword) {
    json_error('Password confirmation does not match.');
}

$db->beginTransaction();
try {
    $adminCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
    if ($adminCount > 0) {
        $db->rollBack();
        json_error('An admin account already exists.', 403);
    }

    $stmt = $db->prepare('INSERT INTO users (email, password, role) VALUES (:email, :password, "admin")');
    $stmt->execute([
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    json_error('Failed to create admin account.');
}

json_response(['ok' => true, 'message' => 'Admin created successfully.']);

