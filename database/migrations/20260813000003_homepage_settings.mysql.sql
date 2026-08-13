-- Site-wide homepage type override (MySQL/MariaDB).
-- NULL means "use the active theme's default homepage_type"; the only
-- currently-recognised override is 'static_page', which is paired with
-- a non-NULL `homepage_page_id` referencing an entry.

ALTER TABLE settings
    ADD COLUMN homepage_type VARCHAR(32) NULL DEFAULT NULL AFTER date_format,
    ADD COLUMN homepage_page_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER homepage_type;
