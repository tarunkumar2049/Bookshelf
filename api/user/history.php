<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';

$user = current_user();
if (!$user) {
    json_error('Login required.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$data = read_json_input();
if (!verify_csrf_token($data['csrf_token'] ?? null)) {
    json_error('Your session expired. Refresh and try again.', 419);
}

$db = Database::connection();
$chapterId = (int) ($data['chapter_id'] ?? 0);
$bookId = (int) ($data['book_id'] ?? 0);
$pageNumber = (int) ($data['page_number'] ?? 0);
if ($pageNumber < 1) {
    $pageNumber = null;
}

if ($chapterId > 0) {
    $chapterStmt = $db->prepare('SELECT id, book_id FROM chapters WHERE id = :id');
    $chapterStmt->execute(['id' => $chapterId]);
    $chapter = $chapterStmt->fetch();

    if (!$chapter) {
        json_error('Chapter not found.', 404);
    }

    $bookId = (int) $chapter['book_id'];
} elseif ($bookId > 0) {
    $bookStmt = $db->prepare('SELECT id, pdf_file FROM books WHERE id = :id');
    $bookStmt->execute(['id' => $bookId]);
    $book = $bookStmt->fetch();

    if (!$book || empty($book['pdf_file'])) {
        json_error('PDF book not found.', 404);
    }
    $chapterId = null;
} else {
    json_error('Invalid reading target.');
}

$hasPageColumn = (bool) $db->query("SHOW COLUMNS FROM reading_history LIKE 'page_number'")->fetch();

if ($hasPageColumn) {
    $stmt = $db->prepare(
        'INSERT INTO reading_history (user_id, book_id, chapter_id, page_number)
         VALUES (:user_id, :book_id, :chapter_id, :page_number)
         ON DUPLICATE KEY UPDATE chapter_id = VALUES(chapter_id), page_number = VALUES(page_number), last_read_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'user_id' => (int) $user['id'],
        'book_id' => $bookId,
        'chapter_id' => $chapterId,
        'page_number' => $pageNumber,
    ]);
} else {
    $stmt = $db->prepare(
        'INSERT INTO reading_history (user_id, book_id, chapter_id)
         VALUES (:user_id, :book_id, :chapter_id)
         ON DUPLICATE KEY UPDATE chapter_id = VALUES(chapter_id), last_read_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'user_id' => (int) $user['id'],
        'book_id' => $bookId,
        'chapter_id' => $chapterId,
    ]);
}

json_response(['ok' => true]);
