-- Theme settings: key-value config declared in theme.json, keyed by theme slug (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS theme_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    theme_slug VARCHAR(190) NOT NULL,
    setting_key VARCHAR(190) NOT NULL,
    value TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_theme_settings_slug_key (theme_slug, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
