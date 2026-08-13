## 1. Database

- [x] 1.1 Add migration `20260813000003_homepage_settings.mysql.sql` and `.sqlite.sql` adding nullable `homepage_type` (string) and `homepage_page_id` (integer) columns to `settings`

## 2. Theme manifest

- [x] 2.1 Extend `ThemeManifestReader` to parse an optional top-level `homepage_type` field, accepting only `collection_list`/`static_page` and treating anything else as absent
- [x] 2.2 Add/update tests covering: valid `homepage_type`, absent `homepage_type`, unrecognized `homepage_type` value

## 3. Settings storage

- [x] 3.1 Extend `Settings` value object with `homepageType` (nullable) and `homepagePageId` (nullable) fields
- [x] 3.2 Extend `SettingsRepository` to read/write the two new columns, defaulting to `NULL`
- [x] 3.3 Add/update tests for reading defaults (both NULL) and saving/clearing an override

## 4. Homepage resolution

- [x] 4.1 In `PublicController::home()`, resolve effective homepage type: `settings.homepage_type` override → active theme's manifest `homepage_type` → `collection_list`
- [x] 4.2 When effective type is `static_page`, look up `homepage_page_id` via the entry repository and render it the same way the entry route does; fall back to `collection_list` rendering if the entry is missing
- [x] 4.3 Add/update tests for: no override + no theme default, theme default applied, admin override takes precedence, deleted static page falls back gracefully

## 5. Admin settings UI

- [x] 5.1 Add homepage type + page-picker fields to the settings form handling in `SettingsAdminController` (validate `static_page` requires a valid existing entry id; "use theme default" clears both columns)
- [x] 5.2 Add a Homepage section to `templates/admin/settings/index.twig`: shows effective type and its source (override vs. theme default), a type select, and a conditional entry picker
- [x] 5.3 Add/update tests for: saving a static page override, clearing an override, non-admin rejected, invalid entry id rejected

## 6. Verification

- [x] 6.1 Run full test suite
- [x] 6.2 Manually verify in dev: fresh install homepage unchanged, set a static page override, clear it back to theme default, switch themes with an override set
