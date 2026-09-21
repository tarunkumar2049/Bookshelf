CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    window_start INT UNSIGNED NOT NULL,
    UNIQUE KEY unique_identifier (identifier),
    KEY idx_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clean up expired rate limit rows (run periodically via cron or on each request)
-- DELETE FROM rate_limits WHERE window_start < UNIX_TIMESTAMP() - 3600;
