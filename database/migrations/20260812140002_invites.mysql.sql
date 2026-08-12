-- Stead Phase 8: invite tokens (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_invites_token_hash (token_hash),
    KEY idx_invites_email (email),
    KEY idx_invites_role (role_id),
    KEY idx_invites_expires (expires_at),
    CONSTRAINT fk_invites_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invites_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
