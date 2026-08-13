## MODIFIED Requirements

### Requirement: Latest release lookup

The system SHALL be able to fetch the latest published release version directly from GitHub's Releases API, using the repository configured at `update.github_repo` (`owner/repo`).

#### Scenario: Successful lookup

- **WHEN** the update checker queries `GET https://api.github.com/repos/<owner>/<repo>/releases/latest` and receives a valid response
- **THEN** it extracts the latest published version from `tag_name` (stripping a leading `v`), selects a download URL from a `.zip` asset in `assets[]` (falling back to `zipball_url` if no such asset exists), and compares the version against the installed version

#### Scenario: Endpoint unreachable or errors

- **WHEN** the GitHub Releases API is unreachable, times out, rate-limits the request, or returns a non-2xx response
- **THEN** the update checker treats this as "no update available", logs the failure, and does not surface an error to the admin user

#### Scenario: Malformed or unexpected response shape

- **WHEN** the GitHub Releases API response is not valid JSON, is missing `tag_name`, or has no usable download URL
- **THEN** the update checker treats this as "no update available" and logs the failure
