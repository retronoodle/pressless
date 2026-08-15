## MODIFIED Requirements

### Requirement: Homepage type override storage

The system SHALL persist an optional homepage type override (`homepage_type`, one of `static_page`, `blog`, or `NULL`) and, when set to `static_page`, a chosen entry id (`homepage_page_id`); when set to `blog`, a chosen collection id (`homepage_collection_id`), on the single-row `settings` table. A `NULL` `homepage_type` SHALL mean "use the active theme's default homepage type." The renderer and admin UI MAY ignore `blog` and `homepage_collection_id` until a follow-up change wires them in; until then, the round-trip storage behaviour described here is the only observable contract.

#### Scenario: No override saved

- **WHEN** an administrator has never configured a homepage type override
- **THEN** `homepage_type` reads as `NULL` and the effective homepage type is determined by the active theme's default

#### Scenario: Override saved as static page

- **WHEN** an administrator selects `static_page` and picks an entry as the homepage
- **THEN** the settings row stores `homepage_type = 'static_page'` and `homepage_page_id` set to the chosen entry's id

#### Scenario: Override saved as blog

- **WHEN** an administrator (or follow-up admin UI) saves `homepage_type = 'blog'` with a chosen collection id
- **THEN** the settings row stores `homepage_type = 'blog'` and `homepage_collection_id` set to the chosen collection's id, and `homepage_page_id` is `NULL`

#### Scenario: Override cleared

- **WHEN** an administrator clears a previously saved override
- **THEN** `homepage_type`, `homepage_page_id`, and `homepage_collection_id` are all reset to `NULL`

#### Scenario: Blog type with no collection

- **WHEN** `homepage_type = 'blog'` is saved without a `homepage_collection_id`
- **THEN** the settings row stores `homepage_type = 'blog'` and `homepage_collection_id` remains `NULL`