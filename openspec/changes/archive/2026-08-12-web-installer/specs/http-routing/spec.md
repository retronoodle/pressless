## MODIFIED Requirements

### Requirement: Single front controller
All dynamic web requests SHALL enter through a public front controller that creates the normalized request context, dispatches application routes, and emits exactly one response. When `installed.lock` is absent at the project root, the front controller SHALL dispatch exclusively to installer routes without first requiring a valid application configuration or database connection; when `installed.lock` is present, the front controller SHALL dispatch through the normal bootstrap and route table and SHALL NOT expose installer routes.

#### Scenario: Admin request dispatch
- **WHEN** a browser requests an admin path
- **THEN** the front controller bootstraps the application, dispatches the matching handler, and sends its status, headers, and body

#### Scenario: Direct source protection
- **WHEN** a client requests a source, configuration, migration, or seed file outside the public document root
- **THEN** the file is not exposed as a public response

#### Scenario: Unconfigured install routes to installer
- **WHEN** a browser requests any path and `installed.lock` is absent at the project root
- **THEN** the front controller routes the request into the installer wizard instead of attempting normal configuration bootstrap, even if `.env` is missing or invalid

#### Scenario: Installed site rejects installer routes
- **WHEN** a browser requests an `/install/*` path and `installed.lock` is present at the project root
- **THEN** the front controller does not dispatch to the installer and instead redirects to `/admin` or returns 404
