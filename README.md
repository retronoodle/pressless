# Stead

A PHP CMS for people who want to publish a website without plugin soup, a 2010-era admin, or learning PHP.

WordPress runs ~40% of the web and still ships an admin from a decade and a half ago — banners, settings rabbit holes, a plugin/hook model where every install becomes a unique pile of third-party UI. Stead is the other bet: opinionated, quiet, modern. Ghost-class admin, Linear-feel polish, zero chrome. Do the common cases well and refuse the rest.

## Status

Early days — most of what's below is the destination, not the current state.

**Built**
- **Typed content:** Collections → Fields → Entries, 8 core field types
  - Admin CRUD for collections/entries
- **Drafts:** explicit publish/unpublish (entries default to draft; public routes filter to published)
- **Revisions:** per-entry history with restore, configurable retention
- **Auth & admin shell:** session-based
- **Roles & permissions:** fixed admin/editor/author roles with per-collection, per-action grants and author ownership scoping on edit/delete/publish
  - Admin UI at `/admin/users` for user list, role assignment, and permission editing
- **Login security:** rate limiting and lockout after repeated failed attempts
- **Public rendering:** theme-aware Twig templates, a starter theme, paginated collection/entry pages
- **Page cache:** file-based for public routes, keyed by collection version, with auto-invalidation on entry changes
- **Asset route:** `/assets/{path}` serving static files from the active theme's `assets/` directory
- **Media library:** uploads with mime/size validation, on-demand GD image transforms, served over a traversal-guarded route
- **SMTP mail:** configurable transport with admin UI + test send
- **Invites:** single-use hashed tokens with expiry; public acceptance flow creates the user with the invited role
- **Web installer:** multi-step wizard at `/install/*` reachable only when no `installed.lock` exists, so a downloaded release ZIP becomes a working site through the browser alone — no terminal required
- **Release pipeline:** `bin/release` builds a version-stamped, dev-stripped, production-only dist ZIP; a tagged-push CI workflow builds and publishes it automatically
- **Update checker:** installed sites compare their `VERSION` file against the latest published release (cached, fails closed on any endpoint error) and surface an admin dashboard banner with manual update instructions
- **Backups & restore:** `bin/backup` dumps the database (`mysqldump`, PDO fallback, or a SQLite file copy) and the media directory into a single archive, written to a local path or an S3-compatible target; scheduled via cron + `--scheduled`, with configurable retention pruning, an admin UI for settings/history, and a restore flow (CLI + admin UI, both requiring explicit confirmation). A backup is triggered automatically before the update-instructions page is shown, so a failed update has a rollback path.
- **Site settings:** admin UI at `/admin/settings` for site name, timezone, and date format, single-row storage with sane defaults before first save
- **Entry SEO:** `meta_title`, `meta_description`, and an `og_image` media picker on every entry's edit form, persisted alongside the entry regardless of collection
- **Redirects:** `redirects` table with an admin UI at `/admin/redirects` for manual old-path → new-path entries; entry slug changes auto-create a redirect from the old public path to the new one, and public entry requests that would 404 check redirects first, responding with a 301

**Not yet built**
- Plugin system (described below)

## Principles

- **Typed content, not field soup.** Collections → Fields → Entries. Every entry has a schema; no arbitrary custom fields, no brittle queries.
- **Plugins are first-class, but designed.** A fixed set of plugin capabilities (themes, field types, repositories, admin extensions, routes, template slots, lifecycle hooks), each with a contract and a boundary — not an open hook system. No plugin soup.
- **Calm admin.** No banners, no upsells, no notification noise. Ghost is the UX reference, Linear the feel.
- **Sane defaults.** Most sites need zero configuration after install.
- **From-scratch core.** Hand-rolled HTTP/router/DB layer. Composer only where it earns it — no Symfony framework, no Laravel, no ORM.
- **Different tools for different audiences.** A dev server for evaluators and contributors; a web installer for everyone else. Neither pretends to be the other.

## What it is

- **Typed content.** Collections → Fields → Entries, with 8 core field types (text, richtext, number, boolean, date, select, media, relation).
- **Calm admin.** Ghost-inspired UX with drafts, publish/unpublish, and revision history.
- **Media library.** Uploads with image transforms, used through a media field type.
- **Roles & permissions.** Admin/editor/author roles, scoped per collection.
- **Reliable outbound mail.** SMTP-first, not PHP's `mail()` — admin-configurable with a test send.
- **Invites.** Admins invite by email + role; single-use hashed tokens, public acceptance flow.
- **Login security.** Rate limiting and lockout after repeated failed attempts.
- **Backups built in.** Scheduled DB + media backups to a configurable target, with a restore flow.
- **SEO-ready out of the box.** Per-entry meta title/description and social image, plus automatic redirects on slug changes so published URLs don't rot.
- **Plugins.** Themes, field types, repositories, and admin extensions ship as plugins through a designed plugin model — not an open hook soup.
- **Self-hosted.** LAMP-style: PHP 8.2+, MySQL/MariaDB, Twig templates.
- **Small core.** Hand-rolled HTTP/router/DB layer. Composer only where it earns it.

## Building plugins

WordPress's hook/filter model lets a plugin touch nearly any point in core, in any order, with no isolation — that's what produces plugin soup, not the number of things plugins can do. Stead instead names a fixed set of capabilities, each with a real contract:

- **Theme** — Twig templates, full control of entry/collection/homepage markup.
- **Field type** — a new typed field usable on any collection (color picker, SEO title/description pair, whatever you need).
- **Repository** — swap or extend the data layer behind `Entry`/`Collection`/`User`/`Media`.
- **Admin extension** — your own page/panel in the admin, outside the core content screens.
- **Namespaced route** — HTTP endpoints under `/plugins/{slug}/*`, for webhooks and redirects (Stripe-style receivers, affiliate links).
- **Reserved core endpoint** — claim a conventional top-level path like `/sitemap.xml` or `/robots.txt`, conflict-checked at install.
- **Template slot** — push into fixed injection points (`head`, `before_body_end`, `entry_meta`) for tracking snippets, SEO meta, structured data.
- **Lifecycle hook** — subscribe to a versioned, core-owned event list (`entry.after_publish`, `user.after_login`, `media.after_upload`, …) with typed, immutable payloads. Handlers are fault-isolated — your bug can't take down someone else's plugin or the request.
- **Plugin migration** — ship your own versioned SQL migrations through the core runner, tables namespaced to `plugin_{slug}_*`, with a declared uninstall behavior (drop or keep data).
- **Raw SQL escape hatch** — for the rare case none of the above covers. Opt-in, disclaimed, not the default path.

If a real plugin idea can't be expressed by this list, that's a signal for core to add a new *designed* capability — not to open a general hook system.

Distribution is `composer require` or a ZIP upload through the admin. A `bin/plugin` CLI scaffolds, validates, and packages plugins, and a discovery site lets people find and install yours. A full authoring guide is planned alongside the plugin discovery site.

## What it isn't

- Not WordPress. Not trying to be.
- Not a block builder. Typed fields instead of drag-and-drop.
- Not headless. Public site is first-class.
- Not enterprise. One site per install, simple roles.

## Who it's for

- **End users** — non-technical folks self-hosting on cPanel, shared hosting, or a small VPS. They download a release ZIP and go through a web installer — no `git clone`, no terminal.
- **Designers/devs** — building/maintaining sites for the above, wanting a saner foundation than WP.
- **Plugin authors** — shipping themes, field types, repositories, or admin extensions through a designed plugin model + discovery site.
- **Evaluators** — `git clone` → `bin/serve` → browser, sample data with `--seed`.

Roadmap and full product requirements are tracked internally.

## Installing Stead (release ZIP)

For non-technical users — a cPanel/shared-hosting audience — installing Stead is a five-minute browser exercise. No terminal, no `git`, no hand-edited config files.

1. **Upload the release ZIP** to your hosting account and unzip it into the document root for the domain you want Stead to serve (often `public_html/`).
2. **Make sure the project root is writable by the web server.** Stead writes `.env` and `installed.lock` at the project root during the wizard, so the user the web server runs as needs write permission on the directory the ZIP was extracted into. On shared hosts, that's usually handled by uploading as the same user the web server uses, or running `chmod -R u+rwX` once after extraction.
3. **Visit the site in a browser.** If the document root points at the `public/` directory, go straight to `https://your-domain/`. If it points at the project root, go to `https://your-domain/public/`.
4. **Walk through the wizard.** It has four short steps:
   - **Database connection** — pick MySQL/MariaDB/SQLite and supply credentials. Stead opens a real connection and runs a trivial query before saving anything, so bad credentials are caught here with a clear, actionable error.
   - **First administrator** — email, display name, and password (same requirements as the rest of the auth system).
   - **Sample data** — opt in or out. Either choice ends at the admin login.
   - **Finish** — Stead writes `.env`, runs migrations, creates the administrator, and drops an `installed.lock` file. The installer becomes permanently unreachable after this step.
5. **Sign in at `/admin/login`** with the credentials from step 4. From there, create a collection and start publishing.

If the installer is reachable but no DB connection succeeds, the most common cause is filesystem permissions on the project root — the wizard surfaces the exact path that couldn't be written. Once `installed.lock` exists, `/install/*` redirects to `/admin` and the only way back in is to delete that file (which is the correct behavior: the installer is destructive to in-progress state).

## Releasing and updating

Maintainers build a dist ZIP with `bin/release <version>` (e.g. `bin/release 1.2.3`) — it installs production-only dependencies, strips dev/test files (`tests/`, `.git/`, `openspec/`, `phpunit.xml`, `phpstan.neon`, `.env`), stamps a `VERSION` file, and zips the result. Pushing a `vX.Y.Z` tag runs the same build in CI and publishes the ZIP to the project website's release endpoint automatically.

Installed sites read their own `VERSION` file and periodically check that endpoint for a newer release (interval configurable via `UPDATE_CHECK_INTERVAL_HOURS`; leave `UPDATE_ENDPOINT_URL` empty to disable checks entirely). If the endpoint is unreachable or errors, the checker fails closed — no admin-facing error, just no update notice. When a newer version is available, admins see a banner on `/admin` linking to `/admin/update` for manual download-and-extract instructions; v1 has no one-click apply.

## Backups and restore

`bin/backup` creates a single archive (DB dump + media directory + manifest) and writes it to the configured storage target (`config/app.yaml`'s `backups:` section — local path by default, or an S3-compatible bucket via `BACKUPS_S3_*` env vars). Runs are tracked in a `backups` table and manageable from `/admin/backups` (settings, history, manual run, restore).

```
bin/backup                 # manual run
bin/backup --scheduled     # run only if the configured frequency has elapsed
bin/backup:restore <id>    # restore DB + media from a backup, with a confirmation prompt (--yes to skip)
```

Scheduling relies on an external cron entry rather than a background worker — add something like `* * * * * php bin/backup --scheduled` to the host's crontab; the admin UI's settings page shows the exact line to use. Restoring from the admin UI requires a separate confirmation step before it touches the database or media directory, same as the CLI. A backup also runs automatically right before the update-instructions page is shown, so a failed update has a rollback point.

### Trying it locally against real MySQL

A `docker-compose.yml` at the repo root brings up PHP + MySQL + nginx on a single host network for prod-parity exercising of the installer against real MySQL (instead of only the dev-mode SQLite path):

```
docker compose up -d
open http://localhost:8080
```

The MySQL service is provisioned with a `stead` database and `stead` user. Those are the credentials to enter on the installer's database step.
