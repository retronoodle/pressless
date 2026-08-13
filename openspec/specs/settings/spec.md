# Capability: settings

## Purpose

TBD
## Requirements
### Requirement: Single-row site settings storage

The system SHALL persist site-wide settings (`site_name`, `timezone`, `date_format`) in a single-row `settings` table, following the existing single-row pattern used for mail settings. Reading settings when no row exists SHALL return sane defaults (empty site name, `UTC` timezone, a default date format) rather than an error.

#### Scenario: Read settings before any save

- **WHEN** an administrator requests the settings screen before ever saving settings
- **THEN** the response shows default values (`UTC` timezone, default date format, empty site name) and does not error

#### Scenario: Save settings

- **WHEN** an administrator submits a new site name, timezone, and date format
- **THEN** the single settings row is upserted with the new values and subsequent reads return them

### Requirement: Settings admin screen

The system SHALL provide an authenticated, admin-only screen at `/admin/settings` to view and edit `site_name`, `timezone`, and `date_format`. Validation failures SHALL redisplay the form with the submitted values and an error message, without saving.

#### Scenario: Admin views and updates settings

- **WHEN** an authenticated administrator opens `/admin/settings`, changes the site name, and submits
- **THEN** the response redirects back to `/admin/settings` and the updated site name is displayed

#### Scenario: Non-admin cannot access settings

- **WHEN** an authenticated non-admin user requests `/admin/settings`
- **THEN** the request is rejected the same way other admin-only screens reject non-admins

#### Scenario: Invalid timezone rejected

- **WHEN** an administrator submits a timezone value that is not a valid identifier
- **THEN** the form is redisplayed with the submitted values and a validation error, and the settings row is not changed

### Requirement: Manual default-collection seeding

The system SHALL provide an authenticated, admin-only action at `POST /admin/settings/seed-default-collections` that creates any of the `posts`/`pages` collections that don't already exist, using the same seeding routine the installer uses, and redirects to `/admin/settings` reporting how many collections were created.

#### Scenario: Both collections missing

- **WHEN** an administrator submits the seed-default-collections action and neither `posts` nor `pages` exists
- **THEN** both collections are created and the response redirects with a flash message reporting 2 collections created

#### Scenario: Collections already present

- **WHEN** an administrator submits the seed-default-collections action and both `posts` and `pages` already exist
- **THEN** no collections are created, no existing collection is modified, and the response redirects with a flash message stating they are already present

#### Scenario: Non-admin cannot trigger seeding

- **WHEN** an authenticated non-admin user submits `POST /admin/settings/seed-default-collections`
- **THEN** the request is rejected the same way other admin-only actions reject non-admins

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

