<?php
declare(strict_types=1);

// Dynamic XML sitemap for Bookshelf.
// Exposed as /api/sitemap.php and rewritten as /sitemap.xml via .htaccess.

require_once __DIR__ . '/../includes/seo.php';

header('Content-Type: application/xml; charset=UTF-8');

$staticPages = [
    ['loc' => seo_absolute('index.html'), 'priority' => '1.0', 'changefreq' => 'daily'],
];

$books = [];
try {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/functions.php';
    $db = Database::connection();
    $stmt = $db->query('SELECT id, title, author, cover_image, created_at FROM books ORDER BY created_at DESC, id DESC LIMIT 5000');
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as $row) {
        $books[] = $row;
    }
} catch (Throwable $e) {
    $books = [];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

// Static pages.
foreach ($staticPages as $page) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($page['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    echo '    <changefreq>' . $page['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $page['priority'] . "</priority>\n";
    echo "  </url>\n";
}

// Book pages with friendly URLs.
foreach ($books as $book) {
    $id = (int) ($book['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $loc = seo_book_url($book);
    $lastmod = '';
    if (!empty($book['created_at'])) {
        $ts = strtotime((string) $book['created_at']);
        if ($ts) {
            $lastmod = gmdate('Y-m-d\TH:i:s\Z', $ts);
        }
    }
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
    if ($lastmod !== '') {
        echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    }
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    if (!empty($book['cover_image'])) {
        $img = seo_absolute(ltrim((string) $book['cover_image'], '/'));
        $caption = trim((string) ($book['title'] ?? '') . ' by ' . ($book['author'] ?? ''));
        echo "    <image:image>\n";
        echo '      <image:loc>' . htmlspecialchars($img, ENT_XML1, 'UTF-8') . "</image:loc>\n";
        if ($caption !== 'by') {
            echo '      <image:caption>' . htmlspecialchars($caption, ENT_XML1, 'UTF-8') . "</image:caption>\n";
        }
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}

echo '</urlset>';
