<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_api_admin();

$db = Database::connection();
ensure_custom_section_tables($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'sections' => get_custom_sections($db, false), 'csrf_token' => csrf_token()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $data = read_json_input();
        if (!verify_csrf_token($data['csrf_token'] ?? null)) {
            json_error('Your session expired. Refresh and try again.', 419);
        }

        $sectionId = (int) ($data['id'] ?? 0);
        if ($sectionId <= 0) {
            throw new RuntimeException('Choose a valid section to delete.');
        }

        $stmt = $db->prepare('DELETE FROM custom_sections WHERE id = :id');
        $stmt->execute(['id' => $sectionId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Section not found.');
        }

        json_response(['ok' => true, 'message' => 'Section deleted successfully.']);
    } catch (Throwable $exception) {
        json_error($exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

try {
    $data = read_json_input();
    if (!verify_csrf_token($data['csrf_token'] ?? null)) {
        json_error('Your session expired. Refresh and try again.', 419);
    }

    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'create') {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Give the section a title.');
        }
        $subtitle = trim((string) ($data['subtitle'] ?? ''));
        $subtitle = $subtitle === '' ? null : mb_substr($subtitle, 0, 255);

        $stmt = $db->prepare('INSERT INTO custom_sections (title, subtitle) VALUES (:title, :subtitle)');
        $stmt->execute(['title' => mb_substr($title, 0, 255), 'subtitle' => $subtitle]);
        json_response(['ok' => true, 'message' => 'Section created successfully.', 'id' => (int) $db->lastInsertId()]);
    }

    if ($action === 'update') {
        $sectionId = (int) ($data['id'] ?? 0);
        if ($sectionId <= 0) {
            throw new RuntimeException('Choose a valid section.');
        }

        $existsStmt = $db->prepare('SELECT id FROM custom_sections WHERE id = :id');
        $existsStmt->execute(['id' => $sectionId]);
        if (!$existsStmt->fetch()) {
            throw new RuntimeException('Section not found.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title !== '') {
            $titleStmt = $db->prepare('UPDATE custom_sections SET title = :title WHERE id = :id');
            $titleStmt->execute(['title' => mb_substr($title, 0, 255), 'id' => $sectionId]);
        }

        if (array_key_exists('subtitle', $data)) {
            $subtitle = trim((string) $data['subtitle']);
            $subtitleStmt = $db->prepare('UPDATE custom_sections SET subtitle = :subtitle WHERE id = :id');
            $subtitleStmt->execute(['subtitle' => $subtitle === '' ? null : mb_substr($subtitle, 0, 255), 'id' => $sectionId]);
        }

        $bookIds = $data['book_ids'] ?? [];
        if (!is_array($bookIds)) {
            $bookIds = [];
        }
        $bookIds = array_values(array_unique(array_filter(array_map('intval', $bookIds), static fn ($id) => $id > 0)));

        $validIds = [];
        if ($bookIds) {
            $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
            $checkStmt = $db->prepare("SELECT id FROM books WHERE id IN ($placeholders)");
            $checkStmt->execute($bookIds);
            $validIds = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $db->beginTransaction();
        $deleteStmt = $db->prepare('DELETE FROM custom_section_books WHERE section_id = :section_id');
        $deleteStmt->execute(['section_id' => $sectionId]);
        $insertStmt = $db->prepare(
            'INSERT INTO custom_section_books (section_id, book_id, sort_order) VALUES (:section_id, :book_id, :sort_order)'
        );
        foreach ($validIds as $order => $bookId) {
            $insertStmt->execute(['section_id' => $sectionId, 'book_id' => $bookId, 'sort_order' => $order]);
        }
        $db->commit();

        json_response(['ok' => true, 'message' => 'Section updated successfully.']);
    }

    json_error('Unknown action.');
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    json_error($exception->getMessage());
}
