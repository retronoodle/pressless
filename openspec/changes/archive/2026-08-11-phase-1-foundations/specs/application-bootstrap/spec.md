## ADDED Requirements

### Requirement: Supported PHP project metadata
The project SHALL declare PHP 8.2 or newer, define a stable `Pressless\\` PSR-4 namespace, and declare the focused runtime and development dependencies required by the Phase 1 application.

#### Scenario: Fresh dependency installation
- **WHEN** a contributor runs `composer install` from a fresh checkout with PHP 8.2 or newer
- **THEN** Composer resolves the declared dependencies and the project autoloader is generated without requiring a framework installation

### Requirement: Deterministic application bootstrap
The application SHALL expose one bootstrap path that resolves the project root, loads configuration, initializes logging and database services, and returns the dependencies needed by HTTP and CLI entry points.

#### Scenario: Web request bootstrap
- **WHEN** `public/index.php` receives a request
- **THEN** it loads the shared bootstrap before dispatching a route and does not construct a second independent application configuration

#### Scenario: CLI bootstrap
- **WHEN** a supported command is invoked from `bin/`
- **THEN** it uses the same configuration, logging, and database initialization rules as the web entry point

### Requirement: Safe runtime error handling
The application SHALL convert expected bootstrap and runtime failures into actionable errors while preventing passwords, database credentials, session payloads, and other secrets from appearing in responses or logs.

#### Scenario: Missing required configuration
- **WHEN** a required production configuration value is absent
- **THEN** startup fails with the missing setting identified by name and omits the setting's value from the error

### Requirement: Standard contributor checks
The project SHALL provide documented Composer or `bin/` entry points for running the PHPUnit test suite and PHPStan analysis.

#### Scenario: Contributor runs verification
- **WHEN** a contributor invokes the documented test and static-analysis commands
- **THEN** both commands execute against the project source without requiring a browser session
