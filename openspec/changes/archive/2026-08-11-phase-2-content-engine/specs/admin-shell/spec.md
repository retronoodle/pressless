## MODIFIED Requirements

### Requirement: Authenticated admin shell

The system SHALL provide an authenticated admin shell at `/admin` with a consistent base layout, navigation that links to the active admin surfaces (Collections at minimum, with other entries remaining as placeholders), and explicit empty states for features not implemented yet.

#### Scenario: Authenticated admin landing

- **WHEN** an authenticated administrator requests `GET /admin`
- **THEN** the response is a successful HTML page with the admin navigation, a link to the collections surface, and an explanation of the next step (create a collection) when no collections exist

#### Scenario: Visitor cannot view shell

- **WHEN** an unauthenticated browser requests `GET /admin`
- **THEN** it is redirected to login and receives no shell markup

#### Scenario: Navigation exposes Collections

- **WHEN** an authenticated administrator views any admin page
- **THEN** the navigation includes a link to the collections list and to the active collection if one is currently being edited
