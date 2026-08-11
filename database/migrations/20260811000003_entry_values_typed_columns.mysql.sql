-- Pressless Phase 2: typed-uniform columns on entry_values (MySQL/MariaDB)
-- Adds one nullable column per phase-2 field kind and a unique key so each
-- (entry, field) pair has at most one row.

ALTER TABLE entry_values
    ADD COLUMN value_text LONGTEXT NULL,
    ADD COLUMN value_number DOUBLE NULL,
    ADD COLUMN value_date DATE NULL,
    ADD COLUMN value_bool TINYINT(1) NULL,
    ADD COLUMN value_json LONGTEXT NULL;

ALTER TABLE entry_values
    ADD UNIQUE KEY uniq_entry_values_entry_field_key (entry_id, field_key);
