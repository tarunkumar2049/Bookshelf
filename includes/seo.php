<?php
declare(strict_types=1);

/**
 * Shared SEO helpers for Bookshelf.
 * Works when included from .html files (parsed as PHP via .htaccess).
 */

if (!defined('SEO_BOOTSTRAPPED')) {
    define('SEO_BOOTSTRAPPED', true);
    define('SEO_SITE_NAME', 'Bookshelf');
    define('SEO_DEFAULT_DESCRIPTION', 'Bookshelf — discover fresh reads, popular books and recommendations. Search by title, author or genre and read in the browser.');
    define('SEO_TWITTER_HANDLE', '');
}

if (!function_exists('seo_origin')) {
    function seo_origin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}

if (!function_exists('seo_base_path')) {
    function seo_base_path(): string
    {
        if (defined('BASE_URL')) {
            return (string) BASE_URL;
        }
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = (string) preg_replace('#/api(?:/admin)?$#', '', $dir);
        $dir = (string) preg_replace('#/admin$#', '', $dir);
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            return '';
        }
        return rtrim($dir, '/');
    }
}

if (!function_exists('seo_absolute')) {
    function seo_absolute(string $path = ''): string
    {
        $base = seo_base_path();
        $path = '/' . ltrim($path, '/');
        if ($base !== '' && strpos($path, $base . '/') !== 0 && $path !== $base) {
            $path = $base . $path;
        }
        return seo_origin() . $path;
    }
}

if (!function_exists('seo_slugify')) {
    function seo_slugify(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        // Transliterate to ASCII when possible.
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($trans) && $trans !== '') {
                $text = $trans;
            }
        }
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string) $text, '-');
        return $text !== '' ? substr($text, 0, 80) : 'book';
    }
}

if (!function_exists('seo_book_path')) {
    function seo_book_path(array $book): string
    {
        $id = (int) ($book['id'] ?? 0);
        $slug = seo_slugify((string) ($book['title'] ?? 'book'));
        return 'book/' . $slug . '-' . $id;
    }
}

if (!function_exists('seo_book_url')) {
    function seo_book_url(array $book): string
    {
        return seo_absolute(seo_book_path($book));
    }
}

if (!function_exists('seo_escape')) {
    function seo_escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('seo_trim_description')) {
    function seo_trim_description(?string $text, int $max = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text ?? '')));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max - 40) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut) . '…';
    }
}
