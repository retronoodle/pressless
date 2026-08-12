-- Stead Phase 9: login attempt throttling (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    cleared_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email_created (email, created_at),
    KEY idx_login_attempts_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
