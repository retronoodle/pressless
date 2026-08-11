-- Stead Phase 2: typed-uniform columns on entry_values (SQLite)
-- Adds one nullable column per phase-2 field kind and a unique key so each
-- (entry, field) pair has at most one row.

ALTER TABLE entry_values ADD COLUMN value_text TEXT NULL;
ALTER TABLE entry_values ADD COLUMN value_number REAL NULL;
ALTER TABLE entry_values ADD COLUMN value_date TEXT NULL;
ALTER TABLE entry_values ADD COLUMN value_bool INTEGER NULL;
ALTER TABLE entry_values ADD COLUMN value_json TEXT NULL;

CREATE UNIQUE INDEX uniq_entry_values_entry_field_key
    ON entry_values (entry_id, field_key);
