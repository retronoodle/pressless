# Capability: database-foundations

## Purpose

TBD

## Requirements

### Requirement: Multi-driver connection boundary

The database layer SHALL provide a small PDO-backed interface for prepared statements, parameter binding, transactions, and consistent exception handling, SHALL support MySQL, MariaDB, and SQLite drivers, and SHALL reject other database drivers.

#### Scenario: Parameterized query
- **WHEN** application code executes a query with user-supplied values through the database interface
- **THEN** the values are bound as parameters and are never interpolated into SQL text

#### Scenario: Transaction failure
- **WHEN** an operation inside an explicit transaction raises an exception
- **THEN** the database layer rolls the transaction back and propagates a safe application error

#### Scenario: SQLite foreign-key enforcement
- **WHEN** the application opens a SQLite connection
- **THEN** `PRAGMA foreign_keys=ON` is set so foreign-key constraints declared in migrations are honored

#### Scenario: Transaction wraps schema-change helper
- **WHEN** the schema-change helper applies `entry_values` data changes as part of a collection edit
- **THEN** the helper's writes run inside the same transaction as the collection update, so a partial failure leaves no orphaned rows or half-applied schema state

    
### Requirement: Schema-change bookkeeping table

The Phase 2 migration SHALL add a `schema_change_log` table that records, for each collection edit, the collection id, the previous field-set hash (or sentinel), the new field-set hash, and the timestamp. The table SHALL be append-only and SHALL be the source of truth for the schema-change helper's idempotence.

#### Scenario: Append on first edit

- **WHEN** an existing collection is edited for the first time after Phase 2 is applied
- **THEN** the schema-change helper inserts one row into `schema_change_log` containing the collection id and the new field-set hash

#### Scenario: Skip on no-op edit

- **WHEN** an existing collection is edited but the new field set matches the previously logged field set
- **THEN** no additional row is appended and no `entry_values` data change is performed

The migration runner SHALL discover versioned SQL migrations, apply pending migrations in deterministic order, and record each successful version in a `migrations` table.

#### Scenario: First migration run
- **WHEN** the migration runner is pointed at an empty supported database
- **THEN** it creates migration tracking, applies each migration once in version order, and records the applied versions

#### Scenario: Driver-aware migration discovery
- **WHEN** the migration runner discovers migrations
- **THEN** it selects the per-driver migration file for the configured driver (MySQL/MariaDB or SQLite) so each track uses idiomatic column types and syntax

#### Scenario: Repeated migration run
- **WHEN** the migration runner is run against a database whose versions are already recorded
- **THEN** it makes no duplicate schema changes and exits successfully

#### Scenario: Failed migration
- **WHEN** a migration statement fails
- **THEN** the migration is not recorded as applied and the runner reports a non-success result

### Requirement: Phase 1 relational schema
The initial migration SHALL create tables for users, sessions, collections, entries, entry values, media, and revisions, with primary keys, required timestamps, foreign-key relationships, uniqueness constraints, and indexes sufficient for authentication and later typed content work.

#### Scenario: Schema inspection after migration
- **WHEN** all Phase 1 migrations complete on MySQL or MariaDB
- **THEN** each required table exists and its relationships prevent orphaned session, entry, value, media, and revision records

#### Scenario: Collection schema extension point
- **WHEN** a collection is created by a seed or future content service
- **THEN** the schema definition can be stored as JSON without requiring a new table for every field definition

#### Scenario: Unique identity constraints
- **WHEN** two users use the same login email or two collections use the same slug
- **THEN** the database rejects the duplicate identity while allowing distinct records

### Requirement: Explicit destructive reset
The database layer SHALL expose an explicit reset operation that removes only known application tables in dependency-safe order and leaves reset disabled unless a caller requests it.

#### Scenario: Reset requested
- **WHEN** the development command requests a fresh database
- **THEN** known application tables are removed, migrations are rerun, and the resulting schema matches a clean first migration

#### Scenario: Normal startup
- **WHEN** the server starts without a reset option
- **THEN** existing tables and data are preserved
