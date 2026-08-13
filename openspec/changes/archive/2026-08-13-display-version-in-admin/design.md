## Context

The admin shell today (see `templates/admin/_header.twig` and the `admin-shell` spec) is rendered by every authenticated admin page through a single shared header partial. It contains the brand, primary navigation, and account/logout controls — but nothing about the running Stead version. The only place a version string ever surfaces in the UI is the conditional `update_notice` banner on `admin.twig` (the dashboard), which by design only appears when a *newer* version is available. When the install is current, there is zero version text anywhere in the admin UI.

The version itself is canonically written into `VERSION` at build time by `bin/release` (`src/Console/ReleaseCommand.php`), and `Stead\Update\InstalledVersion` reads it for the update checker. GitHub's Releases API already returns `published_at` for every release; the current `ReleaseEndpointClient::fetchLatest()` discards that field because the update checker only needs `tag_name` and a download URL.

We want a small, persistent "Stead X.Y.Z · released YYYY-MM-DD" indicator on every authenticated admin page, where the date is the GitHub release date for the installed tag. To keep things cheap and bounded we piggy-back on the existing cached update check — when that check runs (or is replayed from cache), it carries the release date alongside everything else. No new endpoint, no new config, no new background job.

## Goals / Non-Goals

**Goals:**

- Make the installed version visible on every authenticated admin page, without the admin having to navigate anywhere or trigger an update check.
- Show the GitHub release date for the installed version alongside it, formatted in the admin's locale (defaults to a stable `Y-M-D` so the first iteration doesn't need an i18n table).
- Reuse the existing cached update-check infrastructure so the release date is fetched at most once per `update.check_interval_hours` (default 24 h) and tolerates GitHub outages via the same fail-closed semantics the update checker already uses.
- Preserve all current behaviour: dashboard banner, update page, cache TTL, `installed.lock` flow, web installer.

**Non-Goals:**

- A dedicated "About Stead" admin page (the indicator in the header is sufficient and stays out of the way).
- A user-facing setting to hide the indicator (this is admin-only chrome; it should always render).
- Auto-syncing the `VERSION` file from GitHub (that would be an auto-update flow, which the project has explicitly deferred — see `update-notifications` spec).
- Showing the *latest available* version's release date when it differs from the installed version's. The dashboard's existing update banner already covers that case.
- Localising the date format beyond a single stable format for v1.

## Decisions

**1. Render location: shared admin header partial, near the brand.**

The header (`templates/admin/_header.twig`) is included by every admin page, so anything we add there appears everywhere automatically. We add a small subdued `<p class="admin-version">` line directly under the `<h1>Stead admin</h1>` brand block. We deliberately do **not** put it in the footer (no shared footer partial exists; would require scaffolding one) and do **not** bolt it onto the dashboard (would only be visible on one page). The header sits next to the nav and account controls, so it's easy to find without competing with either.

**Alternative considered:** add a "System info" row to the dashboard's recent-activity section. Rejected because it's only visible on `GET /admin`, which means navigating to "see what version I'm on" is a click — defeating the "easy to find" goal.

**Alternative considered:** add a dedicated "About" link in the nav pointing to `/admin/system` (or similar) with a page that shows version, environment, PHP version, etc. Rejected as scope creep for v1 — the user asked for version + release date, not a system-info page. Can be added later without disturbing this work.

**2. Data source: extend the cached `UpdateCheckResult`, do not introduce a parallel fetcher.**

The cached update check already runs on a schedule, already respects `update.check_interval_hours`, already has a cache file in `var/cache/`, and already returns a result we plumb through to the admin UI. Adding a second background task or a parallel cache would double the GitHub load (twice the rate-limit pressure for installs that visit the admin a few times a day) and require its own invalidation logic. We extend `UpdateCheckResult` with one field, `installedVersionReleasedAt: ?string`, and the existing cache/scheduling carries it for free.

**Alternative considered:** store a separate `installed_release.json` next to `update-check.json`. Rejected for the reasons above and because the two would drift (a successful "is-update-available" check should imply a fresh install-date fetch on the same check cycle).

**3. How to fetch the installed version's release date: extend `ReleaseEndpointClient` with `fetchReleaseByTag(string $tag)`.**

GitHub exposes `GET /repos/<owner>/<repo>/releases/tags/<tag>`, which returns the same release shape as `/releases/latest` and includes `published_at`. We add a method that hits that URL, applies the same cURL plumbing and same fail-closed rules as `fetchLatest()` (treat non-2xx / non-JSON / missing `tag_name` / missing usable URL as `null`, never throw), and returns `{version, published_at, download_url}` — the download URL is included for symmetry with `fetchLatest()`, even though the admin version indicator doesn't use it today; it's cheap to return and keeps the data complete so a future "edit entry" / "switch version" affordance on the dashboard has the URL it needs.

**Alternative considered:** always do a separate fetch, even when `installed == latest`. Rejected as wasteful; in that case `fetchLatest()` already returned the same `published_at` we're about to re-fetch. We only call `fetchReleaseByTag()` when installed ≠ latest. When they're equal we copy `published_at` straight off the `fetchLatest()` payload to avoid a redundant HTTP call.

**Tag naming:** the `VERSION` file (and therefore `InstalledVersion::read()`) always holds an unprefixed SemVer string (`1.2.3`) — `ReleaseCommand::assertVersionShape()` rejects a leading `v`, and `bin/release` is invoked as `php bin/release "${GITHUB_REF_NAME#v}"`. But GitHub tags themselves are prefixed (`.github/workflows/release.yml` triggers on `v[0-9]+.[0-9]+.[0-9]+*`, and `fetchLatest()` already strips a leading `v` off `tag_name`). So the tag-lookup call must be `fetchReleaseByTag('v' . $installedVersion)`, not `fetchReleaseByTag($installedVersion)` — passing the unprefixed version would 404 against `releases/tags/1.2.3` when the real tag is `v1.2.3`, silently failing the lookup for every install that isn't exactly on the latest tag (i.e. most of the time). `fetchLatest()` is also extended to capture `published_at` from the same response it already parses, since decision 2 relies on copying that value when installed == latest.

**Alternative considered:** call `GET /repos/.../releases` (the list endpoint) and find the matching tag client-side. Rejected: returns an unbounded list of releases, and rate-limits way worse than the per-tag endpoint for an install that will only ever match one entry.

**4. Refactor: extract a private `fetchJson(string $path): ?array` in `ReleaseEndpointClient`.**

`fetchLatest()` and `fetchReleaseByTag()` would duplicate the cURL + status check + JSON decode block. We extract a private helper that takes a path (relative to the API base, e.g. `releases/latest` or `releases/tags/v1.2.3`), runs the request, and returns the decoded JSON body or `null` on any failure. Both public methods then become a thin parse step on top. The helper is `private` (not `protected`) — production subclasses for tests should override the public methods, not the transport.

**5. Cache schema: add the field defensively.**

`UpdateCheckCache::fromArray()` reads `installed_version_released_at` as `?string`. If missing or wrong type, it's `null` — the cache stays readable across the rollout (existing `update-check.json` files don't have the key) and an old cache file never causes a 500. The new field is written by `toArray()` only when populated (we don't write a `"installed_version_released_at": null` key on every save — keeps the cache file lean and makes a diff of an existing install's cache file post-upgrade obvious).

**6. Controller plumbing: pass the version + release date from `AdminController::index()` into the view.**

The controller already calls `safeUpdateCheck()` and threads `update_notice` into the template. We extend it with one more template variable, `installed_version_released_at`, sourced from the same `UpdateCheckResult` it already has. The header partial reads it. We do **not** call `InstalledVersion::read()` from the template (templates shouldn't do filesystem reads) — the controller does that and passes the plain string.

**7. Fail-closed: same rules as the update checker, plus a graceful "no date" render.**

If GitHub is unreachable, the cache is empty, or `published_at` is missing from the release payload, the controller passes `null` for `installed_version_released_at`. The header partial then renders `Stead X.Y.Z` with no trailing `· released …` segment. The version itself never silently disappears (it comes from the local `VERSION` file, not GitHub) — the worst case is "version shown, date missing", which matches the spirit of the existing fail-closed behaviour.

## Risks / Trade-offs

- **Cache file shape change during deployment** → `UpdateCheckCache::fromArray()` reads the new field defensively (missing → `null`), and the cache file isn't required for the system to function, so a deploy mid-write doesn't break anything. Worst case: a single admin page load reads the old shape and shows no date; the next check cycle writes the new shape.
- **Two GitHub calls per check cycle when installed ≠ latest** (one for `/releases/latest`, one for `/releases/tags/<installed>`) → both calls respect `update.check_interval_hours`, and only the latest call's response is cached. With a 24-hour interval, an install that's a few versions behind is at ~2 calls/day instead of ~1 — well within GitHub's 60 req/hr/IP unauthenticated limit.
- **Rate-limit interaction with existing update checker** → no change to throttling; we already accept GitHub's 60 req/hr/IP limit (see `ReleaseEndpointClient` docblock). The new call piggy-backs on the same check cycle, so the effective request count is at most `2 × existing`.
- **Release date becomes stale after GitHub republishes a release** → GitHub lets maintainers re-publish a release (which updates `published_at`). Our cache TTL means the date could lag the actual republish by up to `update.check_interval_hours`. Acceptable: republishes are rare, the indicator is informational, and an admin who needs the live value can clear `var/cache/update-check.json`.
- **What if `update.github_repo` is empty?** → the existing update checker treats this as "no update available" and writes a sentinel; we extend that path so `installedVersionReleasedAt` is `null` (no repo configured → no date). The version still renders from `VERSION`. Header shows `Stead X.Y.Z` with no date, which is the correct honest behaviour.
- **What if the admin shell is rendered before the first update check has run?** → first admin page load triggers the check (existing behaviour), so by the time the response is rendered, the result is in cache and the date is available. The fail-closed render path still works for the millisecond window where the cache file is being atomically renamed.
- **Locale / formatting** → we hard-code `Y-M-D` for v1 (e.g. `2026-08-13`) rather than reaching for `IntlDateFormatter`. Trades nicety for not having to ship a translation catalogue in the same change; if internationalisation is added later, only the format helper in the header partial needs to change.

## Migration Plan

- No database migrations, no config key changes, no schema version bump.
- Deploy: push the new code. Existing `var/cache/update-check.json` files keep working (the new field is read defensively as `null`); the first update check after deploy populates the new field and writes the updated shape.
- Rollback: revert the deploy. The new header partial lives or dies with the deploy; no persistent state outside the cache file would have been introduced. If we want to be paranoid, an operator can `rm var/cache/update-check.json` after rollback — the next admin page load rebuilds it under the old code.

## Open Questions

- Should the indicator link to `/admin/update` (the manual-update instructions page) when an update is available? Out of scope for v1 — the dashboard already shows a banner for that case — but worth a follow-up.
- Should we also surface the *latest* version's release date in the dashboard's update banner (currently it only shows the version number)? Nice-to-have, deferred.
- Do we want a keyboard-shortcut to jump to the version indicator (e.g. for support screenshots)? No — overkill for an unobtrusive line of text.