## Why

End users install from a downloaded release ZIP, not `git clone` (§8, §4 of the PRD) — but nothing yet builds that ZIP or tells an installed site when a newer version exists. Without this, there is no supported distribution path for non-technical end users and no way for an installed site to know it's out of date.

## What Changes

- Add a build script target that produces a release dist ZIP: installs production-only composer deps, strips dev/test files, stamps the version, and zips the result.
- Add a tagged-release pipeline (CI) that builds the dist ZIP on `vX.Y.Z` tag push and publishes it to the project website's release/download endpoint.
- Add an update checker: installed sites fetch the latest published release tag and compare it to the currently installed version.
- Add an update prompt in the admin UI showing that a newer version is available, with manual download-and-extract instructions (v1 — no one-click apply; see PRD §11 open question on auto-update depth).
- Record the installed version somewhere the checker and admin UI can read (e.g. a `VERSION` file stamped at build time, matching `installed.lock`'s existing convention of a build-time marker).

## Capabilities

### New Capabilities
- `release-build`: build-script target that produces a version-stamped, dependency-vendored, dev-stripped dist ZIP from the repo.
- `update-checker`: fetches the latest published release version from the website and compares it against the installed version.
- `update-notifications`: admin UI surface that shows an available-update banner/panel with manual update instructions, driven by the update checker.

### Modified Capabilities
- None. This phase is additive; it does not change existing spec-level behavior.

## Impact

- New: a build script (likely `bin/release` or an extension of existing build tooling) and CI workflow config (e.g. `.github/workflows/release.yml`) for tag-triggered builds.
- New: an admin-side HTTP call to the website's release endpoint, plus config for that endpoint URL and any request timeout/caching behavior.
- New: `VERSION` file (or equivalent) written at build time and read at runtime by both the update checker and (later) diagnostics/support tooling.
- Depends on the website exposing a release/download endpoint — that endpoint itself is outside this repo's scope; this change assumes its contract (latest tag + download URL) rather than building it.
- No database schema changes anticipated; update-check state (last checked, last known version) can be cached in existing settings/config storage rather than a new table.
