## ADDED Requirements

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
