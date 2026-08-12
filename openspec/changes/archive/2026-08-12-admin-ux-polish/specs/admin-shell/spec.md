## ADDED Requirements

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
