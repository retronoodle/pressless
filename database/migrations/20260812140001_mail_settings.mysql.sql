-- Stead Phase 8: mail settings (single-row config, MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS mail_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    host VARCHAR(255) NOT NULL DEFAULT '',
    port INT UNSIGNED NOT NULL DEFAULT 587,
    encryption VARCHAR(16) NOT NULL DEFAULT 'starttls',
    username VARCHAR(255) NOT NULL DEFAULT '',
    password VARCHAR(255) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mail_settings (id, host, port, encryption, username, password, updated_at)
VALUES (1, '', 587, 'starttls', '', '', '1970-01-01 00:00:00');
