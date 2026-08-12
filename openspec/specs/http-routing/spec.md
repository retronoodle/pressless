# Capability: http-routing

## Purpose

TBD

## Requirements

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

### Requirement: Explicit method and path matching
The router SHALL match routes by HTTP method and normalized path, support the initial static and parameterized route shapes, including `/admin/collections/{slug}` and `/admin/collections/{slug}/entries/{id}`, and pass extracted parameters to the selected handler.

#### Scenario: Login routes
- **WHEN** the client sends `GET /admin/login` or `POST /admin/login`
- **THEN** the router selects the corresponding login handler and preserves the request method distinction

#### Scenario: Protected admin route
- **WHEN** the client sends `GET /admin`
- **THEN** the router selects the admin shell handler and supplies the authenticated request context

#### Scenario: Collection list route
- **WHEN** the client sends `GET /admin/collections`
- **THEN** the router selects the collection list handler and the request is treated as authenticated

#### Scenario: Collection entry route
- **WHEN** the client sends `GET /admin/collections/{slug}/entries/{id}/edit`
- **THEN** the router selects the entry edit handler with both `slug` and `id` available as route parameters

### Requirement: Deterministic routing failures
The router SHALL return a 404 response for an unmatched path and a 405 response for a known path requested with an unsupported method, without leaking stack traces in the response.

#### Scenario: Unknown path
- **WHEN** a request path has no registered route
- **THEN** the response status is 404 and the body is a safe not-found response

#### Scenario: Unsupported method
- **WHEN** a request uses an unsupported method for a known path
- **THEN** the response status is 405 and the response identifies the allowed methods without exposing implementation details

### Requirement: Static asset handoff
The development server integration SHALL allow existing files under the public document root to be served directly while routing other requests through the front controller.

#### Scenario: Existing public asset
- **WHEN** a request targets an existing CSS or image file under the public document root
- **THEN** the development router serves the file without invoking an application route

#### Scenario: Dynamic path with no file
- **WHEN** a request targets a non-file path
- **THEN** the development router forwards it to the front controller
