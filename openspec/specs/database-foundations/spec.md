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

### Requirement: Ordered migration tracking
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
