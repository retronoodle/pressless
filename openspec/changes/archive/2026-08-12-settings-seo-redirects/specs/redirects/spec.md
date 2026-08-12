## ADDED Requirements

### Requirement: Redirect storage

The system SHALL persist redirects as rows of (`old_path`, `new_path`, timestamps) in a `redirects` table. `old_path` SHALL be unique — a new redirect for a path that already has one SHALL replace the existing row's `new_path` rather than creating a duplicate.

#### Scenario: Create a redirect

- **WHEN** a redirect from `/posts/old-slug` to `/posts/new-slug` is created
- **THEN** a row exists in `redirects` with that `old_path` and `new_path`

#### Scenario: Re-adding a redirect for the same old path updates it

- **WHEN** a redirect already exists for `old_path = /posts/old-slug` and a new redirect is created for the same `old_path` with a different `new_path`
- **THEN** the existing row's `new_path` is updated in place and no duplicate row is created

### Requirement: Redirects admin screen

The system SHALL provide an authenticated, admin-only screen at `/admin/redirects` listing all redirects, with actions to manually add a redirect (old path, new path) and delete an existing one.

#### Scenario: List existing redirects

- **WHEN** an authenticated administrator opens `/admin/redirects`
- **THEN** the response lists all redirect rows with their old and new paths

#### Scenario: Manually add a redirect

- **WHEN** an administrator submits an old path and a new path via the redirects admin form
- **THEN** a new redirect row is created and appears in the list

#### Scenario: Delete a redirect

- **WHEN** an administrator deletes a redirect from the list
- **THEN** the row is removed and requests to its old path no longer redirect

### Requirement: Public redirect resolution

When a public entry request would otherwise return 404 because no live entry matches the requested path, the system SHALL check the `redirects` table for a matching `old_path` and, if found, respond with an HTTP 301 redirect to `new_path` instead of rendering the 404 page. A live entry or collection match SHALL always take precedence over a redirect.

#### Scenario: Stale entry URL redirects

- **WHEN** a visitor requests a path that matches a `redirects.old_path` and no live entry exists at that path
- **THEN** the response is an HTTP 301 redirect to the corresponding `new_path`

#### Scenario: No matching redirect still 404s

- **WHEN** a visitor requests an unknown path with no matching live entry and no matching redirect
- **THEN** the response is the existing 404 page

#### Scenario: Live entry takes precedence over a stale redirect

- **WHEN** a visitor requests a path that both resolves to a live, published entry and happens to match a `redirects.old_path`
- **THEN** the response renders the live entry and does not redirect
