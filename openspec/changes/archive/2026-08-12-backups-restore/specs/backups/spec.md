## ADDED Requirements

### Requirement: Manual backup creation

The system SHALL provide a `bin/backup` CLI command that creates a single backup archive containing a database dump, a copy of the media directory, and a manifest, and writes it to the configured storage target.

#### Scenario: Manual backup run

- **WHEN** an operator runs `bin/backup`
- **THEN** the system dumps the database, copies the media directory, bundles both with a manifest into one archive, uploads/writes it to the configured storage target, and records a `backups` row with status, size, and timestamp

#### Scenario: Backup step fails partway

- **WHEN** the database dump or media copy fails during a backup run
- **THEN** the system aborts the run, does not write a partial archive to the storage target, and records the failure

### Requirement: Database dump mechanism

The system SHALL dump the database using `mysqldump` when it is available on the host, and SHALL fall back to a PDO-based table dumper when it is not.

#### Scenario: mysqldump available

- **WHEN** `mysqldump` is present on the host
- **THEN** the backup uses it to produce the SQL dump

#### Scenario: mysqldump unavailable

- **WHEN** `mysqldump` is not present on the host
- **THEN** the backup falls back to a PDO-based dumper that produces an equivalent, restorable SQL dump without requiring shell access

### Requirement: Local and S3-compatible storage targets

The system SHALL support writing backups to a local filesystem path (default) and to an S3-compatible remote target, selected by configuration.

#### Scenario: Local target

- **WHEN** `backups.target` is configured as `local`
- **THEN** backups are written to the configured local directory outside the public web root

#### Scenario: S3-compatible target

- **WHEN** `backups.target` is configured as `s3` with valid credentials, bucket, and region
- **THEN** backups are uploaded to the configured bucket instead of the local filesystem

#### Scenario: S3 credentials invalid or unreachable

- **WHEN** the configured S3-compatible target rejects the upload or is unreachable
- **THEN** the backup run fails, is recorded as failed, and no partial object is left considered valid

### Requirement: Scheduled backups

The system SHALL support running backups on a configured schedule via `bin/backup --scheduled`, driven by an operator-configured cron entry, without requiring a background worker process.

#### Scenario: Scheduled run is due

- **WHEN** `bin/backup --scheduled` runs and the configured frequency interval has elapsed since the last successful backup
- **THEN** a backup is created as in manual backup creation

#### Scenario: Scheduled run is not due

- **WHEN** `bin/backup --scheduled` runs before the configured frequency interval has elapsed
- **THEN** the command exits without creating a backup

### Requirement: Backup retention pruning

The system SHALL retain only the configured number of most recent successful backups per storage target, deleting older ones automatically after each successful run.

#### Scenario: Backups exceed retention count

- **WHEN** a successful backup run brings the count of stored backups for a target above the configured retention count
- **THEN** the oldest backups beyond that count are deleted from both the storage target and the `backups` table

### Requirement: Scheduled backup admin UI

The system SHALL provide an admin UI to configure backup frequency, retention count, and storage target, and to view backup run history.

#### Scenario: Admin updates backup settings

- **WHEN** an admin changes frequency, retention count, or storage target in the backup settings UI and saves
- **THEN** the new configuration is persisted and used by subsequent scheduled and manual runs

#### Scenario: Admin views backup history

- **WHEN** an admin opens the backup admin UI
- **THEN** they see a list of past backup runs with status, timestamp, size, and trigger source (manual, scheduled, pre-update)

### Requirement: Restore from backup

The system SHALL provide a restore flow, available from both the admin UI and the CLI, that reinstates the database and media directory from a selected backup after explicit confirmation.

#### Scenario: Admin restores via UI

- **WHEN** an admin selects a backup in the admin UI, confirms the restore action, and the restore completes
- **THEN** the database and media directory match the state captured in that backup

#### Scenario: CLI restore

- **WHEN** an operator runs the restore CLI command with a backup identifier and confirms
- **THEN** the database and media directory are restored from that backup, independent of whether the admin UI is reachable

#### Scenario: Restore without confirmation

- **WHEN** a restore is requested without the required explicit confirmation step
- **THEN** the system does not modify the database or media directory

### Requirement: Backup covers plugin tables automatically

The system SHALL include all `plugin_{slug}_*` tables in the database dump without plugin-specific backup code, since plugin data lives in the same core database.

#### Scenario: Backup includes plugin data

- **WHEN** a backup is created on a site with installed plugins that have their own `plugin_{slug}_*` tables
- **THEN** those tables' data is present in the resulting database dump

### Requirement: Plugin code directory excluded

The system SHALL NOT include the `plugins/` directory (plugin code) in backups created by this capability.

#### Scenario: Plugin code not present in backup

- **WHEN** a backup archive is created
- **THEN** the `plugins/` directory is not included in the archive
