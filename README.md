# Stead

A PHP CMS for people who want to publish a website without plugin soup, a 2010-era admin, or learning PHP.

WordPress runs ~40% of the web and still ships an admin from a decade and a half ago — banners, settings rabbit holes, a plugin/hook model where every install becomes a unique pile of third-party UI. Stead is the other bet: opinionated, quiet, modern. Ghost-class admin, Linear-feel polish, zero chrome. Do the common cases well and refuse the rest.

## Status

Early days — most of what's below is the destination, not the current state. Built so far: typed collections/entries (Collections → Fields → Entries, 8 field types), the admin CRUD for them, drafts with explicit publish/unpublish (entries default to draft; public routes filter to published), per-entry revision history with restore (configurable retention), session-based auth + admin shell, public site rendering (theme-aware Twig templates, a starter theme, paginated collection/entry pages), a file-based page cache for public routes keyed by collection version with auto-invalidation on entry changes, a `/assets/{path}` route serving static files from the active theme's `assets/` directory, and a media library (uploads with mime/size validation, on-demand GD image transforms, served over a traversal-guarded route). Not yet built: roles & permissions, mail/invites, login rate limiting, backups, the web installer, and the entire plugin system described below.

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
- **Reliable outbound mail.** SMTP-first, not PHP's `mail()` — used for invites and notifications.
- **Login security.** Rate limiting and lockout after repeated failed attempts.
- **Backups built in.** Scheduled DB + media backups to a configurable target, with a restore flow.
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
