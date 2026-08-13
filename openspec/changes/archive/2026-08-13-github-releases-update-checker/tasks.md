## 1. Config

- [x] 1.1 Add `update.github_repo` (`owner/repo` string) to `config/app.yaml`, replacing `update.endpoint_url`; update the doc comment above it
- [x] 1.2 Update `UpdateChecker::fromConfig()` to build `ReleaseEndpointClient` from `update.github_repo` instead of `update.endpoint_url`

## 2. Release client

- [x] 2.1 Update `ReleaseEndpointClient` to build `https://api.github.com/repos/<owner>/<repo>/releases/latest` from a configured `owner/repo` instead of taking a raw `endpoint_url`
- [x] 2.2 Parse GitHub's response: extract `tag_name` (strip leading `v`) as `latest`; select a `.zip` asset from `assets[]` by name, falling back to `zipball_url`, as `url`
- [x] 2.3 Add `Accept: application/vnd.github+json` header (keep existing `User-Agent`); return `null` on any non-2xx, malformed JSON, missing `tag_name`, or no usable URL, per existing fail-closed contract
- [x] 2.4 Update class doc comment to describe the GitHub Releases API contract instead of the custom endpoint contract

## 3. CI release pipeline

- [x] 3.1 In `.github/workflows/release.yml`, replace the "Publish ZIP to website release endpoint" step with a `gh release create "$TAG" "$ZIP" --title ... --generate-notes` step using the built-in `GITHUB_TOKEN`
- [x] 3.2 Remove `RELEASE_PUBLISH_URL`/`RELEASE_PUBLISH_TOKEN` references and the secrets-documentation comment block at the top of the file; add a note that these repo secrets are no longer used and may be deleted
- [x] 3.3 Confirm the workflow still has `permissions: contents: write` (required for `gh release create`) — add if missing

## 4. Tests

- [x] 4.1 Rewrite `tests/Unit/Update/ReleaseEndpointClientTest.php` fixtures to GitHub's response shape (`tag_name`, `assets[]`, `zipball_url`), covering: successful lookup with a `.zip` asset, fallback to `zipball_url` when no asset matches, non-2xx response, malformed JSON, missing `tag_name`
- [x] 4.2 Run `tests/Unit/Update/UpdateCheckerTest.php` and `tests/Integration/UpdateAdminTest.php` and confirm they still pass unmodified (they exercise `UpdateChecker` via a fake client, so the return-shape contract should keep them green); fix only if they reference the old config key directly

## 5. Documentation

- [x] 5.1 Write `docs/RELEASING.md`: versioning scheme, how to cut a release (tag format, `git tag vX.Y.Z && git push --tags`), what CI does automatically, how to verify the GitHub Release and asset were created correctly, how to roll back/delete a bad release, and a note on removing the now-unused `RELEASE_PUBLISH_URL`/`RELEASE_PUBLISH_TOKEN` secrets
- [x] 5.2 Link `docs/RELEASING.md` from `README.md`

## 6. Verification

- [x] 6.1 Run the full test suite and static analysis (`phpstan` if configured) locally
- [x] 6.2 Manually verify `ReleaseEndpointClient` against a real repo's `releases/latest` response shape (e.g. via `curl` against this repo or another public GitHub repo) before merging
