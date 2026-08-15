## MODIFIED Requirements

### Requirement: Homepage section on settings admin screen

The `/admin/settings` screen SHALL include a Homepage section showing the currently effective homepage type (indicating whether it comes from the active theme's default or from a saved override), a control to choose `static_page` and pick an entry, a control to choose `blog` and pick a collection, and a control to clear the override back to the theme default.

#### Scenario: Admin views effective homepage type

- **WHEN** an authenticated administrator opens `/admin/settings` with no override saved
- **THEN** the Homepage section shows the active theme's default homepage type and indicates no override is in effect

#### Scenario: Admin selects a static page as homepage

- **WHEN** an authenticated administrator selects `static_page`, picks an existing entry, and submits
- **THEN** the response redirects back to `/admin/settings` and the Homepage section reflects the saved override and chosen entry

#### Scenario: Admin selects a blog as homepage

- **WHEN** an authenticated administrator selects `blog`, picks an existing collection, and submits
- **THEN** the response redirects back to `/admin/settings` and the Homepage section reflects the saved override and chosen collection

#### Scenario: Admin selects blog without picking a collection

- **WHEN** an authenticated administrator selects `blog` and submits without picking a collection
- **THEN** the form is redisplayed with a validation error and no override is saved

#### Scenario: Admin clears the override

- **WHEN** an authenticated administrator with a saved override selects "Use theme default" and submits
- **THEN** the response redirects back to `/admin/settings`, the override is cleared, and the Homepage section shows the theme's default again

#### Scenario: Non-admin cannot change homepage type

- **WHEN** an authenticated non-admin user submits a homepage type change to `/admin/settings`
- **THEN** the request is rejected the same way other admin-only settings changes reject non-admins