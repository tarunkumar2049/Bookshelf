<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

function json_login_requires_otp(string $email, string $message): void
{
    json_response([
        'ok' => true,
        'requires_verification' => true,
        'email' => $email,
        'message' => $message,
        'csrf_token' => csrf_token(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'ok' => true,
        'user' => current_user(),
        'csrf_token' => csrf_token(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_input();
    $mode = $data['mode'] ?? 'login';
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $db = Database::connection();

    if (!verify_csrf_token($data['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    if ($mode === 'verify') {
        enforce_rate_limit('otp_verify', 5, 300);

        $otp = trim((string) ($data['otp'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $otp)) {
            json_error('Enter the 6 digit OTP sent to your email.');
        }

        $stmt = $db->prepare(
            'SELECT id, otp_code_hash, otp_expires_at FROM users WHERE email = :email AND role IN ("user", "admin") LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || empty($user['otp_code_hash'])) {
    json_error('Invalid or expired OTP.');
}

$expiry = strtotime((string)$user['otp_expires_at']);

if (time() > $expiry) {
    json_error('Invalid or expired OTP.');
}

        if (!password_verify($otp, $user['otp_code_hash'])) {
            json_error('Invalid OTP.');
        }

        $otpAttempts = (int) ($_SESSION['otp_attempts'] ?? 0);
        if ($otpAttempts >= 5) {
            json_error('Too many failed attempts. Please request a new OTP.');
        }

        $updateStmt = $db->prepare(
            'UPDATE users SET email_verified = 1, otp_code_hash = NULL, otp_expires_at = NULL WHERE id = :id'
        );
        $updateStmt->execute(['id' => (int) $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        unset($_SESSION['cached_user']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        json_response(['ok' => true, 'user' => current_user(), 'csrf_token' => csrf_token()]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        json_error('Enter a valid email and an 8 character password.');
    }

    if ($mode === 'register') {
        enforce_rate_limit('user_register', 3, 600);

        try {
            $stmt = $db->prepare(
                'INSERT INTO users (email, password, role, email_verified) VALUES (:email, :password, "user", 0)'
            );
            $stmt->execute([
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (Throwable $exception) {
            json_error('Invalid login details.', 401);
        }

        $otp = create_reader_otp($db, $email);
        if (!send_reader_otp($email, $otp)) {
            json_error('Could not send OTP email. Configure SMTP/mail in PHP and try again.');
        }
        json_login_requires_otp($email, 'Account created. Enter the OTP sent to your email.');
    }

    $stmt = $db->prepare(
        'SELECT id, email, password, email_verified FROM users WHERE email = :email AND role IN ("user", "admin") LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        enforce_rate_limit('user_login:' . $email, 5, 300);
        json_error('Invalid login details.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['cached_user']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    json_response(['ok' => true, 'user' => current_user(), 'csrf_token' => csrf_token()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    logout_user();
    json_response(['ok' => true]);
}

json_error('Method not allowed.', 405);
