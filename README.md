# pressless

A PHP CMS for people who want to publish a website without installing plugins, fighting a 2010-era admin, or learning PHP.

The vibe: opinionated, quiet, modern. Ghost-class admin, Linear-feel polish, zero chrome. Do the common cases well and refuse the rest — no plugin soup, no settings rabbit holes, no notification noise.

## What it is

- **Typed content.** Collections → Fields → Entries. No free-form field soup.
- **Calm admin.** Ghost-inspired UX. No banners, no upsells.
- **Self-hosted.** LAMP-style: PHP 8.2+, MySQL/MariaDB, Twig templates.
- **Small core.** Hand-rolled HTTP/router/DB layer. Composer only where it earns it.
- **Sane defaults.** Most sites need zero config after install.

## What it isn't

- Not WordPress. Not trying to be.
- Not a plugin platform. Extension points are designed, not emergent.
- Not a block builder. Typed fields instead of drag-and-drop.
- Not headless. Public site is first-class.
- Not enterprise. One site per install, simple roles.

## Who it's for

- **End users** — non-technical folks self-hosting on cPanel, shared hosting, or a small VPS.
- **Designers/devs** — building/maintaining sites for the above, wanting a saner foundation than WP.
- **Evaluators** — `git clone` → `bin/serve` → browser, sample data with `--seed`.

## Workflow

This project is built spec-first via [OpenSpec](openspec/). Each phase is one round: proposal → specs → tasks → implement → verify → archive. See [`docs/prd.md`](docs/prd.md) for the full product requirements and phased roadmap.

Current phase: **1 — Foundations.** Goal: `git clone`, `bin/serve`, log in to an empty admin.
