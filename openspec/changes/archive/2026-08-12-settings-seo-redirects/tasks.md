## 1. Settings

- [x] 1.1 Add `settings` migration pair (mysql + sqlite): single-row table, `site_name`, `timezone`, `date_format`, timestamps
- [x] 1.2 Build `src/Settings/Settings.php` value object + `SettingsRepository` (`load()`/`save()`, defaults when no row exists), mirroring `MailSettings`/`MailSettingsRepository`
- [x] 1.3 Build `SettingsAdminController` (`index()`, `save()` with timezone validation) + `templates/admin/settings/index.twig`, mirroring the mail-settings admin screen
- [x] 1.4 Wire `/admin/settings` routes (admin-only guard) and add a "Settings" admin nav entry in `src/Http/Routes.php`

## 2. Entry SEO fields

- [x] 2.1 Add `entries` SEO columns migration pair (mysql + sqlite): `meta_title TEXT NULL`, `meta_description TEXT NULL`, `og_image_id INTEGER NULL` (FK → media)
- [x] 2.2 Update `EntryRepository::hydrate()` to read the three SEO columns onto the entry
- [x] 2.3 Update `EntryRepository::save()` to persist the three SEO columns
- [x] 2.4 Add an "SEO" section to the entry edit template with `meta_title`, `meta_description`, and an `og_image` media picker; preserve values on validation failure

## 3. Redirects

- [x] 3.1 Add `redirects` migration pair (mysql + sqlite): `old_path` (unique), `new_path`, timestamps
- [x] 3.2 Build `src/Content/Redirect.php` value object + `RedirectRepository` (`findByOldPath()`, `upsert()`, `delete()`, `all()`)
- [x] 3.3 Build `RedirectAdminController` (list, add, delete) + `templates/admin/redirects/index.twig`
- [x] 3.4 Wire `/admin/redirects` routes (admin-only guard) and add a "Redirects" admin nav entry

## 4. Auto-redirect on slug change

- [x] 4.1 Inject `RedirectRepository` into `EntryRepository`
- [x] 4.2 In `EntryRepository::save()`, when `$sourceChanged` produces a slug different from the existing one, upsert a redirect (old public path → new public path) inside the same transaction
- [x] 4.3 Confirm a brand-new entry's first save does not create a redirect (no prior slug to redirect from)

## 5. Public redirect resolution

- [x] 5.1 Inject `RedirectRepository` into `PublicController`
- [x] 5.2 In `PublicController::entry()`, before each existing `404` return, look up the requested path in `redirects` and return an HTTP 301 to `new_path` on a match
- [x] 5.3 Confirm a live, published entry always resolves normally even if its path also matches a stale `redirects.old_path` row

## 6. Tests

- [x] 6.1 Unit tests for `SettingsRepository` (defaults, upsert)
- [x] 6.2 Unit tests for `RedirectRepository` (create, upsert-on-duplicate-old-path, delete)
- [x] 6.3 Integration test: save an entry with SEO fields → read it back → values persisted
- [x] 6.4 Smoke test (per PRD): change an entry's slug → request the old public URL → confirm 301 to the new URL → confirm the new URL renders the entry
