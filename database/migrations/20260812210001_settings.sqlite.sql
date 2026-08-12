-- Stead Phase 13: site settings (single-row config, SQLite)

CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_name TEXT NOT NULL DEFAULT '',
    timezone TEXT NOT NULL DEFAULT 'UTC',
    date_format TEXT NOT NULL DEFAULT 'Y-m-d',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT INTO settings (id, site_name, timezone, date_format, created_at, updated_at)
VALUES (1, '', 'UTC', 'Y-m-d', '1970-01-01 00:00:00', '1970-01-01 00:00:00');