## MODIFIED Requirements

### Requirement: Deterministic sample seeding

The command SHALL support an explicit `--seed` option that idempotently creates a development administrator, the documented empty sample collections, a starter `posts` collection with three fields (`title` text required as slug source, `body` richtext, `published_at` date), and three sample entries in that collection, and SHALL prevent accidental production seeding.

#### Scenario: Seed an empty database

- **WHEN** a contributor runs `bin/serve --seed` in a non-production environment after migrations
- **THEN** one usable administrator, the documented empty sample collections, the starter `posts` collection with its fields, and three sample entries exist before the server starts

#### Scenario: Repeat seed

- **WHEN** the same seed operation is run more than once
- **THEN** it does not create duplicate administrators, collections, fields, or entries and exits successfully

#### Scenario: Production seed refusal

- **WHEN** `--seed` is requested while the application environment is production without an explicit safe override
- **THEN** the command exits nonzero and makes no data changes

### Requirement: Evaluator smoke path

The project SHALL include an automated smoke test or equivalent repeatable check covering fresh setup, sample seeding, login, creating a collection, creating an entry, and seeing the entry listed in the admin.

#### Scenario: Clone-to-content verification

- **WHEN** the smoke check runs against a clean supported MySQL/MariaDB test database with valid configuration
- **THEN** migrations and seed complete, valid credentials can log in, a collection can be created and edited through the admin surface, an entry can be created and listed, and the entry's slug is generated from its slug-source field
