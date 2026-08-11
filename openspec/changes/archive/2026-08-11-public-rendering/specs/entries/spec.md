## ADDED Requirements

### Requirement: Paginated collection entry listing

The system SHALL provide a paginated variant of listing entries by collection, returning a bounded page of entries via limit/offset and the total count needed to determine whether further pages exist. This SHALL be used by the public collection listing route in addition to any existing unbounded listing used by the admin surface.

#### Scenario: First page of a collection with more entries than the page size

- **WHEN** a collection has more entries than one page size and a page is requested with no page number
- **THEN** the response includes the first page of entries and indicates that a next page exists

#### Scenario: Requesting a page beyond the last entry

- **WHEN** a page number is requested that is beyond the collection's last entry
- **THEN** the response is an empty page of entries, not an error

#### Scenario: Collection with fewer entries than the page size

- **WHEN** a collection has fewer entries than one page size
- **THEN** the response includes all of its entries on the first page and indicates no further pages exist
