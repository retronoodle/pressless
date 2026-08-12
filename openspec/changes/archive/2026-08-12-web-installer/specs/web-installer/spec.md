## ADDED Requirements

### Requirement: Installer availability gated by lock file
The application SHALL treat `/install/*` routes as reachable only when no `installed.lock` file exists at the project root, and SHALL make them permanently unreachable once that file exists.

#### Scenario: Fresh extraction, no lock file
- **WHEN** a browser requests `GET /install` and `installed.lock` is absent
- **THEN** the installer wizard's first step is rendered without requiring a valid `.env` or database connection to already exist

#### Scenario: Already-installed site
- **WHEN** a browser requests any `/install/*` path and `installed.lock` exists
- **THEN** the request is redirected to `/admin` (or returns 404 for non-page installer endpoints) without re-running any installer step

### Requirement: Multi-step installer wizard
The installer SHALL present a linear wizard with distinct steps for database connection, admin user creation, optional sample data, and completion, and SHALL NOT allow a later step to run before an earlier required step has succeeded.

#### Scenario: Step order enforced
- **WHEN** a user requests the admin-user step directly without having completed the database step
- **THEN** the installer redirects back to the database step instead of rendering a form against an unconfigured connection

#### Scenario: Optional sample data
- **WHEN** a user reaches the sample-data step
- **THEN** they can choose to seed sample content or skip directly to completion, and both choices lead to the same completion step

### Requirement: Database connection test before persisting configuration
The installer SHALL validate a submitted database driver, host, and credentials by opening a real connection and executing a trivial query before writing any configuration to disk.

#### Scenario: Valid credentials
- **WHEN** the user submits database settings that the application can connect with
- **THEN** the installer confirms success and advances to the admin-user step without yet writing `.env`

#### Scenario: Invalid credentials
- **WHEN** the user submits database settings the application cannot connect with
- **THEN** the installer re-displays the database step with an actionable error that does not expose the submitted password

### Requirement: Admin user creation reuses core authentication
The installer SHALL create the initial administrator account through the same user-repository and password-hashing path used elsewhere in the application, assigning it the administrator role.

#### Scenario: Admin account created
- **WHEN** the user submits a valid email and password on the admin-user step
- **THEN** an active user with the administrator role is created and can subsequently log in through the normal `/admin/login` flow

#### Scenario: Duplicate or weak input
- **WHEN** the submitted admin email is malformed or the password fails the application's existing password requirements
- **THEN** the installer re-displays the step with a field-level error and creates no account

### Requirement: Configuration file generation
The installer SHALL write a `.env` file containing the confirmed database settings and application environment once the database and admin-user steps have both succeeded, without modifying `config/app.yaml`.

#### Scenario: Successful config write
- **WHEN** the database and admin-user steps have both succeeded
- **THEN** the installer writes `.env` with the confirmed `DB_*`, `APP_ENV`, and session settings before running migrations

#### Scenario: Config write fails due to permissions
- **WHEN** the project root is not writable by the web server process
- **THEN** the installer reports which path could not be written and does not proceed to create `installed.lock`

### Requirement: Installation completion lock
The installer SHALL create `installed.lock` as its final step, only after configuration has been written and migrations have applied successfully, using an exclusive-create write so a second concurrent completion cannot overwrite the first.

#### Scenario: Successful completion
- **WHEN** migrations have applied successfully against the configured database
- **THEN** the installer creates `installed.lock` and redirects the user to `/admin/login`

#### Scenario: Concurrent completion attempts
- **WHEN** two requests attempt to finish the wizard at the same time
- **THEN** exactly one creates `installed.lock`, and the other is redirected to `/admin/login` without re-running migrations or overwriting the lock file

### Requirement: Sample data reuses existing seeder
The installer SHALL invoke the application's existing sample-data seeder in-process when the user opts in, rather than shelling out to a separate command.

#### Scenario: User opts into sample data
- **WHEN** the user selects the sample-data option during the wizard
- **THEN** the same seed routine used by `bin/migrate --seed` runs in-process against the newly configured database

### Requirement: Prod-parity local environment
The repository SHALL provide a `docker-compose.yml` running PHP, MySQL, and nginx so a contributor can exercise the full installer flow against a MySQL-backed environment locally.

#### Scenario: Local installer smoke test
- **WHEN** a contributor runs the documented `docker compose up` workflow against a fresh checkout
- **THEN** they can complete the installer wizard against the composed MySQL service and reach a working admin login
