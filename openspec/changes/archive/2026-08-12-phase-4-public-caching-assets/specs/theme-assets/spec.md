## ADDED Requirements

### Requirement: Static assets served from the active theme's assets directory

The system SHALL serve `GET /assets/{path}` by resolving `{path}` under the active theme's `assets/` directory and streaming the file's contents with a `Content-Type` derived from its extension and a cache-control header suitable for static files. The route SHALL be registered ahead of the public `/{collectionSlug}` pattern so a fixed `assets` prefix always wins.

#### Scenario: Existing asset file

- **WHEN** a visitor requests `GET /assets/{path}` for a file that exists under the active theme's `assets/` directory
- **THEN** the response is 200 with the file's contents, an appropriate `Content-Type`, and a `Cache-Control` header

#### Scenario: Unknown asset path

- **WHEN** a visitor requests `GET /assets/{path}` for a file that does not exist under the active theme's `assets/` directory
- **THEN** the response is the existing 404 page

#### Scenario: Path traversal is rejected

- **WHEN** a visitor requests `GET /assets/{path}` where `{path}` attempts to escape the theme's `assets/` directory (e.g. contains `..`)
- **THEN** the response is the existing 404 page and no file outside `assets/` is read

### Requirement: Starter theme ships baseline CSS

The system SHALL ship a stylesheet under `themes/starter/assets/`, referenced from `base.twig` via the `/assets/{path}` route, giving the starter theme's homepage, collection listing, and entry pages non-default styling.

#### Scenario: Fresh install serves styled pages

- **WHEN** a visitor loads any public page on a fresh install with the starter theme active
- **THEN** the page's HTML references the starter theme's stylesheet and requesting that stylesheet's URL returns its CSS
