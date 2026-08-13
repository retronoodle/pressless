-- Site-wide homepage type override (SQLite).
-- NULL means "use the active theme's default homepage_type"; the only
-- currently-recognised override is 'static_page', which is paired with
-- a non-NULL `homepage_page_id` referencing an entry.

ALTER TABLE settings ADD COLUMN homepage_type TEXT NULL DEFAULT NULL;
ALTER TABLE settings ADD COLUMN homepage_page_id INTEGER NULL DEFAULT NULL;
