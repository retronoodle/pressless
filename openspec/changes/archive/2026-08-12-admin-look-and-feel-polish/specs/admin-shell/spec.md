## ADDED Requirements

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
