-- Pressless Phase 2: schema-change bookkeeping table (SQLite)

CREATE TABLE IF NOT EXISTS schema_change_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    previous_field_set_hash TEXT NULL,
    new_field_set_hash TEXT NOT NULL,
    applied_at TEXT NOT NULL,
    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uniq_schema_change_log_collection_hash
    ON schema_change_log (collection_id, new_field_set_hash);

CREATE INDEX idx_schema_change_log_applied_at
    ON schema_change_log (applied_at);
