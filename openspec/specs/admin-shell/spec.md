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

### Requirement: Dashboard recent activity
The authenticated admin shell's dashboard SHALL display a recent-activity section listing recent entry edits and recent successful logins, drawn from existing revision and login-attempt data.

#### Scenario: Recent edits shown
- **WHEN** an authenticated administrator views `GET /admin` and at least one entry revision exists
- **THEN** the dashboard lists the most recent entry edits (entry title, collection, editor, timestamp), most recent first, up to a fixed limit

#### Scenario: Recent logins shown
- **WHEN** an authenticated administrator views `GET /admin` and at least one successful login exists
- **THEN** the dashboard lists the most recent successful logins (user, timestamp), most recent first, up to a fixed limit

#### Scenario: No activity yet
- **WHEN** an authenticated administrator views `GET /admin` and no revisions or successful logins exist
- **THEN** the recent-activity section shows the shared empty-state pattern instead of an empty list

### Requirement: Consistent typography and spacing
Admin templates SHALL share a single type scale and spacing scale defined as CSS custom properties, applied consistently across all admin surfaces rather than per-page hardcoded values.

#### Scenario: Shared tokens applied
- **WHEN** any admin page renders
- **THEN** its heading sizes, body text size, and layout spacing derive from the shared type-scale and spacing-scale custom properties rather than one-off hardcoded values

### Requirement: Motion respects reduced-motion preference
Transitions used in the admin UI (navigation state changes, form/button feedback, panel open/close) SHALL be disabled or reduced when the user's operating system indicates a reduced-motion preference.

#### Scenario: Reduced motion honored
- **WHEN** an administrator's browser reports `prefers-reduced-motion: reduce`
- **THEN** admin UI transitions are removed or reduced to near-instant rather than animating

### Requirement: Consistent empty, loading, and error states
Admin list and form views SHALL use a shared, consistent visual pattern for empty, loading, and error states rather than each view defining its own.

#### Scenario: Empty list state
- **WHEN** an admin list view (e.g. entries, media, users) has no items to display
- **THEN** it shows the shared empty-state pattern with an actionable next step

#### Scenario: Error state
- **WHEN** an admin view fails to load its data due to a recoverable error
- **THEN** it shows the shared error-state pattern with a description of what happened, instead of a blank page or raw error output

### Requirement: Keyboard shortcuts for common actions
The admin UI SHALL support keyboard shortcuts for save, publish, and navigation between an entry's list and edit views, without interfering with normal text input.

#### Scenario: Save via shortcut
- **WHEN** an administrator is on an entry edit form (focus outside a text input/textarea/contenteditable) and presses the save shortcut
- **THEN** the current entry is saved, equivalent to clicking the save button

#### Scenario: Shortcut ignored while typing
- **WHEN** an administrator's focus is inside a text input, textarea, or contenteditable field
- **THEN** keyboard shortcut key combinations are not intercepted and behave as normal text input

### Requirement: Admin JS loads only where needed
Admin JavaScript SHALL be organized as small, single-concern files included only on the admin pages that use them, without requiring a JS build/bundling toolchain.

#### Scenario: Page loads only relevant scripts
- **WHEN** an administrator requests an admin page
- **THEN** only the JS files relevant to that page's functionality are included in the response, and no admin page loads JS it does not use

### Requirement: Shared admin header partial
Admin templates SHALL render the header, primary navigation, and account/logout controls from a single shared partial rather than duplicating this markup per template.

#### Scenario: Header consistent across pages
- **WHEN** an authenticated administrator navigates between different admin pages (e.g. dashboard, collections, entries)
- **THEN** the header, navigation links, and account controls are structurally identical and sourced from the same shared partial

#### Scenario: Active nav link reflects current page
- **WHEN** an authenticated administrator views an admin page belonging to a known nav section (e.g. Collections)
- **THEN** the corresponding navigation link is marked `aria-current="page"` and no other link is

### Requirement: Styled data tables and status badges
Admin list views SHALL style tabular data and status badges using the shared design tokens rather than relying on unstyled browser defaults.

#### Scenario: Entries table styled
- **WHEN** an authenticated administrator views the entries list with at least one entry
- **THEN** the table renders with token-based spacing, borders, and typography, and each status badge is visually distinguished by its status

### Requirement: Button visual variants
The admin UI SHALL provide distinct visual styles for primary, secondary, and destructive button actions.

#### Scenario: Destructive action visually distinct
- **WHEN** an authenticated administrator views a destructive action control (e.g. delete user, restore backup)
- **THEN** it is styled with the destructive/danger button variant, visually distinct from primary actions

### Requirement: Dark mode support
The admin UI SHALL support a dark color scheme that activates automatically based on the operating system's `prefers-color-scheme` setting, using the existing design token system.

#### Scenario: Dark mode follows OS preference
- **WHEN** an administrator's operating system is set to dark mode
- **THEN** admin pages render using dark-mode color token values with no separate opt-in required

### Requirement: Content width adapts to table-heavy views
Admin views containing data tables SHALL be allowed a wider content area than the default admin content width, so tables are not unnecessarily cramped.

#### Scenario: Table view wider than form view
- **WHEN** an authenticated administrator views a table-heavy list page (e.g. entries, users, permissions)
- **THEN** the content area is rendered wider than the default form-page content width

### Requirement: Comfortable vertical rhythm on form controls and buttons
Form inputs, buttons, and `a.button` elements in the admin UI SHALL use the project's shared spacing scale to provide a vertical padding that reads as comfortable rather than cramped, applied consistently across all admin surfaces including the sticky `.admin-account` footer bar.

#### Scenario: Form input has comfortable vertical padding
- **WHEN** an administrator views any admin form (e.g. login, collection create/edit, entry create/edit, user, media)
- **THEN** `<input>` controls render with vertical padding derived from the shared `--stead-space-*` token scale such that the input is visibly taller than its single line of text and adjacent stacked fields do not feel pressed together

#### Scenario: Button has comfortable vertical padding
- **WHEN** an administrator views any admin action (save, delete, login, secondary/ghost/danger variants, or `a.button` anchors)
- **THEN** those controls render with vertical padding derived from the shared `--stead-space-*` token scale such that the button is visibly taller than its label

#### Scenario: Sticky account footer stays in step with main chrome
- **WHEN** an administrator views the sticky `.admin-account` bar at the bottom of any admin page
- **THEN** the buttons inside it use the same vertical-spacing token values as the main chrome so the footer does not look visibly shrunken relative to the rest of the form

#### Scenario: Vertical rhythm stays token-driven
- **WHEN** future spacing work touches form inputs, buttons, or `.admin-account` overrides
- **THEN** new values are chosen from the shared `--stead-space-*` token scale and not introduced as per-rule hardcoded values

### Requirement: Consistent gap between form fields
Admin form fields SHALL be separated from one another by a token-driven vertical gap so the form reads as comfortably spaced across all rows, not just inside each individual control. The gap SHALL be the same between consecutive fields regardless of whether the row markup is a wrapper-`<label>`, a `<p>`, or a `<fieldset>`, and SHALL apply at every nesting level (form, nested fieldset) that contains form rows. The submit row SHALL remain flush with the form's last field (no trailing gap below it).

#### Scenario: Wrapper-label forms have consistent gap
- **WHEN** an authenticated administrator views an admin form whose fields are rendered as `<label>` wrappers (e.g. settings, mail settings, redirects, invites, users, backups)
- **THEN** each field has the same vertical separation from the next as every other field in that form

#### Scenario: Paragraph-row forms have the same gap
- **WHEN** an authenticated administrator views an admin form whose fields are rendered as `<p>` rows (e.g. collections top fields, media, themes)
- **THEN** the vertical separation between consecutive rows matches the separation used by wrapper-label forms

#### Scenario: Nested fieldset rows have the same gap
- **WHEN** an authenticated administrator views an admin form with fields nested inside a `<fieldset>` (e.g. entries SEO section, collections schema)
- **THEN** the vertical separation between consecutive fields inside the fieldset matches the separation used at the form's top level

#### Scenario: Submit row stays flush with last field
- **WHEN** an authenticated administrator views any admin form
- **THEN** the submit control does not have a trailing vertical gap below it (the gap lives between fields, not after the last one)

#### Scenario: Gap value comes from the shared spacing token
- **WHEN** future spacing work touches the gap between form fields
- **THEN** the value is chosen from the shared `--stead-space-*` token scale and not introduced as a per-rule hardcoded value
