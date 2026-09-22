<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_api_admin();

$db = Database::connection();
ensure_book_request_tables($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requests = $db->query(
        'SELECT book_requests.id, book_requests.title, book_requests.author, book_requests.status,
                book_requests.created_at, book_requests.fulfilled_at, book_requests.fulfilled_book_id,
                users.email AS user_email
         FROM book_requests
         LEFT JOIN users ON users.id = book_requests.user_id
         ORDER BY
            CASE book_requests.status WHEN \'pending\' THEN 0 WHEN \'fulfilled\' THEN 1 ELSE 2 END ASC,
            book_requests.created_at DESC'
    )->fetchAll();

    $pending = 0;
    foreach ($requests as $row) {
        if (($row['status'] ?? '') === 'pending') {
            $pending++;
        }
    }

    json_response([
        'ok' => true,
        'requests' => $requests,
        'pending_count' => $pending,
        'csrf_token' => csrf_token(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = read_json_input();
        if (!verify_csrf_token($data['csrf_token'] ?? null)) {
            json_error('Your session expired. Refresh and try again.', 419);
        }

        $action = strtolower(trim((string) ($data['action'] ?? '')));
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Choose a valid request.');
        }

        if ($action === 'fulfill') {
            $bookId = (int) ($data['book_id'] ?? 0);
            $params = ['id' => $id];
            $sql = "UPDATE book_requests SET status = 'fulfilled', fulfilled_at = NOW()";
            if ($bookId > 0) {
                $check = $db->prepare('SELECT id FROM books WHERE id = :id');
                $check->execute(['id' => $bookId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Linked book not found.');
                }
                $sql .= ', fulfilled_book_id = :book_id';
                $params['book_id'] = $bookId;
            }
            $sql .= ' WHERE id = :id';
            $db->prepare($sql)->execute($params);
            json_response(['ok' => true, 'message' => 'Request marked as fulfilled. The user will be notified.']);
        }

        if ($action === 'reject') {
            $db->prepare("UPDATE book_requests SET status = 'rejected' WHERE id = :id")
               ->execute(['id' => $id]);
            json_response(['ok' => true, 'message' => 'Request rejected.']);
        }

        if ($action === 'reopen') {
            $db->prepare("UPDATE book_requests SET status = 'pending', fulfilled_book_id = NULL, fulfilled_at = NULL, seen_at = NULL WHERE id = :id")
               ->execute(['id' => $id]);
            json_response(['ok' => true, 'message' => 'Request reopened.']);
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $exception) {
        json_error($exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $data = read_json_input();
        if (!verify_csrf_token($data['csrf_token'] ?? null)) {
            json_error('Your session expired. Refresh and try again.', 419);
        }
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Choose a valid request.');
        }
        $db->prepare('DELETE FROM book_requests WHERE id = :id')->execute(['id' => $id]);
        json_response(['ok' => true, 'message' => 'Request deleted.']);
    } catch (Throwable $exception) {
        json_error($exception->getMessage());
    }
}

json_error('Method not allowed.', 405);
