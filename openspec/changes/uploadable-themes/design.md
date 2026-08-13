## Context

Theme selection is currently pure config: `TwigRenderer::resolveThemePath()` and `AssetController::resolveAssetsRoot()` each independently read `theme.active` + `paths.theme` from `Configuration` and build a filesystem path. Switching themes means editing `config/app.yaml` and redeploying. There's no persistence for "which themes are installed," no upload path, and no validation of theme package contents.

The codebase already has a precedent for admin-editable, DB-backed configuration: `src/Settings/` (`SettingsRepository` + `Settings`) backs `/admin/settings`, and `src/Mail/MailSettingsRepository` backs `/admin/mail-settings`. Admin controllers are wired by hand in `src/Http/Routes.php` (no DI container). File uploads have a precedent in `MediaAdminController::upload()` — real-content MIME sniffing, reserve-then-move DB/filesystem ordering, allow-list filename sanitization. ZIP handling has a precedent in `src/Backups/` (`ArchiveBuilder` writes, `RestoreRunner` reads), but `RestoreRunner`'s extraction does not guard against zip-slip/path traversal — it trusts backups as self-generated. Theme ZIPs are untrusted admin uploads and need a real guard.

## Goals / Non-Goals

**Goals:**
- Upload a theme as a ZIP from the admin console, validated and extracted safely.
- List installed themes and activate one without a redeploy.
- Delete a non-active theme.
- Keep `TwigRenderer`/`AssetController` behavior-compatible for existing installs (starter theme, no manifest) while moving the source of truth to the database.
- Degrade gracefully (no 500s) if the DB is unreachable or the active theme's folder disappears out-of-band.

**Non-Goals:**
- Sandboxing Twig execution for uploaded themes (`Twig\Sandbox\SecurityPolicy`). Uploading a theme is already an admin-only, high-privilege action in this app's trust model (comparable to configuring mail/backup credentials); this change does not attempt to treat theme authors as a lower trust tier than admins.
- Silent overwrite/update of an existing theme by re-uploading the same slug — v1 rejects name collisions outright.
- Theme versioning/rollback history, multiple versions of the same theme coexisting, or a theme marketplace/registry.
- Editing theme files in-browser after upload.

## Decisions

**DB-backed active theme, not a config-file rewrite.** A new `themes` table (mirroring the `settings` migration's shape) stores installed themes and an `is_active` flag. This matches the existing precedent for admin-editable configuration and lets activation take effect on the next request with no deploy. `config/app.yaml`'s `theme.active` is kept, but demoted to a fallback/seed value only, read when the DB has no active row or is unreachable — this bounds the blast radius of a DB problem instead of introducing a new single point of failure.

**Shared `ActiveThemeResolver` instead of duplicating resolution logic.** `TwigRenderer` and `AssetController` currently each derive the theme directory independently from `Configuration`. A new `src/Themes/ActiveThemeResolver` centralizes "DB active theme, falling back to config, falling back to null" so both call sites share one algorithm and one set of edge cases (missing folder, DB exception) rather than drifting apart.

**Reject-on-collision, not overwrite, for re-uploaded slugs.** If an uploaded ZIP's slug matches an existing theme (DB row or `themes/<slug>/` directory), the upload is rejected with an explicit "already exists" error rather than silently replacing a potentially-active theme's files out from under a running site. An explicit delete-then-upload is required to replace a theme in v1.

**Zip-slip guard is validated before any extraction, not entry-by-entry during extraction.** Every entry name is checked against traversal patterns (`..`, absolute paths, symlink entries) in a first pass; only if every entry passes does extraction begin, into a temp staging directory that is atomically `rename()`-d into `themes/<slug>/` on full success. This avoids ever leaving a partially-written, possibly-malicious theme directory live under `themes/`.

**`theme.json` is optional, not required.** Requiring a manifest would break the existing `themes/starter/` (which has none) and any future minimal theme. Parsing falls back to a slug-derived display name when the manifest is absent or malformed, and malformed manifests are a soft warning, not an upload failure — only structural validation (required templates, safe entry names) is a hard failure.

## Risks / Trade-offs

- **[Risk]** An admin with upload access can install a theme containing Twig templates that call unrestricted Twig functions/filters, effectively a controlled-but-real code-execution surface. → **Mitigation**: explicitly a Non-Goal in this change (see above) because theme upload is already gated to the admin role, same trust tier as mail/backup credential configuration; flagged here for visibility rather than solved.
- **[Risk]** Zip-slip / path traversal in a malicious upload could write files outside `themes/`. → **Mitigation**: full entry-name validation pass before any write, reusing the same realpath/prefix-check idiom already proven in `AssetController::serve()`; extraction lands in a temp dir first, so even a bug in the check has a bounded blast radius until the final `rename()`.
- **[Risk]** DB and filesystem can drift (DB says theme X active, folder deleted manually, or vice versa). → **Mitigation**: `ActiveThemeResolver` checks `is_dir()` on the DB's active theme before trusting it and falls back to config; this is exercised directly in `ActiveThemeResolverTest`.
- **[Trade-off]** No update-in-place for an existing theme; re-uploading requires deleting the old slug first. Chosen over silent overwrite to avoid an admin accidentally clobbering the live theme's files mid-request. Acceptable friction for a v1; can be revisited if update-in-place is requested later.

## Migration Plan

1. Add and run the new `themes` migration (paired mysql/sqlite), seeding a `starter` row as active — safe on existing installs since `config/app.yaml`'s `theme.active` already defaults to `starter`.
2. Ship the resolver/controller/repository code behind the same request path — no separate feature flag needed, since the DB seed row makes the DB-backed path immediately correct for existing installs and the config fallback covers any install where the migration hasn't run yet or is skipped.
3. No rollback-specific steps beyond the standard migration-down path (drop `themes` table) — with the table gone, `ActiveThemeResolver` falls back to `config theme.active`, matching pre-change behavior exactly.

## Open Questions

None outstanding — activation storage, manifest handling, and deletion policy were resolved with the requester before this design was written (DB-backed activation, optional manifest, delete non-active only).
