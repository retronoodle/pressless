## ADDED Requirements

### Requirement: Schema-change bookkeeping table

The Phase 2 migration SHALL add a `schema_change_log` table that records, for each collection edit, the collection id, the previous field-set hash (or sentinel), the new field-set hash, and the timestamp. The table SHALL be append-only and SHALL be the source of truth for the schema-change helper's idempotence.

#### Scenario: Append on first edit

- **WHEN** an existing collection is edited for the first time after Phase 2 is applied
- **THEN** the schema-change helper inserts one row into `schema_change_log` containing the collection id and the new field-set hash

#### Scenario: Skip on no-op edit

- **WHEN** an existing collection is edited but the new field set matches the previously logged field set
- **THEN** no additional row is appended and no `entry_values` data change is performed

## MODIFIED Requirements

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
