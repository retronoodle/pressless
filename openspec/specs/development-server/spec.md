# Capability: development-server

## Purpose

TBD

## Requirements

### Requirement: Development server command
The project SHALL provide an executable `bin/serve` command that validates configuration, performs requested preflight actions, and starts PHP's built-in development server with the project public directory and router script.

#### Scenario: Start with defaults
- **WHEN** a contributor runs `bin/serve` with valid configuration
- **THEN** the command starts a local server using the configured or documented default host and port and reports the address without exposing secrets

#### Scenario: Invalid startup configuration
- **WHEN** the command cannot connect to the configured MySQL/MariaDB database
- **THEN** it exits nonzero with an actionable error and does not start a partially configured server

### Requirement: Fresh development reset
The command SHALL support an explicit `--fresh` option that resets the application schema and reruns all migrations before serving requests.

#### Scenario: Fresh server launch
- **WHEN** a contributor runs `bin/serve --fresh`
- **THEN** known application tables are reset, migrations are applied in order, and the server starts only after the clean schema is ready

#### Scenario: Fresh is not implicit
- **WHEN** a contributor runs `bin/serve` without `--fresh`
- **THEN** existing records and migration state are preserved

### Requirement: Deterministic sample seeding
The command SHALL support an explicit `--seed` option that idempotently creates a development administrator and empty sample collections, and SHALL prevent accidental production seeding.

#### Scenario: Seed an empty database
- **WHEN** a contributor runs `bin/serve --seed` in a non-production environment after migrations
- **THEN** one usable administrator and the documented empty sample collections exist before the server starts

#### Scenario: Repeat seed
- **WHEN** the same seed operation is run more than once
- **THEN** it does not create duplicate administrators or collections and exits successfully

#### Scenario: Production seed refusal
- **WHEN** `--seed` is requested while the application environment is production without an explicit safe override
- **THEN** the command exits nonzero and makes no data changes

### Requirement: Evaluator smoke path
The project SHALL include an automated smoke test or equivalent repeatable check covering fresh setup, sample seeding, login, and access to the empty admin shell.

#### Scenario: Clone-to-admin verification
- **WHEN** the smoke check runs against a clean supported MySQL/MariaDB test database with valid configuration
- **THEN** migrations and seed complete, valid credentials can log in, `/admin` is protected before login, and the authenticated response contains the empty admin shell
