<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

function get_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check and record a rate limit hit.
 *
 * @param string $key       Identifier for the action (e.g. "admin_login", "otp_verify")
 * @param int    $maxAttempts Maximum attempts allowed in the window
 * @param int    $windowSecs  Time window in seconds
 * @return array{allowed: bool, remaining: int, retry_after: int}
 */
function check_rate_limit(string $key, int $maxAttempts = 5, int $windowSecs = 300): array
{
    $db = Database::connection();
    $ip = get_client_ip();
    $identifier = $key . ':' . $ip;

    $stmt = $db->prepare(
        'SELECT id, attempts, window_start FROM rate_limits WHERE identifier = :id LIMIT 1'
    );
    $stmt->execute(['id' => $identifier]);
    $row = $stmt->fetch();

    $now = time();

    if (!$row) {
        $db->prepare('INSERT INTO rate_limits (identifier, attempts, window_start) VALUES (:id, 1, :ws)')
           ->execute(['id' => $identifier, 'ws' => $now]);
        return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
    }

    $windowStart = (int) $row['window_start'];
    $attempts = (int) $row['attempts'];

    if ($now - $windowStart > $windowSecs) {
        $db->prepare('UPDATE rate_limits SET attempts = 1, window_start = :ws WHERE identifier = :id')
           ->execute(['ws' => $now, 'id' => $identifier]);
        return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
    }

    if ($attempts >= $maxAttempts) {
        $retryAfter = $windowSecs - ($now - $windowStart);
        return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
    }

    $db->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE identifier = :id')
       ->execute(['id' => $identifier]);
    return ['allowed' => true, 'remaining' => $maxAttempts - $attempts - 1, 'retry_after' => 0];
}

function enforce_rate_limit(string $key, int $maxAttempts = 5, int $windowSecs = 300): void
{
    $result = check_rate_limit($key, $maxAttempts, $windowSecs);
    if (!$result['allowed']) {
        header('Retry-After: ' . $result['retry_after']);
        json_error('Too many requests. Try again in ' . $result['retry_after'] . ' seconds.', 429);
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function ensure_book_request_tables(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS book_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) NOT NULL,
            status ENUM('pending','fulfilled','rejected') NOT NULL DEFAULT 'pending',
            fulfilled_book_id INT UNSIGNED DEFAULT NULL,
            seen_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fulfilled_at DATETIME DEFAULT NULL,
            CONSTRAINT fk_book_requests_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_book_requests_book
                FOREIGN KEY (fulfilled_book_id) REFERENCES books(id)
                ON DELETE SET NULL,
            INDEX idx_book_requests_user (user_id),
            INDEX idx_book_requests_status (status)
        ) ENGINE=InnoDB"
    );

    // Best-effort: add columns when table pre-existed from an older migration.
    foreach (['fulfilled_book_id', 'seen_at', 'fulfilled_at'] as $column) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM book_requests LIKE '" . $column . "'")->fetch();
            if (!$exists) {
                if ($column === 'fulfilled_book_id') {
                    $db->exec('ALTER TABLE book_requests ADD COLUMN fulfilled_book_id INT UNSIGNED DEFAULT NULL');
                } elseif ($column === 'seen_at') {
                    $db->exec('ALTER TABLE book_requests ADD COLUMN seen_at DATETIME DEFAULT NULL');
                } else {
                    $db->exec('ALTER TABLE book_requests ADD COLUMN fulfilled_at DATETIME DEFAULT NULL');
                }
            }
        } catch (Throwable $ignored) {
        }
    }
}

/**
 * Mark pending requests as fulfilled when a book with matching title+author is uploaded.
 * Matching is case-insensitive on trimmed values.
 */
function fulfill_matching_book_requests(PDO $db, string $title, string $author, int $bookId): int
{
    ensure_book_request_tables($db);
    $stmt = $db->prepare(
        "UPDATE book_requests
         SET status = 'fulfilled', fulfilled_book_id = :book_id, fulfilled_at = NOW()
         WHERE status = 'pending'
           AND LOWER(TRIM(title)) = LOWER(TRIM(:title))
           AND LOWER(TRIM(author)) = LOWER(TRIM(:author))"
    );
    $stmt->execute([
        'book_id' => $bookId,
        'title' => $title,
        'author' => $author,
    ]);
    return (int) $stmt->rowCount();
}

function ensure_custom_section_tables(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS custom_sections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            subtitle VARCHAR(255) NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $hasSubtitle = (bool) $db->query("SHOW COLUMNS FROM custom_sections LIKE 'subtitle'")->fetch();
    if (!$hasSubtitle) {
        $db->exec('ALTER TABLE custom_sections ADD COLUMN subtitle VARCHAR(255) NULL DEFAULT NULL AFTER title');
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS custom_section_books (
            section_id INT UNSIGNED NOT NULL,
            book_id INT UNSIGNED NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (section_id, book_id),
            CONSTRAINT fk_section_books_section FOREIGN KEY (section_id) REFERENCES custom_sections (id) ON DELETE CASCADE,
            CONSTRAINT fk_section_books_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
        )'
    );
}

function get_custom_sections(PDO $db, bool $onlyNonEmpty = true): array
{
    ensure_custom_section_tables($db);
    $sections = $db->query('SELECT id, title, subtitle FROM custom_sections ORDER BY id ASC')->fetchAll();
    foreach ($sections as &$section) {
        $stmt = $db->prepare(
            'SELECT books.id, books.title, books.author, books.cover_image, books.pdf_file
             FROM custom_section_books
             INNER JOIN books ON books.id = custom_section_books.book_id
             WHERE custom_section_books.section_id = :section_id
             ORDER BY custom_section_books.sort_order ASC, books.title ASC'
        );
        $stmt->execute(['section_id' => (int) $section['id']]);
        $section['books'] = decorate_books_with_genres($db, $stmt->fetchAll());
    }
    unset($section);

    if ($onlyNonEmpty) {
        $sections = array_values(array_filter($sections, static fn ($section) => !empty($section['books'])));
    }

    return $sections;
}

function get_genres(PDO $db): array
{
    return $db->query('SELECT id, name, slug FROM genres ORDER BY name ASC')->fetchAll();
}

function get_book_genres(PDO $db, int $bookId): array
{
    $stmt = $db->prepare(
        'SELECT genres.id, genres.name, genres.slug
         FROM genres
         INNER JOIN book_genres ON book_genres.genre_id = genres.id
         WHERE book_genres.book_id = :book_id
         ORDER BY genres.name ASC'
    );
    $stmt->execute(['book_id' => $bookId]);

    return $stmt->fetchAll();
}

function decorate_books_with_genres(PDO $db, array $books): array
{
    if (!$books) {
        return [];
    }

    $ids = array_map(static fn ($book) => (int) $book['id'], $books);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT book_genres.book_id, genres.id, genres.name, genres.slug
         FROM book_genres
         INNER JOIN genres ON genres.id = book_genres.genre_id
         WHERE book_genres.book_id IN ($placeholders)
         ORDER BY genres.name ASC"
    );
    $stmt->execute($ids);

    $genresByBook = [];
    foreach ($stmt->fetchAll() as $genre) {
        $genresByBook[(int) $genre['book_id']][] = [
            'id' => (int) $genre['id'],
            'name' => $genre['name'],
            'slug' => $genre['slug'],
        ];
    }

    foreach ($books as &$book) {
        $book['genres'] = $genresByBook[(int) $book['id']] ?? [];
    }

    return $books;
}

function sync_book_genres(PDO $db, int $bookId, array $genreIds): void
{
    $genreIds = array_values(array_unique(array_filter(array_map('intval', $genreIds), static fn ($id) => $id > 0)));

    $deleteStmt = $db->prepare('DELETE FROM book_genres WHERE book_id = :book_id');
    $deleteStmt->execute(['book_id' => $bookId]);

    if (!$genreIds) {
        return;
    }

    $insertStmt = $db->prepare('INSERT IGNORE INTO book_genres (book_id, genre_id) VALUES (:book_id, :genre_id)');
    foreach ($genreIds as $genreId) {
        $insertStmt->execute(['book_id' => $bookId, 'genre_id' => $genreId]);
    }
}

function get_books(PDO $db, string $query = '', int $page = 1, int $perPage = 12, int $genreId = 0, bool $popularOnly = false, string $order = 'recent'): array
{
    $offset = max(0, ($page - 1) * $perPage);
    $joins = '';
    $whereParts = [];
    $params = [];

    if ($query !== '') {
        $whereParts[] = '(books.title LIKE :title_query OR books.author LIKE :author_query)';
        $params['title_query'] = '%' . $query . '%';
        $params['author_query'] = '%' . $query . '%';
    }

    if ($genreId > 0) {
        $joins .= ' INNER JOIN book_genres filter_genres ON filter_genres.book_id = books.id';
        $whereParts[] = 'filter_genres.genre_id = :genre_id';
        $params['genre_id'] = $genreId;
    }

    if ($popularOnly) {
        $whereParts[] = 'books.is_popular = 1';
    }

    $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
    $orderSql = $order === 'random' ? 'RAND()' : 'books.created_at DESC, books.id DESC';

    $countStmt = $db->prepare("SELECT COUNT(DISTINCT books.id) FROM books $joins $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT DISTINCT books.id, books.title, books.author, books.description, books.cover_image, books.pdf_file, books.is_popular, books.created_at
            FROM books
            $joins
            $where
            ORDER BY $orderSql
            LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'books' => decorate_books_with_genres($db, $stmt->fetchAll()),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $perPage)),
    ];
}

function get_book(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM books WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $book = $stmt->fetch();

    return $book ?: null;
}

function get_chapters_for_book(PDO $db, int $bookId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM chapters WHERE book_id = :book_id ORDER BY chapter_number ASC, id ASC'
    );
    $stmt->execute(['book_id' => $bookId]);

    return $stmt->fetchAll();
}

function get_all_books(PDO $db): array
{
    return decorate_books_with_genres(
        $db,
        $db->query('SELECT id, title, author, description, cover_image, pdf_file, is_popular, created_at FROM books ORDER BY title ASC')->fetchAll()
    );
}

function get_recommended_books_for_book(PDO $db, int $bookId, int $limit = 10): array
{
    $stmt = $db->prepare(
        'SELECT DISTINCT books.id, books.title, books.author, books.description, books.cover_image, books.pdf_file, books.is_popular, books.created_at
         FROM book_genres selected_genres
         INNER JOIN book_genres related_genres ON related_genres.genre_id = selected_genres.genre_id
         INNER JOIN books ON books.id = related_genres.book_id
         WHERE selected_genres.book_id = :selected_book_id
            AND books.id <> :excluded_book_id
         ORDER BY books.created_at DESC, books.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':selected_book_id', $bookId, PDO::PARAM_INT);
    $stmt->bindValue(':excluded_book_id', $bookId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $books = $stmt->fetchAll();

    if (!$books) {
        $fallback = get_books($db, '', 1, $limit, 0, false, 'random');
        return array_values(array_filter($fallback['books'], static fn ($book) => (int) $book['id'] !== $bookId));
    }

    return decorate_books_with_genres($db, $books);
}

function get_recommended_books_for_user(PDO $db, ?array $user, int $limit = 12): array
{
    if (!$user) {
        return get_books($db, '', 1, $limit, 0, false, 'random')['books'];
    }

    $stmt = $db->prepare(
        'SELECT DISTINCT books.id, books.title, books.author, books.description, books.cover_image, books.pdf_file, books.is_popular, books.created_at, reading_history.last_read_at
         FROM reading_history
         INNER JOIN book_genres history_genres ON history_genres.book_id = reading_history.book_id
         INNER JOIN book_genres recommended_genres ON recommended_genres.genre_id = history_genres.genre_id
         INNER JOIN books ON books.id = recommended_genres.book_id
         LEFT JOIN reading_history already_read
            ON already_read.book_id = books.id AND already_read.user_id = :already_read_user_id
         WHERE reading_history.user_id = :history_user_id
            AND already_read.id IS NULL
         ORDER BY reading_history.last_read_at DESC, books.created_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':already_read_user_id', (int) $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':history_user_id', (int) $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $books = $stmt->fetchAll();

    if (!$books) {
        return get_books($db, '', 1, $limit, 0, false, 'random')['books'];
    }

    return decorate_books_with_genres($db, $books);
}

function get_all_chapters(PDO $db): array
{
    return $db->query(
        'SELECT chapters.id, chapters.title, chapters.chapter_number, books.title AS book_title
         FROM chapters
         INNER JOIN books ON books.id = chapters.book_id
         ORDER BY books.title ASC, chapters.chapter_number ASC'
    )->fetchAll();
}

function save_uploaded_image(array $file, string $folder): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please choose a valid file.');
    }

    if (($file['size'] ?? 0) > MAX_IMAGE_SIZE) {
        throw new RuntimeException('Image is too large. Maximum size is 10 MB.');
    }

    $tmpPath = $file['tmp_name'] ?? '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are allowed.');
    }

    $targetDir = rtrim(UPLOAD_ROOT . '/' . trim($folder, '/'), '/');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $absolutePath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        throw new RuntimeException('Could not save uploaded image.');
    }

    return 'uploads/' . trim($folder, '/') . '/' . $filename;
}

function save_uploaded_pdf(array $file, string $folder): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('PDF upload failed. Please choose a valid file.');
    }

    if (($file['size'] ?? 0) > MAX_PDF_SIZE) {
        throw new RuntimeException('PDF is too large. Maximum size is 60 MB.');
    }

    $tmpPath = $file['tmp_name'] ?? '';
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    $header = file_get_contents($tmpPath, false, null, 0, 4);

    if ($extension !== 'pdf' || $header !== '%PDF' || !in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
        throw new RuntimeException('Only valid PDF files are allowed.');
    }

    $targetDir = rtrim(UPLOAD_ROOT . '/' . trim($folder, '/'), '/');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true)) {
        throw new RuntimeException('Could not create PDF upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.pdf';
    $absolutePath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        throw new RuntimeException('Could not save uploaded PDF.');
    }

    return 'uploads/' . trim($folder, '/') . '/' . $filename;
}

function save_text_page(string $content, int $chapterId, int $pageNumber): string
{
    $content = trim($content);
    if ($content === '') {
        throw new RuntimeException('Text content cannot be empty.');
    }

    $folder = 'chapters/chapter_' . $chapterId;
    $targetDir = UPLOAD_ROOT . '/' . $folder;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true)) {
        throw new RuntimeException('Could not create text upload directory.');
    }

    $filename = 'page_' . $pageNumber . '_' . bin2hex(random_bytes(8)) . '.txt';
    $absolutePath = $targetDir . '/' . $filename;
    if (file_put_contents($absolutePath, $content) === false) {
        throw new RuntimeException('Could not save text page.');
    }

    return 'uploads/' . $folder . '/' . $filename;
}

function delete_uploaded_file_if_safe(?string $relativePath): void
{
    if (!$relativePath || strpos($relativePath, 'uploads/') !== 0) {
        return;
    }

    $absolutePath = realpath(__DIR__ . '/../' . $relativePath);
    $uploadRoot = realpath(UPLOAD_ROOT);

    if ($absolutePath === false || $uploadRoot === false) {
        return;
    }

    if (strpos($absolutePath, $uploadRoot) === 0 && is_file($absolutePath)) {
        unlink($absolutePath);
    }
}

function create_reader_otp(PDO $db, string $email): string
{
    $otp = (string) random_int(100000, 999999);

    $expires = date(
        'Y-m-d H:i:s',
        strtotime('+10 minutes')
    );

    $stmt = $db->prepare(
        'UPDATE users
         SET otp_code_hash = :hash,
             otp_expires_at = :expires
         WHERE email = :email'
    );

    $stmt->execute([
        'hash' => password_hash($otp, PASSWORD_DEFAULT),
        'expires' => $expires,
        'email' => $email,
    ]);

    return $otp;
}

function send_book_request_notification(string $adminEmail, string $userEmail, string $title, string $author): bool
{
    $mail = null;
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();

        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $smtpUser = trim((string) ($_ENV['SMTP_USERNAME'] ?? ''));
        $smtpPass = str_replace(' ', '', trim((string) ($_ENV['SMTP_PASSWORD'] ?? '')));

        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $smtpUser !== '' ? $smtpUser : 'library.of.bookshelf@gmail.com',
            'Bookshelf Team'
        );

        $mail->addAddress($adminEmail);

        $mail->isHTML(true);

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeAuthor = htmlspecialchars($author, ENT_QUOTES, 'UTF-8');
        $safeUser = htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8');

        $mail->Subject = 'New book request: ' . $title;

        $mail->Body = "
        <div style='font-family:Arial,sans-serif'>
            <h2>New Book Request</h2>
            <p>A reader just requested a book:</p>
            <p><strong>Title:</strong> {$safeTitle}<br>
            <strong>Author:</strong> {$safeAuthor}<br>
            <strong>Requested by:</strong> {$safeUser}</p>
            <p>Review it in the admin dashboard under Requests.</p>
        </div>";

        $mail->AltBody = "New book request - Title: {$title}, Author: {$author}, Requested by: {$userEmail}";

        return $mail->send();

    } catch (\Throwable $e) {
        error_log('Book request email failed: ' . $e->getMessage() . ' | ' . ($mail instanceof PHPMailer ? $mail->ErrorInfo : 'mailer not ready'));
        return false;
    }
}

function send_reader_otp(string $email, string $otp): bool
{
    $mail = null;
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();

        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $smtpUser = trim((string) ($_ENV['SMTP_USERNAME'] ?? ''));
        $smtpPass = str_replace(' ', '', trim((string) ($_ENV['SMTP_PASSWORD'] ?? '')));

        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            $smtpUser !== '' ? $smtpUser : 'library.of.bookshelf@gmail.com',
            'Bookshelf Team'
        );

        $mail->addAddress($email);

        $mail->isHTML(true);

        $mail->Subject = 'Bookshelf Verification Code';

        $mail->Body = "
        <div style='font-family:Arial,sans-serif'>
            <h2>Bookshelf Email Verification</h2>
            <p>Your verification code is:</p>

            <div style='font-size:30px;font-weight:bold'>
                {$otp}
            </div>

            <p>This code expires in 10 minutes.</p>
        </div>";

        $mail->AltBody = "Your Bookshelf verification code is: {$otp}. This code expires in 10 minutes.";

        return $mail->send();

    } catch (\Throwable $e) {
        error_log('OTP send failed: ' . $e->getMessage() . ' | ' . ($mail instanceof PHPMailer ? $mail->ErrorInfo : 'mailer not ready'));
        return false;
    }
}
