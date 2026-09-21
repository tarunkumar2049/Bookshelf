<?php
declare(strict_types=1);

// Load .env file if it exists (for InfinityFree and hosts without native env var support).
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $_ENV[trim($parts[0])] = trim($parts[1]);
        }
    }
}

// Database credentials — override with environment variables in production.
// const DB_HOST = $_ENV['DB_HOST'] ?? '127.0.0.1';
// const DB_NAME = $_ENV['DB_NAME'] ?? 'bookshelf_reader';
// const DB_USER = $_ENV['DB_USER'] ?? 'root';
// const DB_PASS = $_ENV['DB_PASS'] ?? '';
// const DB_CHARSET = 'utf8mb4';

define('DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'bookshelf_reader');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

// Files are saved under /uploads and their relative paths are stored in MySQL.
const UPLOAD_ROOT = __DIR__ . '/../uploads';
const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10 MB
const MAX_PDF_SIZE = 60 * 1024 * 1024; // 60 MB

/*
 * BASE_URL is detected from the current script path, including when the app is
 * installed in a folder such as http://localhost/bookshelf-reader/.
 */
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptDir = preg_replace('#/api(?:/admin)?$#', '', $scriptDir);
$baseUrl = preg_replace('#/admin$#', '', $scriptDir);
$baseUrl = $baseUrl === '/' ? '' : rtrim((string) $baseUrl, '/');
define('BASE_URL', $baseUrl);
