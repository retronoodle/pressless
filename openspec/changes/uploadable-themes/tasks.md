## 1. Database

- [ ] 1.1 Add `database/migrations/20260813000001_themes.mysql.sql` — `themes` table (`id, slug UNIQUE, name, version, author, is_active, created_at, updated_at`), seed `starter` row as active
- [ ] 1.2 Add `database/migrations/20260813000001_themes.sqlite.sql` — same schema/seed for SQLite

## 2. Domain classes

- [ ] 2.1 Add `src/Themes/Theme.php` — readonly value object with `fromRow()`
- [ ] 2.2 Add `src/Themes/ThemeRepository.php` — `listAll()`, `findActive()`, `findBySlug()`, `create()`, `activate()` (transactional single-active flip), `delete()` (rejects deleting the active theme)
- [ ] 2.3 Add `src/Themes/ActiveThemeResolver.php` — `resolveThemeDirectory()`: DB active theme (dir must exist) → config `theme.active` fallback → null; catches DB exceptions and falls back rather than throwing
- [ ] 2.4 Add `src/Themes/ThemeInstaller.php` — `install(UploadedFile $file)`: size check, real-MIME check, `ZipArchive` open, full entry-name validation pass (traversal/absolute-path/symlink rejection, entry-count cap), single-top-level-folder + slug derivation/sanitization, slug-collision rejection, required-template presence check, extract to temp dir then atomic `rename()` into `themes/<slug>/`, optional `theme.json` parsing with fallback

## 3. Wiring

- [ ] 3.1 Update `src/View/TwigRenderer.php` constructor to take `ActiveThemeResolver`; remove `resolveThemePath()`, use resolver instead
- [ ] 3.2 Update `src/Http/Controller/AssetController.php` constructor to take `ActiveThemeResolver`; update `resolveAssetsRoot()` to use it
- [ ] 3.3 Update `src/Http/Routes.php` (`create()`, `register()`, `createWithStore()`) to construct `ThemeRepository`/`ActiveThemeResolver` and pass into `TwigRenderer`/`AssetController`
- [ ] 3.4 Grep `tests/` for direct `new TwigRenderer(` / `new AssetController(` calls and update each for the new constructor signature
- [ ] 3.5 Add `defaults.theme.max_zip_bytes` to `config/app.yaml`
- [ ] 3.6 Add `"ext-zip": "*"` to `composer.json` `require`

## 4. Admin controller and routes

- [ ] 4.1 Add `src/Http/Controller/ThemesAdminController.php` — `index()`, `upload()`, `activate()`, `delete()` (admin-gated, same pattern as `SettingsAdminController`)
- [ ] 4.2 Register `/admin/themes` (GET), `/admin/themes` (POST upload), `/admin/themes/{id}/activate` (POST), `/admin/themes/{id}/delete` (POST) in `src/Http/Routes.php`, guarded with `$guard->protect($collectionAuth->requireAdmin(...))`

## 5. Admin UI

- [ ] 5.1 Add `templates/admin/themes/index.twig` — upload form, theme list table with activate/delete actions (delete hidden for active theme)
- [ ] 5.2 Add `templates/admin/_icons/themes.twig`
- [ ] 5.3 Add "Themes" nav entry to `templates/admin/_header.twig` inside the admin-only block

## 6. Tests

- [ ] 6.1 Add `tests/Unit/Themes/ThemeRepositoryTest.php` — create/list/findActive/findBySlug, activate invariant, delete rejects active
- [ ] 6.2 Add `tests/Unit/Themes/ThemeInstallerTest.php` — valid zip, traversal entry rejected, missing required template rejected, missing/malformed manifest fallback, duplicate slug rejected, oversized/non-zip rejected
- [ ] 6.3 Add `tests/Unit/Themes/ActiveThemeResolverTest.php` — DB active theme used, missing dir falls back to config, DB exception falls back to config, nothing resolves returns null
- [ ] 6.4 Update existing `TwigRenderer`/`AssetController` tests for the new constructor argument

## 7. Manual verification

- [ ] 7.1 Upload a re-zipped copy of `themes/starter/` under a new slug, confirm it lists correctly
- [ ] 7.2 Activate it, confirm `/`, a collection page, and an entry page render via it, and `/assets/*` serves from it
- [ ] 7.3 Upload a ZIP with a `../../` entry, confirm rejected with nothing written outside `themes/`
- [ ] 7.4 Attempt to delete the active theme via direct POST, confirm rejected
- [ ] 7.5 Delete a non-active theme, confirm DB row and directory both removed
- [ ] 7.6 Rename an active theme's directory on disk to simulate drift, reload a page, confirm graceful fallback instead of a 500
