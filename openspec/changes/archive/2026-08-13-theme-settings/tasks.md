## 1. Database

- [x] 1.1 Add `database/migrations/20260813000002_theme_settings.mysql.sql` creating `theme_settings(id, theme_slug, setting_key, value, created_at, updated_at)` with a unique index on `(theme_slug, setting_key)`.
- [x] 1.2 Add the matching `database/migrations/20260813000002_theme_settings.sqlite.sql`.

## 2. Manifest parsing (theme-management)

- [x] 2.1 Extend `ThemeInstaller::readManifest` to parse an optional `settings` array from `theme.json`, validating each entry has a non-empty `key` and a `type` in `{text, textarea, boolean, select, color, image}`; drop invalid entries silently.
- [x] 2.2 Extract a `ThemeSettingSchema` (or similar) value object/array shape carrying `key`, `label`, `type`, `default`, `options` per entry, returned alongside the existing `name`/`version`/`author` from `readManifest`.
- [x] 2.3 Add a way to read a theme's manifest settings schema independent of install (e.g. `ThemeManifestReader` used by both `ThemeInstaller` and the new admin controller/Twig global) so schema is read from disk per request without re-running install logic.
- [x] 2.4 Unit tests: valid settings array parsed; missing `key`/`type` dropped; unsupported `type` dropped; absent `settings` key behaves as before (empty schema, no failure).

## 3. Storage (theme-settings)

- [x] 3.1 Add `ThemeSettingsRepository` (modeled on `SettingsRepository`) with `valuesFor(string $slug, array $schema): array<string,string>` (stored value or schema default per key, keyed by setting key) and `save(string $slug, array<string,string> $values): void` (upsert by `(theme_slug, setting_key)`).
- [x] 3.2 `valuesFor` only returns keys present in the given `$schema` (dormant keys removed from the manifest are excluded, not deleted from the table).
- [x] 3.3 Unit tests: value falls back to schema default when unset; value omitted when key absent from schema (dormant); save upserts and a second `valuesFor` call reflects the new value; values for one slug are not returned for another slug.

## 4. Admin UI

- [x] 4.1 Add `ThemeSettingsAdminController` with `index` (resolve active theme, read its manifest schema, resolve values via `ThemeSettingsRepository::valuesFor`, render form; empty state when no active theme or empty schema) and `save` (validate submitted values against schema `type` — e.g. `boolean` normalized to `0`/`1`, `select` value must be in `options` or falls back to default — then persist via `ThemeSettingsRepository::save`).
- [x] 4.2 Register `GET /admin/theme-settings` and `POST /admin/theme-settings` routes in `src/Http/Routes.php`, admin-guarded like `/admin/mail-settings`.
- [x] 4.3 Add `templates/admin/theme-settings/index.twig` rendering one field per schema entry (text input, textarea, checkbox, select, color input, and a plain text field for `image` path/URL), pre-filled with resolved values.
- [x] 4.4 Add "Theme Settings" nav item to `templates/admin/_header.twig` (admin-only, alongside Themes) and a matching icon partial under `templates/admin/_icons/`.
- [x] 4.5 Integration test: admin views form pre-filled with defaults, submits new values, reload shows updated values; non-admin is rejected; empty state when active theme has no schema.

## 5. Twig exposure

- [x] 5.1 Register a `theme_settings` global in `TwigRenderer`, resolved per-render (same per-request re-sync pattern as `syncThemePaths`) from the active theme's manifest schema + `ThemeSettingsRepository::valuesFor`.
- [x] 5.2 Unit/integration test: a template referencing `{{ theme_settings.<key> }}` renders the configured value (HTML-escaped by default) or the manifest default when unset; renders empty when no active theme.

## 6. Dormant key persistence

- [x] 6.1 Integration test covering the full lifecycle: configure a value, delete the theme, re-upload a ZIP with the same slug but the key removed from `theme.json` (value stored but dormant, absent from form and `theme_settings`), then re-upload again with the key restored (value reappears).

## 7. Documentation

- [x] 7.1 Document the `theme.json` `settings` schema (fields, types, `select` options) and the `theme_settings` Twig global in the theme-building docs, including an explicit note on autoescaping and when a theme author would need `|raw`.
