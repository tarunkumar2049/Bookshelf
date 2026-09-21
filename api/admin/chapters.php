<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_api_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

try {
    $data = read_json_input();

    if (!verify_csrf_token($data['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    $bookId = (int) ($data['book_id'] ?? 0);
    $title = trim($data['title'] ?? '');
    $chapterNumber = (int) ($data['chapter_number'] ?? 0);

    if ($bookId <= 0 || $title === '' || $chapterNumber <= 0) {
        throw new RuntimeException('Choose a book, title the chapter, and enter a valid chapter number.');
    }

    $bookCheck = Database::connection()->prepare('SELECT id FROM books WHERE id = :id');
    $bookCheck->execute(['id' => $bookId]);
    if (!$bookCheck->fetch()) {
        throw new RuntimeException('Selected book does not exist.');
    }

    $stmt = Database::connection()->prepare(
        'INSERT INTO chapters (book_id, title, chapter_number) VALUES (:book_id, :title, :chapter_number)'
    );
    $stmt->execute([
        'book_id' => $bookId,
        'title' => $title,
        'chapter_number' => $chapterNumber,
    ]);

    json_response(['ok' => true, 'message' => 'Chapter added successfully.']);
} catch (Throwable $exception) {
    json_error($exception->getMessage());
}

