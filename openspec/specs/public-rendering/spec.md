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

### Requirement: Homepage renders according to the effective homepage type

The system SHALL serve `GET /` by resolving the effective homepage type as: the saved `settings.homepage_type` override if not `NULL`, otherwise the active theme's `theme.json` `homepage_type`, otherwise `collection_list`. When the effective type is `collection_list`, the system SHALL render `home.twig` with all collections, unchanged from current behavior. When the effective type is `static_page`, the system SHALL render the entry referenced by `settings.homepage_page_id` the same way the public entry route renders it. If that entry no longer exists, the system SHALL fall back to rendering `collection_list` behavior instead of erroring. When the effective type is `blog`, the system SHALL render a paginated, most-recent-first listing of the published entry titles from the referenced collection — `settings.homepage_collection_id` when the effective type came from an admin override, otherwise the `homepage_collection_id` if one is saved, otherwise the `posts` collection by slug if it exists. Pagination SHALL use the same `?page=` query parameter and page size as the collection listing route. If no collection can be resolved, or the resolved collection no longer exists, the system SHALL fall back to rendering `collection_list` behavior instead of erroring.

#### Scenario: No override and no theme default

- **WHEN** a visitor requests `GET /` and neither an admin override nor the active theme declares a homepage type
- **THEN** the response is 200 and renders `home.twig` with all collections, matching today's behavior

#### Scenario: Theme declares a default homepage type

- **WHEN** a visitor requests `GET /`, no admin override is saved, and the active theme's `theme.json` declares `homepage_type: static_page` with a configured page
- **THEN** the response is 200 and renders the configured entry as the homepage

#### Scenario: Admin override takes precedence over theme default

- **WHEN** a visitor requests `GET /` and an admin override is saved that differs from the active theme's default
- **THEN** the response uses the admin override's homepage type, not the theme's default

#### Scenario: Configured static page has been deleted

- **WHEN** a visitor requests `GET /`, the effective homepage type is `static_page`, and the referenced entry no longer exists
- **THEN** the response is 200 and falls back to rendering `collection_list` behavior instead of erroring

#### Scenario: Admin selects blog as homepage

- **WHEN** a visitor requests `GET /`, the effective homepage type is `blog` with a saved `homepage_collection_id` referencing a collection with published entries
- **THEN** the response is 200 and renders the first page of that collection's published entry titles ordered most-recent-first

#### Scenario: Blog homepage pagination

- **WHEN** a visitor requests `GET /?page=2`, the effective homepage type is `blog`, and the referenced collection has more published entries than fit on one page
- **THEN** the response is 200 and renders the second page of that collection's published entries in most-recent-first order

#### Scenario: Blog homepage falls back to the posts collection

- **WHEN** a visitor requests `GET /`, the effective homepage type is `blog` via the active theme's default, and no `homepage_collection_id` is saved
- **THEN** the response is 200 and renders the `posts` collection's published entries if it exists, or falls back to `collection_list` behavior if it does not

#### Scenario: Configured blog collection has been deleted

- **WHEN** a visitor requests `GET /`, the effective homepage type is `blog`, and the referenced collection no longer exists
- **THEN** the response is 200 and falls back to rendering `collection_list` behavior instead of erroring

