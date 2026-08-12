-- Stead Phase 7: roles + permissions (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(32) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (id, name) VALUES (1, 'admin');
INSERT IGNORE INTO roles (id, name) VALUES (2, 'editor');
INSERT IGNORE INTO roles (id, name) VALUES (3, 'author');

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id BIGINT UNSIGNED NOT NULL,
    collection_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_permissions_role_collection_action (role_id, collection_id, action),
    KEY idx_permissions_role (role_id),
    KEY idx_permissions_collection (collection_id),
    CONSTRAINT fk_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_permissions_collection FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN role_id BIGINT UNSIGNED NULL;

UPDATE users SET role_id = 1 WHERE is_admin = 1;
UPDATE users SET role_id = 2 WHERE is_admin = 0;

ALTER TABLE users DROP COLUMN is_admin;

ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT;
