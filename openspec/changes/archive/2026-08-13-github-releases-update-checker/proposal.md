## Why

Update checking currently depends on a custom project-website release endpoint (`update.endpoint_url`) that CI publishes a ZIP to via `RELEASE_PUBLISH_URL`/`RELEASE_PUBLISH_TOKEN`. No GitHub Release is actually created, and no such endpoint is implemented or maintained. Pointing the checker directly at GitHub's Releases API removes this indirection, the need to run/maintain a separate publish endpoint, and gives maintainers a documented, repeatable way to cut a release (tracked in GitHub issue #1).

## What Changes

- **BREAKING**: `ReleaseEndpointClient::fetchLatest()` parses GitHub's release JSON shape (`tag_name`, `assets[]`, `zipball_url`) instead of the custom `{"latest": ..., "url": ...}` shape.
- Replace `update.endpoint_url` config with `update.github_repo` (`owner/repo`); the client builds `https://api.github.com/repos/<owner>/<repo>/releases/latest` internally.
- `.github/workflows/release.yml` creates an actual GitHub Release (via `gh release create`) with the `bin/release`-built ZIP attached as an asset, on `vX.Y.Z` tag push.
- Remove the custom-endpoint publish step and its secrets (`RELEASE_PUBLISH_URL`, `RELEASE_PUBLISH_TOKEN`) from the workflow.
- Add a `docs/RELEASING.md` SOP documenting the end-to-end release process (versioning, tagging, CI behavior, verifying the release, rollback).
- `UpdateChecker`'s fail-closed behavior, caching, and public interface are preserved — only the client's parsing and config key change.

## Capabilities

### New Capabilities

(none — this modifies existing capabilities)

### Modified Capabilities

- `update-checker`: "Latest release lookup" requirement changes from querying a custom release endpoint to querying GitHub's Releases API and parsing its response shape; config key changes from `update.endpoint_url` to `update.github_repo`.
- `release-build`: "Tagged-release CI pipeline" requirement changes from publishing the ZIP to a custom endpoint to creating a GitHub Release with the ZIP attached as an asset.

## Impact

- Affected code: `src/Update/ReleaseEndpointClient.php`, `src/Update/UpdateChecker.php` (config key only), `config/app.yaml`, `.github/workflows/release.yml`.
- Affected tests: `tests/Unit/Update/ReleaseEndpointClientTest.php`, `tests/Unit/Update/UpdateCheckerTest.php`, `tests/Integration/UpdateAdminTest.php`.
- Affected config: any deployed instance with `update.endpoint_url` set will need to migrate to `update.github_repo`.
- Affected secrets: `RELEASE_PUBLISH_URL`/`RELEASE_PUBLISH_TOKEN` become unused and can be removed from repo settings (no new secret needed — `gh release create` uses the Actions-provided `GITHUB_TOKEN`).
- New doc: `docs/RELEASING.md`.
