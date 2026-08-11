## MODIFIED Requirements

### Requirement: Explicit method and path matching

The router SHALL match routes by HTTP method and normalized path, support static and parameterized admin route shapes (including `/admin/collections/{slug}` and `/admin/collections/{slug}/entries/{id}`), and pass extracted parameters to the selected handler.

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

#### Scenario: Unsupported method on a known admin path

- **WHEN** a request uses a method that the route table does not list for a known admin path
- **THEN** the response status is 405 and the response identifies the allowed methods without exposing implementation details
