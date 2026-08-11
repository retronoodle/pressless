-- Stead Phase 2: schema-change bookkeeping table (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS schema_change_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id BIGINT UNSIGNED NOT NULL,
    previous_field_set_hash VARCHAR(40) NULL,
    new_field_set_hash VARCHAR(40) NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_schema_change_log_collection_hash (collection_id, new_field_set_hash),
    KEY idx_schema_change_log_applied_at (applied_at),
    CONSTRAINT fk_schema_change_log_collection
        FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
