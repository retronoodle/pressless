-- Stead backups: backup run tracking (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS backups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target VARCHAR(16) NOT NULL,
    storage_key VARCHAR(512) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL,
    triggered_by VARCHAR(16) NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_backups_target_created (target, created_at),
    KEY idx_backups_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
