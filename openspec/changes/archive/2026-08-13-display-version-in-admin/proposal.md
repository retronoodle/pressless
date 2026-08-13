## Why

Admins have no easy way to see what version of Stead they're running from inside the admin panel. The version is stamped into a `VERSION` file at build time and is currently only visible to the user as part of the "update available" banner on the dashboard, which by design is silent when the install is current. When troubleshooting or comparing notes with support, an admin has to SSH to read `VERSION` — that's friction the admin shell should absorb. We also want to show when the running version was released, sourced from GitHub, so the value updates without a Stead code change whenever a new release ships upstream.

## What Changes

- Add a small, persistent "Stead 1.2.3 · released 2026-08-13" indicator to the admin shell header, visible on every authenticated admin page. Renders the locally installed version (read from `VERSION`) next to the GitHub release date for that exact tag (e.g. `published_at`), formatted in the admin's locale.
- Extend `ReleaseEndpointClient` with a `fetchReleaseByTag(string $tag): ?array` method that hits `GET /repos/<owner>/<repo>/releases/tags/<tag>` and returns `{version, published_at, download_url}` (or `null` on any failure).
- Extend `UpdateChecker` so its cached result also carries the installed version's `published_at` (the release date from GitHub). The existing cache TTL and fail-closed semantics apply — if the lookup fails, the cached `published_at` is reused from the previous successful check, falling back to `null` only if no prior successful check exists.
- Extend `UpdateCheckResult` with an `installedVersionReleasedAt` (ISO-8601 string) field, plumbed through the cache's serialize/deserialize so existing cache files remain readable.
- Extend `AdminController::index()` (and any other admin shell renderers that need it) to pass the version + release date into the view. The header partial renders them.
- The indicator is intentionally **not** part of the dashboard's recent-activity section and **not** a banner: it lives in the persistent admin header so it's visible regardless of which admin page the user is on.

## Capabilities

### New Capabilities

(none — this extends existing capabilities)

### Modified Capabilities

- `admin-shell`: adds a "current Stead version + release date" requirement to the shared admin header, rendered on every authenticated admin page from data passed by the controller. The version alone is always shown when the `VERSION` file is readable; the release date is shown alongside it when the cached update check has one.
- `update-checker`: extends the "Installed version detection" and "Latest release lookup" requirements so the cached check also captures the installed version's GitHub `published_at`. New requirement: the client can fetch a specific release by tag (not only "latest"), and the cache layer round-trips the new `published_at` field.

## Impact

- `src/Update/ReleaseEndpointClient.php`: `fetchLatest()` extended to also capture `published_at` (missing `published_at` now fails the whole call, same as a missing `tag_name`); new `fetchReleaseByTag()` method (plus any small refactor of the cURL plumbing into a private `fetchJson()` helper to avoid duplication).
- `src/Update/UpdateChecker.php`: calls `fetchReleaseByTag('v' . $installedVersion)` for the installed version when it differs from latest (best-effort, never throws — the `v` prefix is re-added because GitHub tags are `vX.Y.Z` while the local `VERSION` file is unprefixed), merges the result into `UpdateCheckResult`, passes `installedVersionReleasedAt` through `buildUnknownResult()`/`rehydrateCached()`.
- `src/Update/UpdateCheckResult.php`: new `installedVersionReleasedAt` readonly property.
- `src/Update/UpdateCheckCache.php`: cache schema gets a new optional key; `fromArray()` reads it defensively (missing/invalid → `null`, never errors).
- `src/Http/Controller/AdminController.php`: passes the version + release date into the rendered view.
- `templates/admin/_header.twig`: renders the new indicator next to the admin nav or in the header block, styled as a small subdued line so it doesn't compete with primary nav.
- `tests/Unit/Update/ReleaseEndpointClientTest.php`: covers the new method (200 with body, 404, malformed JSON, transport failure, empty repo).
- `tests/Unit/Update/UpdateCheckerTest.php`: covers the merge of installed version's `published_at` into the result and the cache.
- `tests/Integration/UpdateAdminTest.php`: covers the admin header rendering the version + date when the cached check has them, and gracefully when it doesn't.
- No new dependencies. No migrations. No breaking config changes (`update.github_repo` already exists from the GitHub releases change).
- The change does **not** add any user-facing setting — the version display is always on in authenticated admin pages.