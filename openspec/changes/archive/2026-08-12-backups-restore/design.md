## Context

Stead has no backup/restore path today. The DB layer supports MySQL/MariaDB (prod) and SQLite (dev, per `database-foundations`). `bin/*` commands use `symfony/console`. Config is layered YAML (`config/app.yaml`) + `.env`, resolved via the `configuration` capability. Phase 11 (`update-checker`/`update-notifications`) added a manual update-prompt flow — the admin views instructions and downloads/extracts a ZIP themselves, with no rollback path. This change closes that gap by triggering a backup as soon as the admin reaches those instructions.

Plugin tables (Phase 15+, not yet built) will live in the same core DB under a `plugin_{slug}_*` prefix, so a full DB dump captures them automatically with no plugin-aware code needed. Plugin *code* under `plugins/` is out of scope for v1 backups.

## Goals / Non-Goals

**Goals:**
- `bin/backup` produces a restorable snapshot (DB dump + media directory) to a configured target.
- Support local filesystem and S3-compatible remote targets.
- Scheduled backups configurable from the admin UI (frequency, retention count).
- Restore flow (CLI + admin UI) that reinstates DB and media from a selected backup, with an explicit confirm step.
- Pre-update backup: the update-apply step (Phase 11) triggers a backup before proceeding.
- Retention pruning: keep only the N most recent backups per target.

**Non-Goals:**
- Backing up `plugins/` directory code (deferred to Phase 20, tracked as an open question there).
- Point-in-time / incremental backups — each backup is a full snapshot.
- Automated rollback on update failure — Phase 11 ships manual update instructions; this change only guarantees a backup exists beforehand, not automatic restore-on-failure.
- Encryption at rest for backup archives (may be revisited later; note as open question).

## Decisions

**DB dump mechanism: shell out to `mysqldump`, PDO-based fallback if unavailable.**
`mysqldump` is present on effectively all cPanel/shared-hosting PHP environments Stead targets (§2 audience) and produces a portable, restorable SQL file with minimal code. Detect it via `shell_exec('which mysqldump')`/`Process` at backup time; if absent, fall back to a PDO-based `SELECT *`-per-table dumper that writes equivalent `INSERT` statements. The fallback is slower and less battle-tested but keeps backups working on hosts without shell access to `mysqldump`. SQLite (dev) backups use a straight file copy of the `.sqlite` file — no dump step needed.

**Backup format: single archive per run, not two files.**
Bundle the DB dump (`dump.sql`) and a copy of the media directory into one `.zip` (or `.tar.gz`) per backup run, alongside a small `manifest.json` (version, timestamp, DB driver, file list, checksums). One artifact per run is simpler to list, retain, transfer to S3, and restore atomically than tracking DB/media as separate paired files.

**Storage targets: local path (default) + S3-compatible via a small `StorageTarget` interface.**
Mirrors the existing driver-selection pattern in `configuration` (`database.connection: mysql|sqlite`). `LocalStorageTarget` writes to a configured directory (default `var/backups`, outside `public/`). `S3StorageTarget` uses a minimal S3-compatible client (signed PUT/GET/DELETE/LIST over HTTP; evaluate a small composer package vs. hand-rolled SigV4 — hand-rolled preferred per §4 "composer only where it earns it" unless the signing code proves substantial). Config carries `backups.target: local|s3` plus target-specific keys, same shape as `database`.

**Scheduling: cron-triggered `bin/backup`, not an in-process scheduler.**
Stead has no background worker process (PHP-FPM/shared hosting reality). The admin UI's "frequency" setting writes a config value that `bin/backup --scheduled` reads to decide whether it's due to run; actual triggering is an external cron entry the installer/docs instruct the operator to add (`* * * * * php bin/backup --scheduled`), consistent with how `bin/serve` vs. real deployment already split dev/prod concerns (§8). The admin UI also documents the cron line needed, rather than trying to manage OS cron itself.

**Backup run tracking: new `backups` table, not filesystem-only.**
A `backups` table (id, target, path/key, size_bytes, status, created_at, triggered_by [manual|scheduled|pre_update]) lets the admin UI list/restore-from without re-listing the storage target on every page load, and lets retention pruning and restore-selection be simple queries. Mirrors the `revisions` table pattern already in the schema.

**Restore: DB dump replay + media directory swap, admin-confirmed, CLI available for when admin UI is inaccessible (failed update).**
Restore replays `dump.sql` against the configured DB (drop/recreate managed tables or `TRUNCATE` + reload — exact mechanism reuses whatever `bin/migrate --fresh` already does for schema reset) and replaces the media directory contents from the archive. CLI restore (`bin/backup:restore <id>`) exists specifically so a broken admin (e.g., failed update leaves admin unreachable) can still be recovered from the command line — this is the actual reason Phase 12 promises a "rollback path" for Phase 11 updates.

## Risks / Trade-offs

- [Risk] `mysqldump` absent and PDO fallback dumps very large tables slowly / with high memory use → Mitigation: stream row-by-row via PDO cursors rather than buffering full result sets; document the performance gap vs. native `mysqldump` in `docs/`.
- [Risk] Restore mid-operation failure leaves DB in a half-restored state → Mitigation: wrap the DB replay in a transaction where the driver supports DDL-in-transaction (SQLite yes; MySQL DDL auto-commits per statement, so document that MySQL restores are not fully atomic and recommend restoring to a fresh DB then swapping, as a documented limitation rather than a false atomicity guarantee).
- [Risk] S3-compatible hand-rolled signing is a security-sensitive area to get wrong → Mitigation: keep the client minimal (PUT/GET/DELETE/LIST only), add tests against a local S3-compatible test double (e.g. MinIO) if available in CI, and document as a `raw`-style escape hatch that credential handling follows §4 config conventions (never logged, `.env`-sourced).
- [Risk] Pre-update backup adds latency/failure surface to the update-instructions page → Mitigation: if the pre-update backup fails, the instructions page shows the failure and does not proceed to display download/extract steps, so the admin isn't nudged toward an update with no safety net (fail closed, matching the login-throttle and update-checker "fail closed" precedent already in this codebase).
- [Trade-off] Full-snapshot-only backups mean large media libraries produce large, slow backups over time → accepted for v1; incremental backups are a plausible future phase, not blocking MVP per §6.

## Migration Plan

- New `backups` table via a core migration (`database/migrations/`).
- New config section `backups:` in `config/app.yaml` (target, retention_count, frequency, S3 credentials via `.env`).
- No changes to existing tables; additive only. No rollback complexity beyond dropping the new table if the change is reverted.

## Open Questions

- Should backup archives be encrypted at rest (particularly for S3 targets holding potentially sensitive media/DB content)? Deferred — flag in `docs/` as a known gap for v1.
- Exact DDL-in-transaction limitation for MySQL restores — worth revisiting if/when a safer restore strategy (blue/green DB swap) is designed.
- Whether `bin/backup` should also back up `plugins/` once Phase 20 ships ZIP-installed plugins (explicitly deferred there per §10 Phase 20 task 3).
