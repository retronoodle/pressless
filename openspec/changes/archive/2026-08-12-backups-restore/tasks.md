## 1. Config & schema

- [x] 1.1 Add `backups:` config section to `config/app.yaml` (target, local path, S3 credentials/bucket/region, retention_count, frequency)
- [x] 1.2 Write migration for `backups` table (target, path/key, size_bytes, status, created_at, triggered_by)

## 2. Backup creation

- [x] 2.1 Build DB dump: shell out to `mysqldump` when available
- [x] 2.2 Build PDO-based fallback dumper for when `mysqldump` is unavailable
- [x] 2.3 Build SQLite backup path (file copy) for dev
- [x] 2.4 Build archive builder: bundle DB dump + media directory copy + `manifest.json` into one archive
- [x] 2.5 Build `LocalStorageTarget` (write archive to configured local path, outside `public/`)
- [x] 2.6 Build `S3StorageTarget` (signed PUT/GET/DELETE/LIST against S3-compatible endpoint)
- [x] 2.7 Build `bin/backup` command: run a backup, record a `backups` row, support `--scheduled` (checks configured frequency before running)
- [x] 2.8 Implement retention pruning: delete oldest backups beyond configured retention count from storage target + `backups` table

## 3. Restore

- [x] 3.1 Build restore: replay DB dump against configured connection (SQLite: transactional; MySQL: document non-atomicity per design.md)
- [x] 3.2 Build restore: replace media directory contents from archive
- [x] 3.3 Build `bin/backup:restore <id>` CLI command with explicit confirmation step

## 4. Admin UI

- [x] 4.1 Build backup settings UI (frequency, retention count, storage target) + document required cron entry
- [x] 4.2 Build backup history list UI (status, timestamp, size, trigger source)
- [x] 4.3 Build restore UI (select backup, confirm, trigger restore, show result)

## 5. Update-flow integration

- [x] 5.1 Trigger a backup when the admin reaches the update-instructions view; block instructions and show the failure if the backup fails

## 6. Verification

- [x] 6.1 Smoke test: create backup → wipe DB → restore → confirm site state matches
- [x] 6.2 Smoke test: exceed retention count → confirm oldest backups pruned, not accumulating unbounded
- [x] 6.3 Smoke test: trigger update-available notice → confirm pre-update backup runs before instructions are shown
