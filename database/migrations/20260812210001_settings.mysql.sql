-- Stead Phase 13: site settings (single-row config, MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_name VARCHAR(190) NOT NULL DEFAULT '',
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    date_format VARCHAR(64) NOT NULL DEFAULT 'Y-m-d',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (id, site_name, timezone, date_format, created_at, updated_at)
VALUES (1, '', 'UTC', 'Y-m-d', '1970-01-01 00:00:00', '1970-01-01 00:00:00');