## MODIFIED Requirements

### Requirement: Static assets served from the active theme's assets directory

The system SHALL serve `GET /assets/{path}` by resolving `{path}` under the active theme's `assets/` directory and streaming the file's contents with a `Content-Type` derived from its extension and a cache-control header suitable for static files. The route SHALL be registered ahead of the public `/{collectionSlug}` pattern so a fixed `assets` prefix always wins. The active theme SHALL be resolved via the shared active-theme resolution (database-backed, falling back to configuration) rather than reading `theme.active` from configuration directly.

#### Scenario: Existing asset file

- **WHEN** a visitor requests `GET /assets/{path}` for a file that exists under the active theme's `assets/` directory
- **THEN** the response is 200 with the file's contents, an appropriate `Content-Type`, and a `Cache-Control` header

#### Scenario: Unknown asset path

- **WHEN** a visitor requests `GET /assets/{path}` for a file that does not exist under the active theme's `assets/` directory
- **THEN** the response is the existing 404 page

#### Scenario: Path traversal is rejected

- **WHEN** a visitor requests `GET /assets/{path}` where `{path}` attempts to escape the theme's `assets/` directory (e.g. contains `..`)
- **THEN** the response is the existing 404 page and no file outside `assets/` is read

#### Scenario: Active theme changed via admin console

- **WHEN** an admin activates a different installed theme via the admin console
- **THEN** subsequent `GET /assets/{path}` requests serve files from the newly active theme's `assets/` directory without a redeploy
