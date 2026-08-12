## Why

Stead's only current install path is `git clone` + hand-edited `.env` + `bin/migrate` — fine for evaluators, unusable for the non-technical cPanel/shared-hosting user the PRD names as the primary audience (§2, §8). Phase 10 delivers the web installer so a downloaded release ZIP becomes a working site through a browser wizard alone, no terminal required.

## What Changes

- Add installer-only routes (`/install/*`) that are reachable only while `installed.lock` is absent, and that 404/redirect once it exists.
- Add a multi-step installer wizard UI (Twig, matching admin-shell visual language): DB connection → admin user creation → optional sample data → done.
- Add a DB connection test step that validates driver/host/credentials before running migrations, reusing the existing configuration-validation rules (see `configuration` capability) rather than duplicating them.
- Add config file generation: the installer writes the resolved `.env` (DB credentials, app settings) to disk at the end of the wizard instead of requiring the user to hand-edit it pre-install.
- Add `installed.lock` creation as the final wizard step, gating re-entry to `/install/*` afterward.
- Add a `docker-compose.yml` (PHP + MySQL + nginx) for prod-parity local/dev use — not part of the installer flow itself, but scoped to this phase per the PRD.
- Add a top-level install README section with 5-minute cPanel-style install instructions.
- **BREAKING**: none — this is new surface area; existing `bin/serve` + `--seed` evaluator path (Phase 1) is untouched.

## Capabilities

### New Capabilities
- `web-installer`: installer routes, wizard UI, DB connection test, config file generation, and `installed.lock` lifecycle that together let a non-technical user turn an extracted release ZIP into a running, configured site through the browser.

### Modified Capabilities
- `http-routing`: the front controller must gate `/install/*` routes on the absence of `installed.lock`, and (once present) prevent admin/public routes from serving before installation completes — both are new routing-level behaviors, not just new handlers.

## Impact

- **Code**: new `src/Installer/` (or similar) namespace for wizard steps, DB test, config writer, lock file writer; new Twig templates under an installer view path; front controller / router changes to gate on `installed.lock`.
- **Config**: introduces the installer's own generation of `.env` at runtime — first time application code writes deployment config rather than a human editing it by hand.
- **Docs**: new/updated root `README.md` install section; `docker-compose.yml` at repo root.
- **Dependencies**: none new — reuses `vlucas/phpdotenv`, `symfony/yaml`, existing PDO connection wrapper, existing Twig setup.
- **Out of scope for this phase**: release ZIP build pipeline and update checker (Phase 11), scheduled backups (Phase 12) — the installer only needs to produce a correctly configured, migrated, locked install.
