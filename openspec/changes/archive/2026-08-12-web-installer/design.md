## Context

Today `public/index.php` always calls `Application::bootstrap()` first, which loads `.env`, resolves `Configuration`, and runs `Validator::validate()` before any route is dispatched. On a fresh release-ZIP extraction there is no `.env` and no database — bootstrap throws `SafeException` and the front controller returns a fixed 500 body. That's correct behavior for a broken production install, but it means the installer cannot live behind the normal bootstrap path: it has to run *before* configuration is known to be valid.

`config/app.yaml` already defines every setting the installer needs to collect (`database.*`, `app.name`, `app.url`, etc.) with `production`/`development` environment overlays. The installer's job is narrower than it looks: collect the handful of values that can't have safe defaults (DB credentials, admin user), write them to `.env`, run migrations, and drop a lock file — not reimplement configuration.

## Goals / Non-Goals

**Goals:**
- A fresh release-ZIP extraction, visited in a browser with no `.env` and no database, can reach a working admin login in under 5 minutes via a wizard.
- Reuse existing configuration validation, migration runner, and user-creation code paths instead of duplicating their logic inside the installer.
- Once `installed.lock` exists, `/install/*` becomes permanently inert (no re-run, no accidental data loss) without an extra DB check.
- `docker-compose.yml` gives a prod-parity way to exercise the installer locally against real MySQL instead of only the dev-mode SQLite path.

**Non-Goals:**
- Building the release ZIP itself (Phase 11).
- Update checking/self-update (Phase 11).
- Scheduled backups or restore (Phase 12).
- Multi-database-per-install or re-running the installer to change DB credentials later (that's a config-file edit, not an installer feature).

## Decisions

**Gate at the front controller, before `Application::bootstrap()`.**
`public/index.php` checks `installed.lock` first. If absent, it routes exclusively into a minimal installer kernel that does *not* call `Configuration::fromProjectRoot()` / `Validator::validate()` up front — those only become valid once the wizard has collected DB settings. If `installed.lock` is present, `/install/*` requests get a plain redirect to `/admin` (or 404 for API-style installer endpoints) and everything else proceeds through the existing bootstrap unchanged.
- *Alternative considered*: let `Application::bootstrap()` tolerate missing config and inject installer routes into the normal `Kernel`/`Routes` table. Rejected — it would require threading "am I installed?" through every route definition and risks a misconfigured install falling through to real admin/public routes.

**DB connection test builds a throwaway `Configuration`/`Connection` from wizard input, not the on-disk config.**
The wizard's DB step constructs an in-memory `Configuration` (same shape `Validator::validate()` expects) from posted form values and attempts a `Connection` + trivial query. Success/failure is reported without writing anything to disk yet. This reuses `Validator`'s existing driver/field checks (`database-foundations`, `configuration` capabilities) rather than re-validating by hand.

**Config file generation writes `.env` only, not `config/app.yaml`.**
`app.yaml` stays a versioned file shipped with the release; the installer never edits it. Only the values that are legitimately per-install secrets/environment (`DB_*`, `APP_ENV`, `APP_URL` if added, `SESSION_NAME`) are written to a fresh `.env`, mirroring `.env.example`'s keys. This matches the existing layering rule in the `configuration` capability (env overrides YAML) and avoids the installer needing write access to a file that also holds non-secret app defaults.

**`installed.lock` is a plain empty file at the project root (sibling to `.env`), not a DB flag.**
A DB-stored "installed" flag would require a working DB connection to gate the installer, which is circular on a fresh install with a *broken* DB. A filesystem lock is checked with a single `is_file()` before any DB work happens, matching how `.env` presence is already checked in `Application::bootstrap()`.
- *Alternative considered*: infer "installed" from `.env` existing. Rejected — an admin mid-wizard (DB step done, admin-user step not yet done) would have a partial `.env` and no way to safely resume vs. restart.

**Admin user creation reuses `UserRepository::create()` and existing password hashing — no parallel account-creation path.**
The wizard's "admin user" step is a thin form in front of the same repository method admin invites use, run against the connection opened during the DB step, immediately after migrations apply.

**Sample data reuses the existing `--seed` seeder, invoked in-process rather than shelling out to `bin/migrate --seed`.**
Avoids a `proc_open`/shell dependency on hosts where it may be disabled (common on shared cPanel hosts), and keeps the installer's only filesystem/DB side effects contained to PHP-level calls.

**`docker-compose.yml` is dev tooling, not part of the installer's request path.**
It exists so a contributor can validate the installer against real MySQL/nginx locally; it has no runtime coupling to the installer code itself.

## Risks / Trade-offs

- **Partially-completed install (crash/navigate-away mid-wizard)** → Each step is idempotent up to what it has already persisted: DB step writes nothing until confirmed; `.env` write and `installed.lock` write are the last two steps, in that order, so a crash before `installed.lock` is written always leaves the site re-enterable at `/install/*` on next visit, and `.env`-then-crash just means the wizard's later steps re-run against the config that's already on disk instead of re-collecting DB info.
- **Web server can't write `.env` or `installed.lock`** (restrictive shared-host permissions) → Installer surfaces a clear, actionable error at the config-write step (not a generic 500) naming the exact path that needs write permission, consistent with the existing "no secrets in errors, but name the setting" pattern in `Validator`.
- **Race: two browser tabs completing the wizard concurrently** → Low-value edge case for a single-operator installer; final `installed.lock` write uses exclusive file creation (`fopen` with `x` mode or equivalent) so only the first completer wins and the second gets redirected once it detects the lock.
- **Installer left reachable indefinitely if `installed.lock` write silently fails** → Treated as the config-write-permission risk above, not a separate failure mode; same error surface applies.

## Migration Plan

New capability, additive only — no existing routes, config keys, or DB schema change. Ships as part of the phase; no rollback concerns beyond normal code revert.

## Open Questions

- Does `APP_URL` need to be collected in the wizard (for absolute link generation later), or is `config/app.yaml`'s existing default sufficient for v1? Leaning toward collecting it since a wrong `localhost` default would silently break canonical URLs on the first real deploy — worth confirming during specs.
