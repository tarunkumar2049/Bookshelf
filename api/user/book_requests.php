<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

$user = current_user();
if ($user === null) {
    json_error('Login required.', 401);
}

$db = Database::connection();
ensure_book_request_tables($db);
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare(
        'SELECT id, title, author, status, created_at, fulfilled_at, fulfilled_book_id, seen_at
         FROM book_requests
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 50'
    );
    $stmt->execute(['user_id' => $userId]);
    $requests = $stmt->fetchAll();

    // Bell notifications: fulfilled requests that are unseen, or seen within the last 24 hours.
    // The bell is hidden when there is nothing in this list.
    $notifStmt = $db->prepare(
        "SELECT id, title, author, fulfilled_at, fulfilled_book_id, seen_at
         FROM book_requests
         WHERE user_id = :user_id
           AND status = 'fulfilled'
           AND (seen_at IS NULL OR seen_at > (NOW() - INTERVAL 24 HOUR))
         ORDER BY fulfilled_at DESC"
    );
    $notifStmt->execute(['user_id' => $userId]);
    $notifications = $notifStmt->fetchAll();

    json_response([
        'ok' => true,
        'requests' => $requests,
        'notifications' => $notifications,
        'csrf_token' => csrf_token(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = read_json_input();
        if (!verify_csrf_token($data['csrf_token'] ?? null)) {
            json_error('Your session expired. Refresh and try again.', 419);
        }

        $action = strtolower(trim((string) ($data['action'] ?? 'create')));

        // User opened the bell dropdown: start the 24h expiry window.
        if ($action === 'seen' || $action === 'mark_seen') {
            $db->prepare(
                "UPDATE book_requests
                 SET seen_at = NOW()
                 WHERE user_id = :user_id AND status = 'fulfilled' AND seen_at IS NULL"
            )->execute(['user_id' => $userId]);
            json_response(['ok' => true, 'message' => 'Notifications marked as seen.']);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $author = trim((string) ($data['author'] ?? ''));

        if ($title === '' || $author === '') {
            throw new RuntimeException('Book title and author are required.');
        }
        if (mb_strlen($title) > 255 || mb_strlen($author) > 255) {
            throw new RuntimeException('Title and author must be under 255 characters.');
        }

        enforce_rate_limit('book_request:' . $userId, 10, 3600);

        // Avoid duplicate pending requests for the same book by the same user.
        $dupStmt = $db->prepare(
            "SELECT id FROM book_requests
             WHERE user_id = :user_id AND status = 'pending'
               AND LOWER(TRIM(title)) = LOWER(TRIM(:title))
               AND LOWER(TRIM(author)) = LOWER(TRIM(:author))
             LIMIT 1"
        );
        $dupStmt->execute(['user_id' => $userId, 'title' => $title, 'author' => $author]);
        if ($dupStmt->fetch()) {
            throw new RuntimeException('You already requested this book.');
        }

        $insertStmt = $db->prepare(
            'INSERT INTO book_requests (user_id, title, author, status) VALUES (:user_id, :title, :author, "pending")'
        );
        $insertStmt->execute(['user_id' => $userId, 'title' => $title, 'author' => $author]);

        // Email the admin (same address as the SMTP account) about the new request.
        // The request itself succeeds even if the email fails.
        try {
            $adminEmail = trim((string) ($_ENV['SMTP_USERNAME'] ?? ''));
            if ($adminEmail === '') {
                $adminEmail = 'library.of.bookshelf@gmail.com';
            }
            send_book_request_notification($adminEmail, (string) ($user['email'] ?? ''), $title, $author);
        } catch (Throwable $mailError) {
            error_log('Book request email failed: ' . $mailError->getMessage());
        }

        json_response(['ok' => true, 'message' => 'Book requested. The admin has been notified.']);
    } catch (Throwable $exception) {
        json_error($exception->getMessage());
    }
}

json_error('Method not allowed.', 405);
