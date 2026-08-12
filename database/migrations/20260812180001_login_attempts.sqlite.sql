-- Stead Phase 9: login attempt throttling (SQLite)

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    succeeded INTEGER NOT NULL DEFAULT 0,
    cleared_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_login_attempts_email_created ON login_attempts (email, created_at);
CREATE INDEX idx_login_attempts_ip_created ON login_attempts (ip_address, created_at);
