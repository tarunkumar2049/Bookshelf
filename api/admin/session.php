<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'user' => current_user(),
        'csrf_token' => csrf_token(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforce_rate_limit('admin_login', 5, 300);

    $data = read_json_input();
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $remember = !empty($data['remember']);

    if (!verify_csrf_token($data['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    $stmt = Database::connection()->prepare(
        'SELECT id, password FROM users WHERE email = :email AND role = "admin" LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();

    if ($email === '' || $password === '' || !$admin || !password_verify($password, $admin['password'])) {
        json_error('Invalid admin credentials.', 401);
    }

    set_logged_in_user((int) $admin['id'], $remember);

    json_response(['ok' => true, 'user' => current_user(), 'csrf_token' => csrf_token()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    logout_admin();
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);

