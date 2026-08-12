## Why

Sites currently have no place to configure basic identity (name, timezone, date format), no SEO metadata on entries, and no safety net when an editor renames a slug — the old URL just 404s, breaking inbound links and search rankings. Phase 13 closes these three gaps with a small, self-contained set of admin screens and a redirect hook into the existing slug-change path.

## What Changes

- Add a `settings` table (single row) holding `site_name`, `timezone`, `date_format`, editable via a new admin screen, mirroring the existing mail-settings pattern.
- Add three nullable SEO columns to `entries` (`meta_title`, `meta_description`, `og_image_id` FK → media) and surface them as an extra section on the existing entry edit form.
- Add a `redirects` table (`old_path`, `new_path`, timestamps) with an admin screen to list, add, and delete redirects manually.
- Auto-create a redirect row whenever an entry save changes its slug (hooking into `EntryRepository::save()`'s existing slug-change detection).
- Wire redirect lookup into the public 404 path (`PublicController`) so a matched `old_path` issues a 301 to `new_path` instead of rendering the 404 page.

## Capabilities

### New Capabilities
- `settings`: site-wide settings (site name, timezone, date format) — single-row storage, admin read/write screen.
- `redirects`: redirect storage, admin CRUD screen, and public-path lookup used to 301 old URLs to new ones.

### Modified Capabilities
- `entries`: entry persistence and the entry edit form gain three optional SEO fields (`meta_title`, `meta_description`, `og_image`); saving an entry with a changed slug now also creates a redirect record.
- `public-rendering`: the existing "unknown entry slug" and "unknown collection slug" 404 paths now first check the redirects table and issue a 301 on a match before falling back to 404.

## Impact

- New migrations: `settings`, `redirects`, `entries` SEO columns (mysql + sqlite pairs).
- New code: `src/Settings/Settings.php` + `SettingsRepository.php`, `src/Content/Redirect.php` + `RedirectRepository.php`, `SettingsAdminController`, `RedirectAdminController`, two new admin templates, admin nav entries.
- Changed code: `EntryRepository::hydrate()`/`save()` (SEO columns + redirect-on-slug-change), entry edit template/controller (new form section), `PublicController` (redirect lookup before 404), `Routes.php` (new wiring + routes).
- No breaking changes to existing tables or APIs; all new columns are nullable and additive.
