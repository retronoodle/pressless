# Capability: admin-shell

## Purpose

TBD

## Requirements

### Requirement: Twig view foundation
The admin surface SHALL render through Twig with autoescaping enabled, a shared base layout, and template paths resolved from the configured project root.

#### Scenario: Render a template
- **WHEN** an admin handler renders a Twig view with user-controlled text in its view data
- **THEN** the output is escaped by default and uses the shared layout where applicable

#### Scenario: Missing template
- **WHEN** a requested admin template does not exist
- **THEN** the application records a safe diagnostic and returns a controlled server error without exposing a stack trace

### Requirement: Administrator login page
The system SHALL provide a calm, server-rendered login page at `GET /admin/login` with labeled email and password fields and a form targeting `POST /admin/login`.

#### Scenario: Login page for a visitor
- **WHEN** an unauthenticated browser requests `GET /admin/login`
- **THEN** it receives a successful HTML response containing the login form and no protected admin data

#### Scenario: Login error display
- **WHEN** a login submission fails validation or authentication
- **THEN** the page is rendered again with a generic actionable error and preserves only safe form values

### Requirement: Authenticated admin shell
The system SHALL provide an authenticated admin shell at `/admin` with a consistent base layout, quiet navigation placeholders, navigation that links to the active admin surfaces (Collections at minimum, with other entries remaining as placeholders), and explicit empty states for features not implemented in Phase 1.

#### Scenario: Empty admin after login
- **WHEN** an authenticated administrator requests `GET /admin`
- **THEN** the response is a successful HTML page with the admin navigation, a link to the collections surface, and an explanation of the next step (create a collection) when no collections exist

#### Scenario: Visitor cannot view shell
- **WHEN** an unauthenticated browser requests `GET /admin`
- **THEN** it is redirected to login and receives no shell markup

#### Scenario: Navigation exposes Collections
- **WHEN** an authenticated administrator views any admin page
- **THEN** the navigation includes a link to the collections list and to the active collection if one is currently being edited

### Requirement: Accessible minimal presentation
The initial admin templates SHALL use semantic headings, labels associated with form controls, keyboard-operable controls, and hand-rolled CSS without an admin template kit or frontend framework.

#### Scenario: Keyboard login
- **WHEN** a user navigates the login form using a keyboard
- **THEN** the fields and submit action have a logical focus order and can be operated without a pointer
