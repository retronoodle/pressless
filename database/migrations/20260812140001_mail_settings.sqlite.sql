-- Stead Phase 8: mail settings (single-row config, SQLite)

CREATE TABLE IF NOT EXISTS mail_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host TEXT NOT NULL DEFAULT '',
    port INTEGER NOT NULL DEFAULT 587,
    encryption TEXT NOT NULL DEFAULT 'starttls',
    username TEXT NOT NULL DEFAULT '',
    password TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
);

INSERT INTO mail_settings (id, host, port, encryption, username, password, updated_at)
VALUES (1, '', 587, 'starttls', '', '', '1970-01-01 00:00:00');
