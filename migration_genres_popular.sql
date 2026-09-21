USE if0_42944029_library_db;

ALTER TABLE books
    ADD COLUMN IF NOT EXISTS is_popular TINYINT(1) NOT NULL DEFAULT 0 AFTER pdf_file,
    ADD INDEX IF NOT EXISTS idx_books_popular (is_popular);

CREATE TABLE IF NOT EXISTS genres (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(90) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_genres (
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
