## MODIFIED Requirements

### Requirement: Installed version detection

The system SHALL determine its own installed version by reading the `VERSION` file written at build time.

#### Scenario: Reading installed version

- **WHEN** any code needs the currently installed version
- **THEN** it SHALL read the value from the `VERSION` file at the application root

### Requirement: Latest release lookup

The system SHALL be able to fetch the latest published release version directly from GitHub's Releases API, using the repository configured at `update.github_repo` (`owner/repo`).

#### Scenario: Successful lookup

- **WHEN** the update checker queries `GET https://api.github.com/repos/<owner>/<repo>/releases/latest` and receives a valid response
- **THEN** it extracts the latest published version from `tag_name` (stripping a leading `v`), selects a download URL from a `.zip` asset in `assets[]` (falling back to `zipball_url` if no such asset exists), captures `published_at` as the latest release's publication timestamp (ISO-8601), and compares the version against the installed version

#### Scenario: Endpoint unreachable or errors

- **WHEN** the GitHub Releases API is unreachable, times out, rate-limits the request, or returns a non-2xx response
- **THEN** the update checker treats this as "no update available", logs the failure, and does not surface an error to the admin user

#### Scenario: Malformed or unexpected response shape

- **WHEN** the GitHub Releases API response is not valid JSON, is missing `tag_name`, is missing `published_at`, or has no usable download URL
- **THEN** the update checker treats this as "no update available" and logs the failure

### Requirement: Update check caching

The system SHALL cache the result of an update check so it is not re-queried on every admin page load.

#### Scenario: Cached check reused within window

- **WHEN** an update check was performed less than the configured interval ago
- **THEN** subsequent admin page loads reuse the cached result instead of calling the release endpoint again

#### Scenario: Cache expires and re-checks

- **WHEN** the cached check is older than the configured interval
- **THEN** the next admin page load triggers a fresh check against the release endpoint and updates the cache

## ADDED Requirements

### Requirement: Installed version release-date lookup

The system SHALL be able to fetch the GitHub release that matches the installed version's tag, by querying `GET https://api.github.com/repos/<owner>/<repo>/releases/tags/<tag>`, and SHALL extract `tag_name` (the version, with any leading `v` stripped), `published_at` (the ISO-8601 publication timestamp), and a usable download URL (a `.zip` asset from `assets[]` falling back to `zipball_url`). GitHub release tags are stamped as `v<version>` (e.g. `v1.2.3`), while the locally installed version (from `VERSION`) is always unprefixed (e.g. `1.2.3`); the caller SHALL re-add the `v` prefix when building `<tag>` for this lookup.

#### Scenario: Successful tag lookup

- **WHEN** the update checker queries `GET .../releases/tags/v<installed-version>` (the installed version with a `v` prefix re-added) and receives a 2xx response with valid JSON
- **THEN** it extracts `version` from `tag_name` (stripping a leading `v`), `published_at` as the ISO-8601 string, and a download URL using the same asset-selection rule as the "latest" endpoint

#### Scenario: Tag endpoint returns 404

- **WHEN** the tag endpoint returns 404 (no GitHub release exists for the installed tag, e.g. a local-only version)
- **THEN** the lookup returns `null`, the checker logs the miss, and the cached result's `installedVersionReleasedAt` is left as `null` (or the previously cached value, if any)

#### Scenario: Tag endpoint unreachable or errors

- **WHEN** the tag endpoint is unreachable, times out, rate-limits, returns a non-2xx, or returns a malformed/unexpected payload (no `tag_name`, no `published_at`, no usable URL)
- **THEN** the lookup returns `null`, the checker logs the failure, and the cached result's `installedVersionReleasedAt` is left as `null` (or the previously cached value, if any)

### Requirement: Installed version release date in cached result

The cached update check result SHALL carry the installed version's GitHub `published_at` (the release date) as a nullable ISO-8601 string field, plumbed through serialization and deserialization of the cache file. When the installed version equals the latest version, the field SHALL be populated from the `/releases/latest` response's `published_at` without an extra HTTP call. When the installed version differs from the latest version, the field SHALL be populated by an additional `fetchReleaseByTag()` call during the same check cycle, with the existing fail-closed semantics (any lookup failure leaves the field as `null` rather than failing the check).

#### Scenario: Installed equals latest

- **WHEN** the update checker runs and the installed version equals the latest version reported by `/releases/latest`
- **THEN** the cached `UpdateCheckResult`'s `installedVersionReleasedAt` is set to the `published_at` from the `/releases/latest` response (no extra HTTP call), and the cached result is persisted

#### Scenario: Installed differs from latest

- **WHEN** the update checker runs and the installed version differs from the latest version reported by `/releases/latest`
- **THEN** the checker additionally calls `fetchReleaseByTag()` for the installed tag, sets `installedVersionReleasedAt` to the returned `published_at` on success, and persists the cached result

#### Scenario: Tag lookup fails on a behind-the-latest install

- **WHEN** the update checker runs and `fetchReleaseByTag()` returns `null` (404, transport error, malformed payload)
- **THEN** the check completes normally (does not throw), `installedVersionReleasedAt` is `null`, and the cached result is still persisted with the latest-version comparison populated

#### Scenario: Existing cache file lacks the new field

- **WHEN** the cache is loaded from a file written before this change was deployed (no `installed_version_released_at` key)
- **THEN** the loaded `UpdateCheckResult` has `installedVersionReleasedAt = null` and the system continues to function; the next check cycle that succeeds writes the new field

### Requirement: Repository not configured

When `update.github_repo` is empty (no GitHub repo configured), the cached `UpdateCheckResult`'s `installedVersionReleasedAt` SHALL be `null`, the existing "no update available" sentinel behaviour SHALL be preserved, and the system SHALL NOT issue any HTTP request.

#### Scenario: No repo configured

- **WHEN** the update checker runs and `update.github_repo` is the empty string
- **THEN** the cached result has `installedVersionReleasedAt = null`, the existing "up to date" sentinel is persisted, and no HTTP request is made