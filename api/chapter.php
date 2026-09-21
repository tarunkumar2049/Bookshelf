<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$db = Database::connection();
$chapterId = (int) ($_GET['chapter_id'] ?? 0);

$stmt = $db->prepare(
    'SELECT chapters.*, books.title AS book_title, books.id AS book_id
     FROM chapters
     INNER JOIN books ON books.id = chapters.book_id
     WHERE chapters.id = :id'
);
$stmt->execute(['id' => $chapterId]);
$chapter = $stmt->fetch();

if (!$chapter) {
    json_error('Chapter not found.', 404);
}

$pagesStmt = $db->prepare('SELECT * FROM pages WHERE chapter_id = :chapter_id ORDER BY page_number ASC');
$pagesStmt->execute(['chapter_id' => $chapterId]);
$pages = $pagesStmt->fetchAll();

foreach ($pages as &$page) {
    if ($page['content_type'] === 'text') {
        $resolvedPath = realpath(__DIR__ . '/../' . $page['content_path']);
        $uploadRoot = realpath(UPLOAD_ROOT);
        if ($resolvedPath !== false && $uploadRoot !== false && strpos($resolvedPath, $uploadRoot) === 0 && is_file($resolvedPath)) {
            $page['text_content'] = file_get_contents($resolvedPath);
        } else {
            $page['text_content'] = 'Text page file is missing.';
        }
    }
}
unset($page);

$prevStmt = $db->prepare(
    'SELECT id FROM chapters
     WHERE book_id = :book_id AND chapter_number < :chapter_number
     ORDER BY chapter_number DESC, id DESC
     LIMIT 1'
);
$prevStmt->execute([
    'book_id' => (int) $chapter['book_id'],
    'chapter_number' => $chapter['chapter_number'],
]);

$nextStmt = $db->prepare(
    'SELECT id FROM chapters
     WHERE book_id = :book_id AND chapter_number > :chapter_number
     ORDER BY chapter_number ASC, id ASC
     LIMIT 1'
);
$nextStmt->execute([
    'book_id' => (int) $chapter['book_id'],
    'chapter_number' => $chapter['chapter_number'],
]);

$resumePage = null;
$viewer = current_user();
if ($viewer) {
    try {
        $hasPageColumn = (bool) $db->query("SHOW COLUMNS FROM reading_history LIKE 'page_number'")->fetch();
        if ($hasPageColumn) {
            $resumeStmt = $db->prepare(
                'SELECT page_number FROM reading_history
                 WHERE user_id = :user_id AND book_id = :book_id AND chapter_id = :chapter_id
                 LIMIT 1'
            );
            $resumeStmt->execute([
                'user_id' => (int) $viewer['id'],
                'book_id' => (int) $chapter['book_id'],
                'chapter_id' => (int) $chapter['id'],
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

json_response([
    'ok' => true,
    'chapter' => $chapter,
    'pages' => $pages,
    'previous_chapter_id' => $prevStmt->fetchColumn() ?: null,
    'next_chapter_id' => $nextStmt->fetchColumn() ?: null,
    'resume_page' => $resumePage,
]);

