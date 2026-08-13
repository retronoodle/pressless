-- Uploadable themes: installed themes and the active-theme flag (SQLite)

CREATE TABLE IF NOT EXISTS themes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL,
    name TEXT NOT NULL,
    version TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT '',
    is_active INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX uniq_themes_slug ON themes (slug);

INSERT INTO themes (slug, name, version, author, is_active, created_at, updated_at)
VALUES ('starter', 'Starter', '', '', 1, '1970-01-01 00:00:00', '1970-01-01 00:00:00');
