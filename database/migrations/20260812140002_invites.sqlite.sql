-- Stead Phase 8: invite tokens (SQLite)

CREATE TABLE IF NOT EXISTS invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    role_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    accepted_at TEXT NULL,
    revoked_at TEXT NULL,
    created_by INTEGER NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX uniq_invites_token_hash ON invites (token_hash);
CREATE INDEX idx_invites_email ON invites (email);
CREATE INDEX idx_invites_role ON invites (role_id);
CREATE INDEX idx_invites_expires ON invites (expires_at);
