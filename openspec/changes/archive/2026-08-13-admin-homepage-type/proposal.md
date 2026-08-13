## Why

Today the homepage is hardcoded: `PublicController::home()` always renders `home.twig` with every collection, and there is no concept of a "homepage type." Themes can only express intent by writing arbitrary `home.twig` logic, and site admins have no way to change what the homepage shows without editing theme files. Admins need a way to pick what the homepage renders (e.g. a collection listing vs. a static page) from the admin dashboard, while still letting a theme express a sensible default so most installs need no configuration at all.

## What Changes

- Themes MAY declare a `homepage_type` default at the root of `theme.json` (e.g. `"collection_list"` or `"static_page"`). Absent a declaration, the system falls back to `collection_list` (today's behavior).
- The `settings` table gains a nullable `homepage_type` override column and, when `homepage_type` is `static_page`, a nullable `homepage_page_id` column referencing the chosen entry.
- The `/admin/settings` screen gains a "Homepage" section: a select showing the effective homepage type (theme default pre-selected, or the saved override), plus a page picker that appears when `static_page` is selected. Choosing "Use theme default" clears the override.
- `PublicController::home()` resolves the effective homepage type as: saved override (if set) → active theme's `theme.json` default → `collection_list` fallback. When the effective type is `static_page`, it renders the chosen entry (via `entry.twig`, matching how individual entries render today) instead of the collection listing; if the configured page no longer exists, it falls back to `collection_list` behavior.

## Capabilities

### New Capabilities
(none — this extends existing capabilities)

### Modified Capabilities
- `settings`: adds `homepage_type`/`homepage_page_id` fields to site settings storage and a Homepage section to the `/admin/settings` screen.
- `public-rendering`: the homepage route (`GET /`) branches on the effective homepage type instead of always rendering the collection list.
- `theme-management`: `theme.json` manifest parsing gains an optional top-level `homepage_type` field.

## Impact

- `src/Http/Controller/PublicController.php` (`home()` method)
- `src/Settings/Settings.php`, `src/Settings/SettingsRepository.php`
- `src/Http/Controller/SettingsAdminController.php`, `templates/admin/settings/index.twig`
- `src/Themes/ThemeManifestReader.php` (parse `homepage_type`)
- New migration adding `homepage_type`/`homepage_page_id` columns to `settings` (mysql + sqlite)
- No breaking changes: default behavior (`collection_list`, no override) is unchanged from today.
