<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

$user = current_user();
if (!$user) {
    json_error('Login required.', 401);
}

$db = Database::connection();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    try {
        if (empty($_FILES['profile_image']['name'])) {
            throw new RuntimeException('Choose a profile image first.');
        }

        $oldImage = $user['profile_image'] ?? null;
        $imagePath = save_uploaded_image($_FILES['profile_image'], 'users');
        $stmt = $db->prepare('UPDATE users SET profile_image = :profile_image WHERE id = :id');
        $stmt->execute(['profile_image' => $imagePath, 'id' => $userId]);
        delete_uploaded_file_if_safe(is_string($oldImage) ? $oldImage : null);

        json_response(['ok' => true, 'message' => 'Profile picture updated.', 'profile_image' => $imagePath]);
    } catch (Throwable $exception) {
        json_error($exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$historyStmt = $db->prepare(
    'SELECT books.id, books.title, books.author, books.cover_image, books.pdf_file,
            chapters.id AS chapter_id, chapters.title AS chapter_title,
            chapters.chapter_number, reading_history.last_read_at
     FROM reading_history
     INNER JOIN books ON books.id = reading_history.book_id
     LEFT JOIN chapters ON chapters.id = reading_history.chapter_id
     WHERE reading_history.user_id = :user_id
     ORDER BY reading_history.last_read_at DESC'
);
$historyStmt->execute(['user_id' => $userId]);

$wishlistStmt = $db->prepare(
    'SELECT books.id, books.title, books.author, books.cover_image, wishlists.created_at
     FROM wishlists
     INNER JOIN books ON books.id = wishlists.book_id
     WHERE wishlists.user_id = :user_id
     ORDER BY wishlists.created_at DESC'
);
$wishlistStmt->execute(['user_id' => $userId]);

$history = $historyStmt->fetchAll();
$wishlist = $wishlistStmt->fetchAll();

json_response([
    'ok' => true,
    'user' => $user,
    'stats' => [
        'reading_books' => count($history),
        'wishlist_books' => count($wishlist),
    ],
    'history' => $history,
    'wishlist' => $wishlist,
]);
