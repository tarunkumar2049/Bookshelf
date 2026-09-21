USE if0_42944029_library_db;

ALTER TABLE books
    ADD COLUMN IF NOT EXISTS pdf_file VARCHAR(255) DEFAULT NULL AFTER cover_image;

CREATE TABLE IF NOT EXISTS reading_history (
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

CREATE TABLE IF NOT EXISTS wishlists (
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
