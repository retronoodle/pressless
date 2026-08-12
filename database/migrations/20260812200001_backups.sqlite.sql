-- Stead backups: backup run tracking (SQLite)

CREATE TABLE IF NOT EXISTS backups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target TEXT NOT NULL,
    storage_key TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    triggered_by TEXT NOT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_backups_target_created ON backups (target, created_at);
CREATE INDEX idx_backups_status ON backups (status);
