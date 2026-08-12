## MODIFIED Requirements

### Requirement: Manual update instructions

The system SHALL trigger a backup before showing manual update instructions, and SHALL provide manual update instructions rather than applying an update automatically.

#### Scenario: Admin views update instructions

- **WHEN** an admin interacts with the update-available notice and the pre-update backup succeeds
- **THEN** the system shows instructions to download the new release ZIP and extract it over the existing install, and does not attempt to apply the update itself

#### Scenario: Pre-update backup fails

- **WHEN** an admin interacts with the update-available notice and the triggered backup fails
- **THEN** the system shows the backup failure instead of the update instructions, and does not present the download/extract steps
