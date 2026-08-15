-- Site-wide homepage collection override for the `blog` homepage type (MySQL/MariaDB).
-- NULL means the renderer has no blog override to use; the only currently-recognised
-- override paired with `homepage_type = 'blog'` is a non-NULL `homepage_collection_id`
-- referencing a collection.

ALTER TABLE settings
    ADD COLUMN homepage_collection_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER homepage_page_id;