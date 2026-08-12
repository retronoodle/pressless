# Capability: release-build

## Purpose

TBD

## Requirements

### Requirement: Dist ZIP build command

The system SHALL provide a `bin/release` command that produces a version-stamped, dependency-vendored, dev-file-stripped dist ZIP suitable for extraction on an end-user host.

#### Scenario: Building a release ZIP

- **WHEN** a maintainer runs `bin/release <version>`
- **THEN** the command installs production-only composer dependencies (`--no-dev`), excludes test/dev-only paths (`tests/`, `.git/`, `openspec/`, dev configs), writes a `VERSION` file containing `<version>`, and produces a single ZIP archive containing the result

#### Scenario: Dist ZIP excludes dev/test artifacts

- **WHEN** the produced ZIP is inspected after a build
- **THEN** it SHALL NOT contain `.env`, `tests/`, `.git/`, `phpunit.xml`, `phpstan.neon`, or `openspec/`

### Requirement: Tagged-release CI pipeline

The system SHALL build and publish a dist ZIP automatically when a `vX.Y.Z` git tag is pushed.

#### Scenario: Tag push triggers a publish

- **WHEN** a tag matching `vX.Y.Z` is pushed to the repository
- **THEN** CI runs `bin/release`, producing a ZIP stamped with that version, and publishes it to the project website's release/download endpoint

#### Scenario: Non-release tag or branch push does not publish

- **WHEN** a commit is pushed to a branch, or a tag not matching `vX.Y.Z` is pushed
- **THEN** the release pipeline SHALL NOT run
