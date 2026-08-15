## Why

The homepage type mechanism (added in `2026-08-13-admin-homepage-type`) currently recognises two values: `collection_list` (links to every collection) and `static_page` (one chosen entry). Neither fits a common case: a site whose homepage should just be a running feed of its most recent posts — titles only, paginated — without an admin having to write a custom `home.twig`. A `blog` homepage type gives admins that out of the box, and a follow-up change will wire the rendering and admin UI.

This change lays the **data foundation only** so that `blog` becomes a recognised value across the codebase:

- `ThemeManifestReader` accepts `blog` as a `homepage_type` value in `theme.json` (matching the existing soft-failure semantics for unrecognised values).
- The single-row `settings` table gains a nullable `homepage_collection_id` column to pair with a `blog` override, alongside the existing `homepage_page_id` for `static_page`.
- The `Settings` value object and `SettingsRepository` read/write the new column and widen `normaliseHomepageType()` to accept `blog`.

The actual homepage rendering branch (`PublicController::resolveHomepage()`/`home()`), the admin settings UI changes (radio option + collection picker), the recency-ordered repository method, and the new `home-blog.twig` templates are intentionally **out of scope here** — they belong in a follow-up change so this one stays focused on the data layer and is easy to verify.

## What Changes

- `ThemeManifestReader::HOMEPAGE_TYPE_BLOG` joins the recognised manifest values and `HOMEPAGE_TYPES` is extended accordingly; invalid values still fall back to "absent" exactly like the existing `static_page` path.
- `settings.homepage_collection_id` becomes a nullable integer column on the existing single-row `settings` table, with no behaviour change until the follow-up change wires it into the homepage renderer.
- `Settings::$homepageCollectionId` and `SettingsRepository::save()`/`load()` round-trip the new column, defaulting to `NULL`. `Settings::normaliseHomepageType()` is widened to accept `blog`.

## Capabilities

### New Capabilities
(none — this extends existing capabilities)

### Modified Capabilities
- `settings`: adds `homepage_collection_id` to the single-row site settings storage and widens the recognised `homepage_type` override values to include `blog`. Reading and writing the new column is supported but no rendering or admin UI behaviour is added in this change.
- `theme-management`: `theme.json` manifest parsing recognises `blog` as a valid `homepage_type` value (alongside `collection_list`/`static_page`).

## Impact

- New migration adding `homepage_collection_id` to `settings` (mysql + sqlite).
- `src/Themes/ThemeManifestReader.php` (add `HOMEPAGE_TYPE_BLOG`, extend `HOMEPAGE_TYPES`).
- `src/Settings/Settings.php`, `src/Settings/SettingsRepository.php` (new `homepageCollectionId` field/column; widened `normaliseHomepageType()`).
- Tests: extend `ThemeManifestReader` tests for `blog`; extend `Settings`/`SettingsRepository` tests for the new column.
- No rendering, admin UI, or template changes — purely additive to the data layer; existing `collection_list`/`static_page` behaviour is unchanged.