<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_api_admin();

$db = Database::connection();

function genre_slug_for_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug === '' ? 'genre' : $slug;
}

function unique_genre_slug(PDO $db, string $base, int $excludeId = 0): string
{
    $slug = $base;
    $attempt = 1;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM genres WHERE slug = :slug AND id <> :exclude_id LIMIT 1');
        $stmt->execute(['slug' => $slug, 'exclude_id' => $excludeId]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $attempt++;
        $slug = $base . '-' . $attempt;
        if ($attempt > 100) {
            throw new RuntimeException('Could not generate a unique slug.');
        }
    }
}

function genre_list(PDO $db): array
{
    return $db->query(
        'SELECT genres.id, genres.name, genres.slug, COUNT(book_genres.book_id) AS book_count
         FROM genres
         LEFT JOIN book_genres ON book_genres.genre_id = genres.id
         GROUP BY genres.id, genres.name, genres.slug
         ORDER BY genres.name ASC'
    )->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'genres' => genre_list($db), 'csrf_token' => csrf_token()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $data = read_json_input();
        if (!verify_csrf_token($data['csrf_token'] ?? null)) {
            json_error('Your session expired. Refresh and try again.', 419);
        }

        $genreId = (int) ($data['id'] ?? 0);
        if ($genreId <= 0) {
            throw new RuntimeException('Choose a valid genre to delete.');
        }

        $stmt = $db->prepare('DELETE FROM genres WHERE id = :id');
        $stmt->execute(['id' => $genreId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Genre not found.');
        }

        json_response(['ok' => true, 'message' => 'Genre deleted successfully.']);
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
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the genre a name.');
        }

        $stmt = $db->prepare('INSERT INTO genres (name, slug) VALUES (:name, :slug)');
        $stmt->execute([
            'name' => mb_substr($name, 0, 80),
            'slug' => unique_genre_slug($db, genre_slug_for_name($name)),
        ]);
        json_response(['ok' => true, 'message' => 'Genre created successfully.', 'id' => (int) $db->lastInsertId()]);
    }

    if ($action === 'update') {
        $genreId = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($genreId <= 0) {
            throw new RuntimeException('Choose a valid genre.');
        }
        if ($name === '') {
            throw new RuntimeException('Give the genre a name.');
        }

        $stmt = $db->prepare('UPDATE genres SET name = :name, slug = :slug WHERE id = :id');
        $stmt->execute([
            'name' => mb_substr($name, 0, 80),
            'slug' => unique_genre_slug($db, genre_slug_for_name($name), $genreId),
            'id' => $genreId,
        ]);
        if ($stmt->rowCount() === 0) {
            $existsStmt = $db->prepare('SELECT id FROM genres WHERE id = :id');
            $existsStmt->execute(['id' => $genreId]);
            if (!$existsStmt->fetch()) {
                throw new RuntimeException('Genre not found.');
            }
        }

        json_response(['ok' => true, 'message' => 'Genre updated successfully.']);
    }

    json_error('Unknown action.');
} catch (Throwable $exception) {
    json_error($exception->getMessage());
}
