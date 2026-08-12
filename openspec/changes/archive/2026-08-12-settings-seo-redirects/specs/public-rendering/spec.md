## MODIFIED Requirements

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
