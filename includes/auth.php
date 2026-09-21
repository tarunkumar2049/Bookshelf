<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    if (isset($_SESSION['cached_user']) && is_array($_SESSION['cached_user']) && (int) ($_SESSION['cached_user']['id'] ?? 0) === (int) $_SESSION['user_id']) {
        return $_SESSION['cached_user'];
    }

    $stmt = Database::connection()->prepare(
        'SELECT id, email, role, profile_image, email_verified FROM users WHERE id = :id'
    );
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['cached_user'] = $user;
        return $user;
    }

    return null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function require_admin(): void
{
    if (!is_admin()) {
        flash('Please log in as admin first.', 'error');
        redirect('admin/login.html');
    }
}

function login_admin(string $email, string $password): bool
{
    $stmt = Database::connection()->prepare(
        'SELECT id, email, password, role FROM users WHERE email = :email AND role = "admin" LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return true;
}

function login_reader(string $email, string $password): bool
{
    $stmt = Database::connection()->prepare(
        'SELECT id, email, password, role FROM users WHERE email = :email AND role IN ("user", "admin") LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function logout_admin(): void
{
    logout_user();
}
