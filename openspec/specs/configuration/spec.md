# Capability: configuration

## Purpose

TBD

## Requirements

### Requirement: Layered environment and YAML configuration
The application SHALL load deployment settings from process environment variables and local dotenv values, load application defaults from YAML configuration files, and expose the result through one typed or normalized configuration interface.

#### Scenario: Local development configuration
- **WHEN** a developer provides a local `.env` and the repository YAML defaults
- **THEN** the bootstrap resolves database, application, path, session, and logging settings without requiring values to be hard-coded in PHP

#### Scenario: Deployment override
- **WHEN** a setting is exported in the process environment and a different value exists in `.env` or YAML
- **THEN** the exported environment value wins

### Requirement: Project-relative path resolution
Configuration SHALL resolve application paths relative to the project root rather than the caller's current working directory.

#### Scenario: Command invoked outside the project root
- **WHEN** a supported CLI command is invoked with the project root as its configured application location
- **THEN** migrations, templates, and seed files are found using project-relative paths

### Requirement: Configuration validation
The configuration loader SHALL validate the database driver, required connection fields, application environment, and writable/runtime paths before the application serves requests or mutates the database.

#### Scenario: Unsupported database driver
- **WHEN** configuration specifies a driver other than MySQL or MariaDB-compatible PDO
- **THEN** startup fails with an actionable validation error before a connection is attempted

#### Scenario: Invalid required value
- **WHEN** a required database or application setting is empty or malformed
- **THEN** startup fails and identifies the setting name without exposing secret values

### Requirement: Safe local configuration defaults
The repository SHALL include an example configuration that documents required settings while ensuring real environment files and credentials are excluded from version control.

#### Scenario: New checkout setup
- **WHEN** a contributor follows the documented configuration setup
- **THEN** they can create a local environment file from the example without modifying tracked source files or committing credentials
