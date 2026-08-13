## Why

Themes today require a developer to manually drop a `themes/<name>/` folder on the server and hand-edit `theme.active` in `config/app.yaml` followed by a redeploy. There's no admin UI, no upload path, and no way to switch or manage themes without shell/file access. This blocks non-developer admins from installing or switching themes and turns every theme change into a deploy event.

## What Changes

- New admin console page (`/admin/themes`) to upload a theme as a ZIP file.
- On upload: validate the ZIP (size limit, real-content MIME check, entry-count cap, path-traversal/zip-slip guard, no symlink entries, exactly one top-level theme folder, required templates present — `base.twig`, `home.twig`, `collection.twig`, `entry.twig`), then extract to `themes/<slug>/` and register it.
- Optional `theme.json` manifest (name/version/author) parsed from the ZIP root; falls back to a slug-derived name when absent, so the existing `themes/starter/` (no manifest) keeps working unmodified.
- Admin UI to list uploaded themes and activate one — **BREAKING (internal)**: active-theme selection moves from `config/app.yaml`'s `theme.active` to a new database-backed `themes` table, so activation takes effect immediately without a redeploy. `theme.active` in config remains as a fallback/seed value only.
- Admin UI to delete a non-active uploaded theme; the currently active theme cannot be deleted (enforced in both the UI and the repository layer).
- `TwigRenderer` and `AssetController` resolve the active theme through a shared resolver that reads the DB first and falls back to the existing config-based path if the DB is unreachable or the active theme's folder is missing on disk — so a DB outage or manual folder deletion degrades gracefully instead of 500ing every request.

## Capabilities

### New Capabilities
- `theme-management`: uploading, validating, listing, activating, and deleting themes via the admin console, including the DB-backed active-theme record and the ZIP validation/extraction rules.

### Modified Capabilities
- `theme-assets`: asset serving (`/assets/{path}`) now resolves the active theme via the shared DB-backed resolver instead of reading `theme.active` directly from `Configuration`; behavior/scenarios for existing requests are unchanged, only the source of "which theme is active" changes.

## Impact

- New: `src/Themes/` (`Theme`, `ThemeRepository`, `ThemeInstaller`, `ActiveThemeResolver`), `src/Http/Controller/ThemesAdminController.php`, `templates/admin/themes/`, new DB migration (`themes` table).
- Modified: `src/View/TwigRenderer.php`, `src/Http/Controller/AssetController.php`, `src/Http/Routes.php` (constructor/wiring changes), `templates/admin/_header.twig` (nav entry), `config/app.yaml` (new `theme.max_zip_bytes` key), `composer.json` (declare `ext-zip`, already used implicitly for backups).
- Dependencies: uses PHP's built-in `\ZipArchive` (already used by `src/Backups/`), no new third-party package.
