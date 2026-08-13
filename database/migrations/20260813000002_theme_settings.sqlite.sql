-- Theme settings: key-value config declared in theme.json, keyed by theme slug (SQLite)

CREATE TABLE IF NOT EXISTS theme_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    theme_slug TEXT NOT NULL,
    setting_key TEXT NOT NULL,
    value TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_theme_settings_slug_key ON theme_settings (theme_slug, setting_key);
