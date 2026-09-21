<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['ok' => false, 'error' => $message], $status);
}

function require_api_admin(): void
{
    if (!is_admin()) {
        json_error('Admin login required.', 401);
    }
}

function read_json_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false && stripos($contentType, 'multipart/form-data') !== false) {
        return [];
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
