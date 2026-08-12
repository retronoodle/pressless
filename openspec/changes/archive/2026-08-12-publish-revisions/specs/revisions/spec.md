## ADDED Requirements

### Requirement: Revision snapshot on entry save

The system SHALL write a `revisions` row capturing an entry's field values, slug, title, author, and timestamp immediately before that entry's existing state is overwritten by a save, using the entry's state as it was prior to the save. The system SHALL NOT write a revision when an entry is created for the first time (there is no prior state to capture).

#### Scenario: Save an existing entry writes a revision

- **WHEN** an administrator edits and saves an existing entry
- **THEN** a `revisions` row is written whose payload reflects the entry's field values as they were immediately before the save, with the current authenticated user as author and the current timestamp

#### Scenario: Creating a new entry writes no revision

- **WHEN** an administrator creates a new entry for the first time
- **THEN** no `revisions` row is written for that save

#### Scenario: Publish and unpublish do not themselves snapshot

- **WHEN** an administrator publishes or unpublishes an entry without changing its field values
- **THEN** no additional `revisions` row is written beyond what a value-changing save would produce

### Requirement: Configurable revision retention

The system SHALL enforce a configurable maximum number of retained revisions per entry, read from configuration with a default value. After writing a new revision, the system SHALL prune the oldest revisions for that entry beyond the configured limit, within the same transaction as the save.

#### Scenario: Save within the retention limit

- **WHEN** an entry has fewer revisions than the configured retention limit and is saved again
- **THEN** the new revision is added and no existing revisions are pruned

#### Scenario: Save past the retention limit

- **WHEN** an entry already has revisions equal to the configured retention limit and is saved again
- **THEN** the new revision is added and the oldest revision(s) beyond the limit are deleted, leaving exactly the configured limit of revisions

### Requirement: Revision list per entry

The system SHALL provide an authenticated admin view listing an entry's revisions in reverse-chronological order, showing each revision's timestamp and author.

#### Scenario: View revision history

- **WHEN** an administrator requests the revision list for an entry with saved revisions
- **THEN** the response lists the entry's revisions newest-first with timestamp and author for each

#### Scenario: Entry with no revisions

- **WHEN** an administrator requests the revision list for an entry that has never been re-saved
- **THEN** the response shows an empty revision list without error

### Requirement: Restore from revision

The system SHALL allow an authenticated administrator to restore an entry to the field values captured in one of its revisions. Restoring SHALL re-validate the revision's payload against the collection's current schema and persist it through the same save path as a normal entry edit, including writing a new revision of the entry's pre-restore state.

#### Scenario: Restore a prior revision

- **WHEN** an administrator selects a revision and confirms restore
- **THEN** the entry's field values are replaced with that revision's payload, the entry is saved, and a new revision capturing the entry's state immediately before the restore is written

#### Scenario: Restoring an invalid revision

- **WHEN** a revision's payload fails validation against the collection's current schema (e.g., a since-added required field is missing)
- **THEN** the restore fails with the same validation errors a form submission would show, and the entry's current state is unchanged
