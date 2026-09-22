-- Book request feature: users request title+author, admin fulfills on upload.
CREATE TABLE IF NOT EXISTS book_requests (
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
) ENGINE=InnoDB;
