# Capability: public-rendering

## Purpose

TBD

## Requirements

### Requirement: Public collection listing route

The system SHALL serve `GET /{collectionSlug}` by resolving the collection via `CollectionRepository::findBySlug`, fetching a paginated page of its `published` entries, and rendering the `collection.twig` template with the collection and its entries.

#### Scenario: Existing collection with entries

- **WHEN** a visitor requests `GET /{collectionSlug}` for a collection that exists and has published entries
- **THEN** the response is 200 and renders `collection.twig` with the collection and the first page of its published entries

#### Scenario: Unknown collection slug

- **WHEN** a visitor requests `GET /{collectionSlug}` for a slug that does not match any collection
- **THEN** the response is the existing 404 page and no entry data is exposed

#### Scenario: Collection with only draft entries

- **WHEN** a visitor requests `GET /{collectionSlug}` for a collection whose entries are all drafts
- **THEN** the response is 200 and renders `collection.twig` with an empty entry list

### Requirement: Public entry route

The system SHALL serve `GET /{collectionSlug}/{entrySlug}` by resolving the collection and then the `published` entry via `EntryRepository::findByCollectionAndSlug`, rendering the `entry.twig` template with the entry and its collection. When no live published entry matches the requested path, the system SHALL check the `redirects` table for a matching `old_path` before returning 404, and respond with an HTTP 301 redirect to `new_path` on a match.

#### Scenario: Existing published entry

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for a published entry that exists in that collection
- **THEN** the response is 200 and renders `entry.twig` with the entry's values and its parent collection

#### Scenario: Unknown entry slug within a known collection

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` where the collection exists but no entry has that slug, and no redirect matches the requested path
- **THEN** the response is the existing 404 page

#### Scenario: Unknown entry slug with a matching redirect

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` where the collection exists, no entry has that slug, and the requested path matches a `redirects.old_path`
- **THEN** the response is an HTTP 301 redirect to the redirect's `new_path` instead of the 404 page

#### Scenario: Unknown collection slug

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for a collection slug that does not exist
- **THEN** the response is the existing 404 page

#### Scenario: Draft entry is not publicly reachable

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for an entry that exists in that collection but has `status = draft`
- **THEN** the response is the existing 404 page and no entry data is exposed

### Requirement: Theme-aware template resolution with default fallback

The system SHALL resolve Twig templates by checking the active theme's template directory first and falling back to the default `templates/` directory when the theme does not provide a given template. When no theme is configured, resolution SHALL behave exactly as it does today (default directory only).

#### Scenario: Theme overrides a template

- **WHEN** the active theme provides its own `entry.twig`
- **THEN** rendering `entry.twig` uses the theme's version

#### Scenario: Theme does not override a template

- **WHEN** the active theme has no `home.twig` of its own
- **THEN** rendering `home.twig` falls back to the default `templates/home.twig`

#### Scenario: No theme configured

- **WHEN** no active theme is set in configuration
- **THEN** template resolution uses only the default `templates/` directory, unchanged from current behavior

### Requirement: Starter theme

The system SHALL ship a starter theme providing `base.twig`, `home.twig`, `collection.twig`, and `entry.twig`, active by default, sufficient to render a homepage, a collection listing, and a single entry with basic styling.

#### Scenario: Fresh install renders without a custom theme

- **WHEN** an entry is published in a fresh install with no custom theme installed
- **THEN** visiting its public URL renders the entry using the starter theme's `entry.twig`
