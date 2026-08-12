-- Stead Phase 7: roles + permissions (SQLite)

CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin');
INSERT OR IGNORE INTO roles (id, name) VALUES (2, 'editor');
INSERT OR IGNORE INTO roles (id, name) VALUES (3, 'author');

CREATE TABLE IF NOT EXISTS permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_id INTEGER NOT NULL,
    collection_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    UNIQUE (role_id, collection_id, action),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_permissions_role ON permissions (role_id);
CREATE INDEX IF NOT EXISTS idx_permissions_collection ON permissions (collection_id);

ALTER TABLE users ADD COLUMN role_id INTEGER NULL;

UPDATE users SET role_id = 1 WHERE is_admin = 1;
UPDATE users SET role_id = 2 WHERE is_admin = 0;

ALTER TABLE users DROP COLUMN is_admin;
