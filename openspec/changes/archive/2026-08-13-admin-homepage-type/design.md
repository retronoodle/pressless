## Context

`PublicController::home()` always renders `home.twig` with every collection — there is no concept of a "homepage type" anywhere in the code. Site settings live in a single fixed-column `settings` row (`src/Settings/Settings.php` / `SettingsRepository.php`); themes declare metadata and per-theme key/value settings via an optional `theme.json` parsed by `ThemeManifestReader`. This change introduces a homepage type concept that a theme can default and an admin can override, without disturbing either existing mechanism.

## Goals / Non-Goals

**Goals:**
- Let a theme express a default homepage type via `theme.json`.
- Let an admin override that default from `/admin/settings`, or clear the override to fall back to the theme's default.
- Support two homepage types at launch: `collection_list` (today's behavior — unchanged) and `static_page` (render one chosen entry as the homepage).
- Preserve current behavior exactly when nothing is configured (no theme default, no override).

**Non-Goals:**
- Arbitrary/custom homepage types beyond `collection_list` and `static_page` (theme authors already have full control via `home.twig` if they need something bespoke).
- Per-language or per-environment homepage overrides.
- Migrating existing per-theme settings (`theme_settings` table) — homepage type is a site-wide setting, not a per-theme key/value setting, since it must persist across theme switches independent of which theme is active.

## Decisions

**Store the override on the existing `settings` table, not `theme_settings`.** The `theme_settings` table is keyed by `(theme_slug, setting_key)` and is wiped from relevance when the active theme changes. Homepage type is a site-wide admin decision that should survive a theme switch (re-resolving against the *new* theme's default only when there's no override), so it belongs alongside `site_name`/`timezone`/`date_format` in the single-row `settings` table. Two nullable columns: `homepage_type` (`'static_page'|NULL`; NULL means "use theme default") and `homepage_page_id` (entry id, only meaningful when `homepage_type = 'static_page'`). Storing `collection_list` explicitly vs. NULL was considered; NULL-as-"inherit" was chosen so clearing the override is a single, unambiguous state distinct from an admin explicitly picking `collection_list` — both are handled identically at render time, but NULL round-trips cleanly through "theme changed, no override" scenarios in future work.

**Theme default lives at the top level of `theme.json`, not inside the existing `settings` array.** The existing `settings` array (from the theme-settings feature) is theme-specific key/value data rendered through `ThemeSettingsAdminController`. Homepage type is a distinct, first-class concept the platform itself understands and branches on in `PublicController`, so it gets its own top-level `homepage_type` string field in the manifest, parsed by `ThemeManifestReader` alongside `name`/`version`/`author`. Invalid/unrecognized values are treated as absent (fall back to `collection_list`), matching the manifest reader's existing "never fail the parse" posture.

**Resolution order, computed per-request in `PublicController::home()`:** saved `settings.homepage_type` override (if not NULL) → active theme's `theme.json` `homepage_type` → `collection_list`. When resolved to `static_page`, look up `homepage_page_id`; if the entry no longer exists (deleted since being configured), silently fall back to `collection_list` rendering for that request rather than erroring, consistent with how the app already degrades gracefully (e.g. missing manifest fields).

**Reuse `entry.twig` for `static_page` rendering.** Rather than inventing a new template, the chosen entry renders exactly as `PublicController`'s existing entry route renders it, so themes don't need new template files to support this feature.

## Risks / Trade-offs

- [Admin picks `static_page` then deletes the chosen page] → Mitigated by the graceful fallback to `collection_list` described above; no 500s or broken homepage.
- [Theme is switched and the new theme has no `homepage_type` default while an admin override exists] → Override still applies (it's site-wide, not theme-scoped) — this is intentional per the Decisions section, but worth calling out since it could surprise an admin who forgot they'd set an override.
- [Two new nullable columns on a fixed-schema single-row table] → Low risk; matches the existing migration pattern already used for `settings`.

## Migration Plan

1. Add migration(s) for `settings.homepage_type` (nullable string) and `settings.homepage_page_id` (nullable integer, FK-like reference to `entries.id`, no enforced foreign key needed since the app already tolerates dangling references gracefully) — mysql + sqlite variants, following the existing `database/migrations/` naming convention.
2. Extend `ThemeManifestReader` to parse the optional top-level `homepage_type`.
3. Extend `Settings`/`SettingsRepository` to read/write the two new fields (defaulting to NULL).
4. Update `SettingsAdminController` + `templates/admin/settings/index.twig` with the Homepage section (type select + conditional page picker).
5. Update `PublicController::home()` to resolve and branch on the effective homepage type.
6. No rollback complexity: dropping the columns and reverting the controller restores today's behavior exactly, since NULL/absent always maps to current behavior.
