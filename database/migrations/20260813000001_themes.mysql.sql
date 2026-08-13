-- Uploadable themes: installed themes and the active-theme flag (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS themes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    version VARCHAR(64) NOT NULL DEFAULT '',
    author VARCHAR(190) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_themes_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO themes (slug, name, version, author, is_active, created_at, updated_at)
VALUES ('starter', 'Starter', '', '', 1, '1970-01-01 00:00:00', '1970-01-01 00:00:00');
