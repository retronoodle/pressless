## MODIFIED Requirements

### Requirement: Tagged-release CI pipeline

The system SHALL build and publish a GitHub Release automatically when a `vX.Y.Z` git tag is pushed.

#### Scenario: Tag push triggers a GitHub Release

- **WHEN** a tag matching `vX.Y.Z` is pushed to the repository
- **THEN** CI runs `bin/release`, producing a ZIP stamped with that version, and creates a GitHub Release for that tag with the ZIP attached as a release asset

#### Scenario: Non-release tag or branch push does not publish

- **WHEN** a commit is pushed to a branch, or a tag not matching `vX.Y.Z` is pushed
- **THEN** the release pipeline SHALL NOT run
