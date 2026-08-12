# Capability: public-caching

## Purpose

TBD

## Requirements

### Requirement: Public pages are served from a file-based cache

The system SHALL cache the rendered HTML of the `home`, `collection`, and `entry` public routes in `{paths.cache}/public/pages/`, keyed by route, route parameters, and the version of every collection whose content the page depends on. A request whose cache key has a matching file SHALL be served from that file without re-rendering.

#### Scenario: First request renders and caches

- **WHEN** a visitor requests a public page for the first time (no cached file for its key)
- **THEN** the response is rendered normally and its HTML is written to the cache before being returned

#### Scenario: Repeat request is served from cache

- **WHEN** a visitor requests a public page whose cache key already has a cached file
- **THEN** the response is served from the cached file without querying the database or rendering Twig again

#### Scenario: 404s are not cached

- **WHEN** a visitor requests an unknown collection or entry slug
- **THEN** the 404 response is returned without reading from or writing to the page cache

### Requirement: Cache invalidation on entry changes

The system SHALL maintain a per-collection version counter and increment it whenever an entry in that collection is created, updated, or deleted. Cache keys for pages depending on a collection SHALL include that collection's current version, so a version bump causes subsequent requests to miss the stale cached file and re-render.

#### Scenario: Editing an entry invalidates its cached pages

- **WHEN** an entry is updated via the admin
- **THEN** its collection's version counter increments, and the next request for that entry's page or its collection's listing page re-renders instead of serving the previously cached HTML

#### Scenario: Creating an entry invalidates the collection listing and homepage

- **WHEN** a new entry is created in a collection
- **THEN** that collection's version counter increments, and the next request for that collection's listing page and the homepage re-renders

#### Scenario: Deleting an entry invalidates its collection's pages

- **WHEN** an entry is deleted
- **THEN** its collection's version counter increments, and the next request for that collection's listing page and homepage re-renders; the deleted entry's own page returns 404 as it already does today

#### Scenario: Unrelated collections are unaffected

- **WHEN** an entry changes in one collection
- **THEN** cached pages for other collections whose version did not change continue to be served from cache
