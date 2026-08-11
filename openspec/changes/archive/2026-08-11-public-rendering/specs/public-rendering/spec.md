## ADDED Requirements

### Requirement: Public collection listing route

The system SHALL serve `GET /{collectionSlug}` by resolving the collection via `CollectionRepository::findBySlug`, fetching a paginated page of its entries, and rendering the `collection.twig` template with the collection and its entries.

#### Scenario: Existing collection with entries

- **WHEN** a visitor requests `GET /{collectionSlug}` for a collection that exists and has entries
- **THEN** the response is 200 and renders `collection.twig` with the collection and the first page of its entries

#### Scenario: Unknown collection slug

- **WHEN** a visitor requests `GET /{collectionSlug}` for a slug that does not match any collection
- **THEN** the response is the existing 404 page and no entry data is exposed

### Requirement: Public entry route

The system SHALL serve `GET /{collectionSlug}/{entrySlug}` by resolving the collection and then the entry via `EntryRepository::findByCollectionAndSlug`, rendering the `entry.twig` template with the entry and its collection.

#### Scenario: Existing entry

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for an entry that exists in that collection
- **THEN** the response is 200 and renders `entry.twig` with the entry's values and its parent collection

#### Scenario: Unknown entry slug within a known collection

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` where the collection exists but no entry has that slug
- **THEN** the response is the existing 404 page

#### Scenario: Unknown collection slug

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for a collection slug that does not exist
- **THEN** the response is the existing 404 page

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
