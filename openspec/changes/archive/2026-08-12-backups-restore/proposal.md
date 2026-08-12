## Why

Stead currently has no way to recover from a bad edit, a failed update, or host-level data loss short of manual `mysqldump`/file-copy work — the exact operational fragility (§1) the product exists to remove. Non-technical end users need scheduled, automatic backups and a restore path that doesn't require a terminal.

## What Changes

- Add `bin/backup` CLI command: dumps the DB and copies the media directory to a target (local path by default).
- DB dump covers plugin tables automatically (they share the core DB and use the `plugin_{slug}_*` prefix per Phase 15); the `plugins/` directory (plugin code) is explicitly out of scope for v1.
- Add an S3-compatible remote storage target as a config option, alongside local.
- Add scheduled backup admin UI: frequency, retention count, storage target.
- Add restore flow (admin UI + CLI): select a backup, confirm, restore DB + media.
- Auto-trigger a backup immediately before an update is applied (hooks into the Phase 11 update flow), giving a failed update a rollback path.
- Retention pruning: oldest backups beyond the configured retention count are deleted automatically after each run.

## Capabilities

### New Capabilities
- `backups`: scheduled/manual DB + media backup creation, configurable storage targets (local, S3-compatible), retention pruning, and a restore flow (CLI + admin UI) that reinstates DB and media from a selected backup.

### Modified Capabilities
- `update-notifications`: the manual-update-instructions flow gains a pre-update backup trigger, so a backup exists before the admin follows the download/extract steps.

## Impact

- New `bin/backup` and `bin/restore` (or a `backup:restore` subcommand) CLI commands.
- New DB table to track backup runs (target, timestamp, size, status).
- New admin UI screens: backup settings (schedule/retention/target) and restore.
- New config keys for storage target (local path vs. S3 credentials/bucket/region).
- Touches the update-apply code path from Phase 11 to invoke a backup before proceeding.
- Depends on `mysqldump`/equivalent being available in the host's `PATH`, or a PDO-based fallback if not — needs a decision in design.md.
