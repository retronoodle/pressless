-- Stead Phase 13: entry SEO columns (MySQL/MariaDB)

ALTER TABLE entries
    ADD COLUMN meta_title VARCHAR(255) NULL,
    ADD COLUMN meta_description TEXT NULL,
    ADD COLUMN og_image_id BIGINT UNSIGNED NULL,
    ADD KEY idx_entries_og_image (og_image_id),
    ADD CONSTRAINT fk_entries_og_image FOREIGN KEY (og_image_id) REFERENCES media(id) ON DELETE SET NULL;