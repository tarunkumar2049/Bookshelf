<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

$user = current_user();
if (!$user) {
    json_error('Login required.', 401);
}

$db = Database::connection();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bookId = (int) ($_GET['book_id'] ?? 0);
    if ($bookId <= 0) {
        json_error('Invalid book.');
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = :user_id AND book_id = :book_id');
    $stmt->execute(['user_id' => $userId, 'book_id' => $bookId]);
    json_response(['ok' => true, 'in_wishlist' => (int) $stmt->fetchColumn() > 0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_input();
    if (!verify_csrf_token($data['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    $bookId = (int) ($data['book_id'] ?? 0);
    $action = $data['action'] ?? 'add';
    if ($bookId <= 0) {
        json_error('Invalid book.');
    }

    if (!in_array($action, ['add', 'remove'], true)) {
        json_error('Invalid action.');
    }

    if ($action === 'remove') {
        $stmt = $db->prepare('DELETE FROM wishlists WHERE user_id = :user_id AND book_id = :book_id');
        $stmt->execute(['user_id' => $userId, 'book_id' => $bookId]);
        json_response(['ok' => true, 'message' => 'Removed from wishlist.', 'in_wishlist' => false]);
    }

    $stmt = $db->prepare(
        'INSERT IGNORE INTO wishlists (user_id, book_id) VALUES (:user_id, :book_id)'
    );
    $stmt->execute(['user_id' => $userId, 'book_id' => $bookId]);
    json_response(['ok' => true, 'message' => 'Added to wishlist.', 'in_wishlist' => true]);
}

json_error('Method not allowed.', 405);

