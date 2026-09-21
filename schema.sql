
USE if0_42944029_library_db;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    profile_image VARCHAR(255) DEFAULT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    otp_code_hash VARCHAR(255) DEFAULT NULL,
    otp_expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE books (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    cover_image VARCHAR(255) DEFAULT NULL,
    pdf_file VARCHAR(255) DEFAULT NULL,
    is_popular TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_books_title (title),
    INDEX idx_books_popular (is_popular),
    INDEX idx_books_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE genres (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(90) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE book_genres (
    book_id INT UNSIGNED NOT NULL,
    genre_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (book_id, genre_id),
    CONSTRAINT fk_book_genres_book
        FOREIGN KEY (book_id) REFERENCES books(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_book_genres_genre
        FOREIGN KEY (genre_id) REFERENCES genres(id)
        ON DELETE CASCADE,
    INDEX idx_book_genres_genre_id (genre_id)
) ENGINE=InnoDB;

CREATE TABLE chapters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    chapter_number DECIMAL(8,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chapters_book
        FOREIGN KEY (book_id) REFERENCES books(id)
        ON DELETE CASCADE,
    UNIQUE KEY unique_book_chapter_number (book_id, chapter_number),
    INDEX idx_chapters_book_id (book_id)
) ENGINE=InnoDB;

CREATE TABLE pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chapter_id INT UNSIGNED DEFAULT NULL,
    page_number INT UNSIGNED NOT NULL,
    content_type ENUM('image', 'text') NOT NULL,
    content_path VARCHAR(255) NOT NULL,
    CONSTRAINT fk_pages_chapter
        FOREIGN KEY (chapter_id) REFERENCES chapters(id)
        ON DELETE CASCADE,
    UNIQUE KEY unique_chapter_page_number (chapter_id, page_number),
    INDEX idx_pages_chapter_id (chapter_id)
) ENGINE=InnoDB;

CREATE TABLE reading_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    book_id INT UNSIGNED NOT NULL,
    chapter_id INT UNSIGNED DEFAULT NULL,
    last_read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_history_book
        FOREIGN KEY (book_id) REFERENCES books(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_history_chapter
        FOREIGN KEY (chapter_id) REFERENCES chapters(id)
        ON DELETE CASCADE,
    UNIQUE KEY unique_user_book_history (user_id, book_id),
    INDEX idx_history_user_updated (user_id, last_read_at)
) ENGINE=InnoDB;

CREATE TABLE wishlists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    book_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wishlist_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_book
        FOREIGN KEY (book_id) REFERENCES books(id)
        ON DELETE CASCADE,
    UNIQUE KEY unique_user_book_wishlist (user_id, book_id),
    INDEX idx_wishlist_user_created (user_id, created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO genres (name, slug) VALUES
('Action', 'action'),
('Adventure', 'adventure'),
('Romance', 'romance'),
('Fantasy', 'fantasy'),
('Horror', 'horror'),
('Comedy', 'comedy'),
('Drama', 'drama'),
('Mystery', 'mystery'),
('Sci-Fi', 'sci-fi'),
('Slice of Life', 'slice-of-life'),
('Sports', 'sports'),
('Martial Arts', 'martial-arts'),
('Family', 'family');

-- Create the first admin safely at /setup_admin.html after importing this schema.
