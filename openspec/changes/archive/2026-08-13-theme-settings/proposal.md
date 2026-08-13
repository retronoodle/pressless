## Why

Themes currently cannot expose configurable options to admins — anything a theme wants to make adjustable (hero image, sidebar visibility, accent color, etc.) has to be hardcoded in the theme's templates. Theme authors need a declarative way to define settings, and admins need a place to configure them per active theme.

## What Changes

- Extend the `theme.json` manifest schema with an optional `settings` array (`key`, `label`, `type`, `default`, `options`) parsed by `ThemeInstaller::readManifest`.
- Add a `theme_settings` table (`theme_id`, `key`, `value`) and a `ThemeSettingsRepository`, modeled on `SettingsRepository`, namespaced per theme so switching themes never leaks one theme's values into another's.
- Add a "Theme Settings" admin tab (`/admin/theme-settings`) that renders a form generated from the active theme's manifest schema, pre-filled with stored values or manifest defaults.
- Register a `theme_settings` Twig global in `TwigRenderer` so theme templates can read `{{ theme_settings.hero_image }}`.
- Preserve stored setting values across theme re-upload/reactivation for keys that still exist in the manifest; values for keys removed from a re-uploaded manifest are kept dormant (not deleted), since they cost nothing to retain and may be needed again if the key returns.
- HTML-capable field types (`textarea`) are rendered in Twig via manual escaping guidance for theme authors; the `theme_settings` global itself returns raw stored strings and relies on Twig's default autoescaping unless a theme author explicitly opts into `|raw`.
- Document the manifest schema, template usage, and escaping guidance for theme developers.

## Capabilities

### New Capabilities
- `theme-settings`: storage, admin UI, and Twig exposure for theme-defined configuration values.

### Modified Capabilities
- `theme-management`: `theme.json` manifest parsing gains an optional `settings` array alongside the existing `name`/`version`/`author` fields.

## Impact

- **Code**: `src/Themes/ThemeInstaller.php` (manifest parsing), new `src/Themes/ThemeSettingsRepository.php` + value object, new `src/Http/Controller/ThemeSettingsAdminController.php`, `src/View/TwigRenderer.php` (new global), `templates/admin/_header.twig` (new nav item), new `templates/admin/theme-settings/index.twig`.
- **Database**: new migration for `theme_settings` table (MySQL + SQLite variants, following the existing `settings`/`mail_settings` pattern).
- **Docs**: theme-building documentation gains a section on declaring and reading settings.
- **No breaking changes**: existing themes without a `settings` key in `theme.json` continue to work unchanged; the admin tab renders an empty form for such themes.
