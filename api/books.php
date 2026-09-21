<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$db = Database::connection();
$query = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$mode = trim($_GET['mode'] ?? 'recent');
$genreId = max(0, (int) ($_GET['genre_id'] ?? 0));
$perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 12)));

if ($mode === 'popular') {
    $result = get_books($db, $query, $page, 12, $genreId, true);
} elseif ($mode === 'recommended') {
    $recommended = get_recommended_books_for_user($db, current_user(), 12);
    $result = [
        'books' => $recommended,
        'total' => count($recommended),
        'pages' => 1,
    ];
} else {
    $result = get_books($db, $query, $page, $perPage, $genreId);
}

json_response([
    'ok' => true,
    'books' => $result['books'],
    'genres' => get_genres($db),
    'total' => $result['total'],
    'pages' => $result['pages'],
    'page' => $page,
]);
