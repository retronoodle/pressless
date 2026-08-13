## ADDED Requirements

### Requirement: Default collections seeded at completion
The installer SHALL create the `posts` and `pages` collections during `complete()` regardless of the sample-data choice, using the same collection-seeding routine the sample-data seeder uses. It SHALL skip creating either collection if a collection with that slug already exists.

#### Scenario: Sample data declined
- **WHEN** the user completes the installer wizard without opting into sample data
- **THEN** the `posts` and `pages` collections exist after completion, with no entries seeded into either

#### Scenario: Sample data accepted
- **WHEN** the user completes the installer wizard and opts into sample data
- **THEN** the `posts` and `pages` collections exist, and the three sample `posts` entries are seeded as before

#### Scenario: Collection already exists
- **WHEN** a `posts` or `pages` collection already exists at the time `complete()` runs
- **THEN** the existing collection is left unchanged and no duplicate is created
