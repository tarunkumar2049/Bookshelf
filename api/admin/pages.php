<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_api_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$db = Database::connection();

try {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    $mode = $_POST['mode'] ?? '';
    $chapterId = (int) ($_POST['chapter_id'] ?? 0);

    if ($chapterId <= 0) {
        throw new RuntimeException('Choose a chapter before uploading pages.');
    }

    $chapterCheck = $db->prepare('SELECT id FROM chapters WHERE id = :id');
    $chapterCheck->execute(['id' => $chapterId]);
    if (!$chapterCheck->fetch()) {
        throw new RuntimeException('Selected chapter does not exist.');
    }

    if ($mode === 'images') {
        $startPage = max(1, (int) ($_POST['start_page'] ?? 1));
        if (empty($_FILES['pages']['name'][0])) {
            throw new RuntimeException('Choose at least one image page.');
        }

        $fileCount = count(array_filter($_FILES['pages']['name'], fn($n) => $n !== ''));
        if ($fileCount > 50) {
            throw new RuntimeException('Maximum 50 pages per upload.');
        }

        $db->beginTransaction();
        foreach ($_FILES['pages']['name'] as $index => $name) {
            if ($name === '') {
                continue;
            }

            $singleFile = [
                'name' => $_FILES['pages']['name'][$index],
                'type' => $_FILES['pages']['type'][$index],
                'tmp_name' => $_FILES['pages']['tmp_name'][$index],
                'error' => $_FILES['pages']['error'][$index],
                'size' => $_FILES['pages']['size'][$index],
            ];
            $pageNumber = $startPage + $index;
            $path = save_uploaded_image($singleFile, 'chapters/chapter_' . $chapterId);

            $stmt = $db->prepare(
                'INSERT INTO pages (chapter_id, page_number, content_type, content_path)
                 VALUES (:chapter_id, :page_number, "image", :content_path)
                 ON DUPLICATE KEY UPDATE content_type = VALUES(content_type), content_path = VALUES(content_path)'
            );
            $stmt->execute([
                'chapter_id' => $chapterId,
                'page_number' => $pageNumber,
                'content_path' => $path,
            ]);
        }
        $db->commit();
        json_response(['ok' => true, 'message' => 'Image pages uploaded successfully.']);
    }

    if ($mode === 'text') {
        $pageNumber = max(1, (int) ($_POST['page_number'] ?? 1));
        $path = save_text_page($_POST['text_content'] ?? '', $chapterId, $pageNumber);

        $stmt = $db->prepare(
            'INSERT INTO pages (chapter_id, page_number, content_type, content_path)
             VALUES (:chapter_id, :page_number, "text", :content_path)
             ON DUPLICATE KEY UPDATE content_type = VALUES(content_type), content_path = VALUES(content_path)'
        );
        $stmt->execute([
            'chapter_id' => $chapterId,
            'page_number' => $pageNumber,
            'content_path' => $path,
        ]);

        json_response(['ok' => true, 'message' => 'Text page uploaded successfully.']);
    }

    throw new RuntimeException('Unknown page upload mode.');
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    json_error($exception->getMessage());
}

