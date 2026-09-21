<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$db = Database::connection();
$bookId = (int) ($_GET['id'] ?? 0);
$book = $bookId > 0 ? get_book($db, $bookId) : null;

if (!$book) {
    json_error('Book not found.', 404);
}

$resumePage = null;
$viewer = current_user();
if ($viewer) {
    try {
        $hasPageColumn = (bool) $db->query("SHOW COLUMNS FROM reading_history LIKE 'page_number'")->fetch();
        if ($hasPageColumn) {
            $resumeStmt = $db->prepare(
                'SELECT page_number FROM reading_history
                 WHERE user_id = :user_id AND book_id = :book_id
                 LIMIT 1'
            );
            $resumeStmt->execute([
                'user_id' => (int) $viewer['id'],
                'book_id' => $bookId,
            ]);
            $saved = $resumeStmt->fetchColumn();
            if ($saved !== false && (int) $saved > 0) {
                $resumePage = (int) $saved;
            }
        }
    } catch (Throwable $e) {
        $resumePage = null;
    }
}

$chapters = [];
try {
    $chaptersStmt = $db->prepare(
        'SELECT c.*, COUNT(p.id) AS page_count
         FROM chapters c
         LEFT JOIN pages p ON p.chapter_id = c.id
         WHERE c.book_id = :book_id
         GROUP BY c.id
         ORDER BY c.chapter_number ASC, c.id ASC'
    );
    $chaptersStmt->execute(['book_id' => $bookId]);
    $chapters = $chaptersStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $chapters = get_chapters_for_book($db, $bookId);
}

json_response([
    'ok' => true,
    'book' => $book,
    'genres' => get_book_genres($db, $bookId),
    'chapters' => $chapters,
    'recommended' => get_recommended_books_for_book($db, $bookId, 10),
    'resume_page' => $resumePage,
]);
