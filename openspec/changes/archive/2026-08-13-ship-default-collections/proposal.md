## Why

Nearly every install needs `posts` and `pages` collections, but today they're only created when the admin opts into sample data during install. Anyone who declines (or upgraded from a version predating this) lands on an empty Content screen with no path forward.

## What Changes

- `InstallerController::complete` seeds the `posts` and `pages` collections unconditionally, regardless of the sample-data answer.
- `Seeder` gains a small, reusable `seedDefaultCollections()` (built on a `seedCollection(slug, name, schema)` primitive) that both the installer and the new settings action call — no second hand-rolled `INSERT`.
- A new admin-only `POST /admin/settings/seed-default-collections` endpoint runs the same seeding path for existing sites that skipped it, reporting how many collections it created (or that they're already present) via flash/error redirect, mirroring the backups "run" action.
- Sample-data opt-in during install keeps seeding the three sample `posts` entries whenever the `posts` collection exists — unchanged behavior, now decoupled from whether `posts` itself needed creating.
- Seeding is idempotent: existing `posts`/`pages` (or a same-slug collection with a different schema) is left untouched; only missing collections are created.

## Capabilities

### New Capabilities

(none — this reuses and extends existing web-installer and settings capabilities)

### Modified Capabilities

- `web-installer`: `InstallerController::complete` now seeds default `posts` and `pages` collections on every install, independent of the sample-data choice.
- `settings`: adds an admin-only action to seed the default `posts`/`pages` collections on demand, for sites that skipped them at install time.

## Impact

- `src/Installer/Controller/InstallerController.php` — `complete()` calls default-collection seeding unconditionally; sample-data branch continues to seed entries only.
- `src/Console/Seeder.php` — refactor to expose `seedDefaultCollections()` / `seedCollection()` as the single collection-creation path; `seed()` (used by `bin/serve` sample data) calls the same methods.
- `src/Http/Controller/SettingsAdminController.php` (or a new controller) — new `seedDefaultCollections` action.
- `src/Http/Routes.php` — new `POST /admin/settings/seed-default-collections` route, admin-gated, near L344.
- `templates/admin/settings/index.twig` — new button/section mirroring `templates/admin/backups/index.twig`'s "Run a backup now" block.
- Release notes: document that `posts` and `pages` are now created on every install.
  Draft release-notes copy:

  > **Default collections on every install.** New installs now create the `posts` and `pages` collections automatically, regardless of whether you opt into sample data. Declining sample data no longer leaves you on an empty Content screen.
  >
  > Sites that previously declined sample data can create the missing collections from **Settings → Seed default collections** (admin only). The action is idempotent — existing collections are never overwritten.
- Tests: installer completion test without `sample_data=yes` now asserts both collections exist; `Seeder` idempotency test for `seedDefaultCollections()`; existing fixtures using the `posts` slug are unaffected.
