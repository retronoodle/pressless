## ADDED Requirements

### Requirement: Installed version detection
The system SHALL determine its own installed version by reading the `VERSION` file written at build time.

#### Scenario: Reading installed version
- **WHEN** any code needs the currently installed version
- **THEN** it SHALL read the value from the `VERSION` file at the application root

### Requirement: Latest release lookup
The system SHALL be able to fetch the latest published release version from the project website's release endpoint.

#### Scenario: Successful lookup
- **WHEN** the update checker queries the release endpoint and receives a valid response
- **THEN** it extracts the latest published version and compares it against the installed version

#### Scenario: Endpoint unreachable or errors
- **WHEN** the release endpoint is unreachable, times out, or returns an error
- **THEN** the update checker treats this as "no update available", logs the failure, and does not surface an error to the admin user

### Requirement: Update check caching
The system SHALL cache the result of an update check so it is not re-queried on every admin page load.

#### Scenario: Cached check reused within window
- **WHEN** an update check was performed less than the configured interval ago
- **THEN** subsequent admin page loads reuse the cached result instead of calling the release endpoint again

#### Scenario: Cache expires and re-checks
- **WHEN** the cached check is older than the configured interval
- **THEN** the next admin page load triggers a fresh check against the release endpoint and updates the cache
