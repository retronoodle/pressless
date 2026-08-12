-- Stead Phase 13: entry SEO columns (SQLite)

ALTER TABLE entries ADD COLUMN meta_title TEXT NULL;
ALTER TABLE entries ADD COLUMN meta_description TEXT NULL;
ALTER TABLE entries ADD COLUMN og_image_id INTEGER NULL;
CREATE INDEX idx_entries_og_image ON entries (og_image_id);