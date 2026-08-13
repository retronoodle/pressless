## ADDED Requirements

### Requirement: Homepage type override storage

The system SHALL persist an optional homepage type override (`homepage_type`, one of `static_page` or `NULL`) and, when set to `static_page`, a chosen entry id (`homepage_page_id`) on the single-row `settings` table. A `NULL` `homepage_type` SHALL mean "use the active theme's default homepage type."

#### Scenario: No override saved

- **WHEN** an administrator has never configured a homepage type override
- **THEN** `homepage_type` reads as `NULL` and the effective homepage type is determined by the active theme's default

#### Scenario: Override saved as static page

- **WHEN** an administrator selects `static_page` and picks an entry as the homepage
- **THEN** the settings row stores `homepage_type = 'static_page'` and `homepage_page_id` set to the chosen entry's id

#### Scenario: Override cleared

- **WHEN** an administrator selects "Use theme default" after previously saving an override
- **THEN** `homepage_type` and `homepage_page_id` are reset to `NULL`

### Requirement: Homepage section on settings admin screen

The `/admin/settings` screen SHALL include a Homepage section showing the currently effective homepage type (indicating whether it comes from the active theme's default or from a saved override), a control to choose `static_page` and pick an entry, and a control to clear the override back to the theme default.

#### Scenario: Admin views effective homepage type

- **WHEN** an authenticated administrator opens `/admin/settings` with no override saved
- **THEN** the Homepage section shows the active theme's default homepage type and indicates no override is in effect

#### Scenario: Admin selects a static page as homepage

- **WHEN** an authenticated administrator selects `static_page`, picks an existing entry, and submits
- **THEN** the response redirects back to `/admin/settings` and the Homepage section reflects the saved override and chosen entry

#### Scenario: Admin clears the override

- **WHEN** an authenticated administrator with a saved override selects "Use theme default" and submits
- **THEN** the response redirects back to `/admin/settings`, the override is cleared, and the Homepage section shows the theme's default again

#### Scenario: Non-admin cannot change homepage type

- **WHEN** an authenticated non-admin user submits a homepage type change to `/admin/settings`
- **THEN** the request is rejected the same way other admin-only settings changes reject non-admins
