## Context

`ReleaseEndpointClient` currently expects `{"latest": "...", "url": "..."}` from a URL configured at `update.endpoint_url`. `.github/workflows/release.yml` builds a dist ZIP via `bin/release` and POSTs it to `RELEASE_PUBLISH_URL` with `RELEASE_PUBLISH_TOKEN`. Neither the endpoint nor a GitHub Release actually exists today, so update checks and the publish step are both effectively dead code paths. `UpdateChecker` (the caller) treats the client as a black box returning `array{latest: string, url: string}|null` and is otherwise unaffected as long as that contract holds.

## Goals / Non-Goals

**Goals:**
- Query `GET https://api.github.com/repos/<owner>/<repo>/releases/latest` directly, no custom endpoint.
- Preserve `UpdateChecker`'s fail-closed behavior and public interface exactly.
- Have CI create a real GitHub Release with the built ZIP attached, using the built-in `GITHUB_TOKEN` (no new secrets).
- Document a repeatable release SOP (`docs/RELEASING.md`) a maintainer can follow without reading workflow YAML.

**Non-Goals:**
- Authenticated/PAT-based GitHub API calls (unauthenticated 60/hr rate limit is accepted; see Risks).
- Automatic version bumping or changelog generation.
- Any change to `bin/release`'s ZIP-building logic itself.

## Decisions

- **Config shape: `update.github_repo` (`owner/repo`) instead of a raw URL.** Rationale: the URL is now a fixed GitHub API shape derived entirely from the repo name; asking installs to configure `owner/repo` is less error-prone than a full URL and matches how `gh`/GitHub Actions already reference repos. Alternative considered: keep `update.endpoint_url` and let it hold the full GitHub API URL — rejected because it invites pointing at the wrong GitHub endpoint (e.g. `/releases` instead of `/releases/latest`) and is harder to validate.
- **Asset selection: match a ZIP asset by name pattern, fall back to `zipball_url`.** The release ZIP built by `bin/release` is named `stead-<version>.zip`; the client looks for an asset whose `name` ends in `.zip`. If no asset is attached (e.g. a release created without one), fall back to `zipball_url` (GitHub's auto-generated source archive) so the checker still degrades gracefully rather than returning null. Rationale: keeps the client independent of the exact naming convention as long as it's a `.zip`.
- **`fetchLatest()` return contract unchanged**: still `array{latest: string, url: string}|null`. This means `UpdateChecker` requires zero code changes beyond how `ReleaseEndpointClient` is constructed in `fromConfig()`. Rationale: minimizes blast radius per the "only change what needs to change" constraint — `UpdateChecker`'s caching, fail-closed, and version-comparison logic are all orthogonal to where the data comes from.
- **`ReleaseEndpointClient` keeps its name.** Renaming to something like `GitHubReleaseClient` was considered but rejected to avoid an unnecessary rename churn across `UpdateChecker::fromConfig()` and tests when the class's role (fetch latest release info, return null on any failure) hasn't changed — only its data source. (Open question below if the user wants to rename anyway.)
- **CI: `gh release create` over `actions/create-release`.** `gh` is preinstalled on `ubuntu-latest` runners, actively maintained, and can attach an asset in the same call (`gh release create "$TAG" "$ZIP" --title ... --generate-notes`), whereas `actions/create-release` is archived/unmaintained and needs a separate upload-asset action.
- **Remove the custom-endpoint publish step and its two secrets entirely** rather than keeping both paths. The custom endpoint has never been implemented, so there's no live consumer to keep compatible during a transition.

## Risks / Trade-offs

- [Unauthenticated GitHub API rate limit is 60 req/hr per source IP] → Acceptable: checks are cached per `update.check_interval_hours` (default 24h) and only run on admin page loads, not per-request. Installs sharing a NAT/IP with many other GitHub API consumers could see occasional failures, but the checker is fail-closed (treated as "no update," logged, no admin-facing error), so worst case is a stale "up to date" state, not a crash.
- [Existing installs with `update.endpoint_url` set] → Their config becomes inert (empty client, checks silently disabled) rather than erroring, since `ReleaseEndpointClient::fetchLatest()` returns null on empty config today. Document the config key rename in the SOP/changelog so maintainers migrate deliberately.
- [Repo secrets `RELEASE_PUBLISH_URL`/`RELEASE_PUBLISH_TOKEN` left configured but unused] → Harmless (no step references them after this change), but note in the SOP that they can be deleted from repo settings.

## Migration Plan

1. Ship `ReleaseEndpointClient` changes + `update.github_repo` config + workflow changes together (single PR/change) since they're tightly coupled.
2. Update `config/app.yaml` default/example to the new key; existing deployed instances update their local config on next upgrade per the SOP.
3. No data migration needed — `UpdateCheckCache` entries just get a fresh `checkedAt` on next check.
4. Rollback: revert the change; the old custom-endpoint code path returns since it was never deleted from git history, but note no GitHub Releases created during the interim need cleanup (harmless — GitHub Releases don't affect the app).

## Open Questions

- Should `ReleaseEndpointClient` be renamed (e.g. `GitHubReleaseClient`) to reflect its new data source? Leaning no per Decisions above, but flagging for maintainer preference during implementation.
