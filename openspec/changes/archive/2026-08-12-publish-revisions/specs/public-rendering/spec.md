## MODIFIED Requirements

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

The system SHALL serve `GET /{collectionSlug}/{entrySlug}` by resolving the collection and then the `published` entry via `EntryRepository::findByCollectionAndSlug`, rendering the `entry.twig` template with the entry and its collection.

#### Scenario: Existing published entry

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for a published entry that exists in that collection
- **THEN** the response is 200 and renders `entry.twig` with the entry's values and its parent collection

#### Scenario: Unknown entry slug within a known collection

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` where the collection exists but no entry has that slug
- **THEN** the response is the existing 404 page

#### Scenario: Unknown collection slug

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for a collection slug that does not exist
- **THEN** the response is the existing 404 page

#### Scenario: Draft entry is not publicly reachable

- **WHEN** a visitor requests `GET /{collectionSlug}/{entrySlug}` for an entry that exists in that collection but has `status = draft`
- **THEN** the response is the existing 404 page and no entry data is exposed
