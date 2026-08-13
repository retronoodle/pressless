## ADDED Requirements

### Requirement: Installed version display in admin shell header

The shared admin shell header SHALL render an unobtrusive "Stead <version>" indicator on every authenticated admin page, where `<version>` is the value read from the `VERSION` file at the project root by the controller. The indicator SHALL be visible regardless of whether an update is available, and SHALL degrade to "version unknown" (not omitted) when the `VERSION` file cannot be read.

#### Scenario: Version rendered from VERSION file

- **WHEN** an authenticated administrator views any admin page and the `VERSION` file is readable
- **THEN** the admin shell header contains the text "Stead <version>" using the file's trimmed contents, visible on the same line group as the existing header brand/nav

#### Scenario: VERSION file unreadable

- **WHEN** an authenticated administrator views any admin page and the `VERSION` file is missing or unreadable
- **THEN** the admin shell header still renders, and the version segment reads "version unknown" rather than crashing or omitting the segment entirely

### Requirement: GitHub release date alongside the version

When the cached update check has a non-null release date for the installed version (the GitHub `published_at` of the release tagged with the installed version), the admin shell header SHALL render that date alongside the version, formatted as `Y-M-D` (e.g. `2026-08-13`), prefixed with the word "released" and separated from the version by a visible separator (e.g. a middot or "·"). When the cached update check has no release date for the installed version (GitHub unreachable, no `update.github_repo` configured, first check pending, malformed response, etc.), the header SHALL render only the "Stead <version>" segment and SHALL NOT show a date placeholder, error, or empty separator.

#### Scenario: Release date available

- **WHEN** an authenticated administrator views any admin page and the cached update check's `installedVersionReleasedAt` is a non-null ISO-8601 string
- **THEN** the header indicator reads "Stead <version> · released <Y-M-D>", where `<Y-M-D>` is derived from the cached value

#### Scenario: Release date missing on first paint

- **WHEN** an authenticated administrator views any admin page before the first successful update check has populated the cache, or when GitHub is unreachable
- **THEN** the header indicator reads "Stead <version>" with no date segment and no error message

#### Scenario: Release date missing because no repo configured

- **WHEN** an authenticated administrator views any admin page and `update.github_repo` is empty
- **THEN** the header indicator reads "Stead <version>" with no date segment (the existing update checker's "no repo configured" sentinel is reused; no separate "no repo" error is rendered)

### Requirement: Header indicator is purely informational

The admin shell header indicator SHALL be purely informational. It SHALL NOT be a link, SHALL NOT trigger any action on click, and SHALL NOT change the page's primary navigation, focus order, or accessibility tree beyond adding one labelled text node. The indicator SHALL render inside the existing shared header partial so all admin pages see the same markup; it SHALL NOT be duplicated per template.

#### Scenario: Indicator is not interactive

- **WHEN** an authenticated administrator views any admin page
- **THEN** the version/release-date indicator is rendered as plain text (no `<a>`, `<button>`, or other interactive element) and does not appear in the keyboard tab order

#### Scenario: Indicator uses the shared header partial

- **WHEN** the source of `templates/admin/_header.twig` is inspected
- **THEN** it contains the version indicator markup, and no other admin template renders the version indicator outside this partial