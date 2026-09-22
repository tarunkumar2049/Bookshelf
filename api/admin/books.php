<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_api_admin();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $data = read_json_input();
        if (!verify_csrf_token($data['csrf_token'] ?? null)) {
            json_error('Your session expired. Refresh and try again.', 419);
        }

        $bookId = (int) ($data['id'] ?? 0);
        if ($bookId <= 0) {
            throw new RuntimeException('Choose a valid book to delete.');
        }

        $db = Database::connection();

        $coverStmt = $db->prepare('SELECT cover_image, pdf_file FROM books WHERE id = :id');
        $coverStmt->execute(['id' => $bookId]);
        $bookFiles = $coverStmt->fetch();

        if (!$bookFiles) {
            throw new RuntimeException('Book not found.');
        }

        $pageStmt = $db->prepare(
            'SELECT pages.content_path
             FROM pages
             INNER JOIN chapters ON chapters.id = pages.chapter_id
             WHERE chapters.book_id = :book_id'
        );
        $pageStmt->execute(['book_id' => $bookId]);
        $pagePaths = $pageStmt->fetchAll(PDO::FETCH_COLUMN);

        delete_uploaded_file_if_safe(is_string($bookFiles['cover_image'] ?? null) ? $bookFiles['cover_image'] : null);
        delete_uploaded_file_if_safe(is_string($bookFiles['pdf_file'] ?? null) ? $bookFiles['pdf_file'] : null);
        foreach ($pagePaths as $path) {
            delete_uploaded_file_if_safe(is_string($path) ? $path : null);
        }

        $deleteStmt = $db->prepare('DELETE FROM books WHERE id = :id');
        $deleteStmt->execute(['id' => $bookId]);

        json_response(['ok' => true, 'message' => 'Book deleted successfully.']);
    } catch (Throwable $exception) {
        json_error($exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    try {
        $data = read_json_input();
        if (!verify_csrf_token($data['csrf_token'] ?? null)) {
            json_error('Your session expired. Refresh and try again.', 419);
        }

        $bookId = (int) ($data['id'] ?? 0);
        if ($bookId <= 0) {
            throw new RuntimeException('Choose a valid book.');
        }

        $stmt = Database::connection()->prepare('UPDATE books SET is_popular = :is_popular WHERE id = :id');
        $stmt->execute([
            'is_popular' => !empty($data['is_popular']) ? 1 : 0,
            'id' => $bookId,
        ]);

        json_response(['ok' => true, 'message' => 'Popular setting updated.']);
    } catch (Throwable $exception) {
        json_error($exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

try {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isPopular = isset($_POST['is_popular']) ? 1 : 0;
    $genreIds = $_POST['genre_ids'] ?? [];
    if (!is_array($genreIds)) {
        $genreIds = [];
    }

    if ($title === '' || $author === '' || $description === '') {
        throw new RuntimeException('Title, author, and description are required.');
    }

    $bookId = (int) ($_POST['id'] ?? 0);
    $db = Database::connection();

    $coverPath = null;
    if (!empty($_FILES['cover_image']['name'])) {
        $coverPath = save_uploaded_image($_FILES['cover_image'], 'books');
    }

    $pdfPath = null;
    if (!empty($_FILES['pdf_file']['name'])) {
        $pdfPath = save_uploaded_pdf($_FILES['pdf_file'], 'books/pdfs');
    }

    if ($bookId > 0) {
        $existingStmt = $db->prepare('SELECT cover_image, pdf_file FROM books WHERE id = :id');
        $existingStmt->execute(['id' => $bookId]);
        $existingBook = $existingStmt->fetch();
        if (!$existingBook) {
            throw new RuntimeException('Book not found.');
        }

        $sql = 'UPDATE books
                SET title = :title,
                    author = :author,
                    description = :description,
                    is_popular = :is_popular';
        $params = [
            'title' => $title,
            'author' => $author,
            'description' => $description,
            'is_popular' => $isPopular,
            'id' => $bookId,
        ];

        if ($coverPath !== null) {
            $sql .= ', cover_image = :cover_image';
            $params['cover_image'] = $coverPath;
        }

        if ($pdfPath !== null) {
            $sql .= ', pdf_file = :pdf_file';
            $params['pdf_file'] = $pdfPath;
        }

        $sql .= ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        sync_book_genres($db, $bookId, $genreIds);

        if ($coverPath !== null) {
            delete_uploaded_file_if_safe(is_string($existingBook['cover_image'] ?? null) ? $existingBook['cover_image'] : null);
        }
        if ($pdfPath !== null) {
            delete_uploaded_file_if_safe(is_string($existingBook['pdf_file'] ?? null) ? $existingBook['pdf_file'] : null);
        }

        try {
            fulfill_matching_book_requests($db, $title, $author, $bookId);
        } catch (Throwable $notifyError) {
        }

        json_response(['ok' => true, 'message' => 'Book updated successfully.']);
    }

    $stmt = $db->prepare(
        'INSERT INTO books (title, author, description, cover_image, pdf_file, is_popular)
         VALUES (:title, :author, :description, :cover_image, :pdf_file, :is_popular)'
    );
    $stmt->execute([
        'title' => $title,
        'author' => $author,
        'description' => $description,
        'cover_image' => $coverPath,
        'pdf_file' => $pdfPath,
        'is_popular' => $isPopular,
    ]);
    sync_book_genres($db, (int) $db->lastInsertId(), $genreIds);
    $newBookId = (int) $db->lastInsertId();

    // Notify users who requested this exact title+author.
    $fulfilledCount = 0;
    try {
        $fulfilledCount = fulfill_matching_book_requests($db, $title, $author, $newBookId);
    } catch (Throwable $notifyError) {
        // Book upload succeeds even if request matching fails.
    }

    json_response(['ok' => true, 'message' => $fulfilledCount > 0
        ? 'Book added successfully. ' . $fulfilledCount . ' request(s) fulfilled.'
        : 'Book added successfully.']);
} catch (Throwable $exception) {
    json_error($exception->getMessage());
}
