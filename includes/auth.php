<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

const REMEMBER_COOKIE = 'bookshelf_remember';
const REMEMBER_DAYS = 30;

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

try_remember_login();

function ensure_remember_tokens_table(): void
{
    try {
        Database::connection()->exec(
            'CREATE TABLE IF NOT EXISTS remember_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_remember_tokens_user (user_id),
                INDEX idx_remember_tokens_hash (token_hash)
            ) ENGINE=InnoDB'
        );
    } catch (Throwable $ignored) {
    }
}

function remember_cookie_secure(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function issue_remember_token(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    ensure_remember_tokens_table();
    try {
        $db = Database::connection();
        // Cleanup expired tokens (best-effort) + cap tokens per user.
        $db->prepare('DELETE FROM remember_tokens WHERE expires_at < NOW()')->execute();
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $db->prepare('DELETE FROM remember_tokens WHERE user_id = :uid AND expires_at < NOW()')->execute(['uid' => $userId]);
        $count = (int) $db->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ' . $userId)->fetchColumn();
        if ($count >= 5) {
            $db->prepare('DELETE FROM remember_tokens WHERE user_id = :uid ORDER BY created_at ASC LIMIT 1')->execute(['uid' => $userId]);
        }
        $db->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL ' . REMEMBER_DAYS . ' DAY))')
            ->execute(['uid' => $userId, 'hash' => $hash]);
        setcookie(REMEMBER_COOKIE, $raw, [
            'expires' => time() + REMEMBER_DAYS * 86400,
            'path' => '/',
            'secure' => remember_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[REMEMBER_COOKIE] = $raw;
    } catch (Throwable $ignored) {
    }
}

function clear_remember_token(): void
{
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($raw) && $raw !== '') {
        try {
            ensure_remember_tokens_table();
            Database::connection()->prepare('DELETE FROM remember_tokens WHERE token_hash = :hash')
                ->execute(['hash' => hash('sha256', $raw)]);
        } catch (Throwable $ignored) {
        }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => remember_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

function try_remember_login(): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($raw) || strlen($raw) !== 64 || !ctype_xdigit($raw)) {
        return;
    }
    ensure_remember_tokens_table();
    try {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT user_id, expires_at FROM remember_tokens WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $raw)]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $db->prepare('DELETE FROM remember_tokens WHERE token_hash = :hash')->execute(['hash' => hash('sha256', $raw)]);
            clear_remember_token();
            return;
        }
        $userId = (int) $row['user_id'];
        $userStmt = $db->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => $userId]);
        if (!$userStmt->fetch()) {
            $db->prepare('DELETE FROM remember_tokens WHERE token_hash = :hash')->execute(['hash' => hash('sha256', $raw)]);
            clear_remember_token();
            return;
        }
        // Rotate: single-use token.
        $db->prepare('DELETE FROM remember_tokens WHERE token_hash = :hash')->execute(['hash' => hash('sha256', $raw)]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        unset($_SESSION['cached_user']);
        issue_remember_token($userId);
    } catch (Throwable $ignored) {
    }
}

function set_logged_in_user(int $userId, bool $remember = false): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['cached_user']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['remember_pending']);
    if ($remember) {
        issue_remember_token($userId);
    } else {
        clear_remember_token();
    }
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

function login_admin(string $email, string $password, bool $remember = false): bool
{
    $stmt = Database::connection()->prepare(
        'SELECT id, email, password, role FROM users WHERE email = :email AND role = "admin" LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    set_logged_in_user((int) $user['id'], $remember);
    return true;
}

function login_reader(string $email, string $password, bool $remember = false): bool
{
    $stmt = Database::connection()->prepare(
        'SELECT id, email, password, role FROM users WHERE email = :email AND role IN ("user", "admin") LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    set_logged_in_user((int) $user['id'], $remember);
    return true;
}

function logout_user(): void
{
    clear_remember_token();
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
