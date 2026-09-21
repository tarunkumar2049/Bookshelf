<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_api_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$db = Database::connection();

$recentBooks = $db->query('SELECT id, title, created_at FROM books ORDER BY created_at DESC')->fetchAll();
$recentChapters = $db->query(
    'SELECT chapters.id, chapters.title, chapters.chapter_number, books.title AS book_title
     FROM chapters
     INNER JOIN books ON books.id = chapters.book_id
     ORDER BY chapters.created_at DESC'
)->fetchAll();

json_response([
    'ok' => true,
    'books' => get_all_books($db),
    'chapters' => get_all_chapters($db),
    'genres' => get_genres($db),
    'recent_books' => $recentBooks,
    'recent_chapters' => $recentChapters,
    'csrf_token' => csrf_token(),
]);
