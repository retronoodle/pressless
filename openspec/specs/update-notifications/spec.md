# Capability: update-notifications

## Purpose

TBD

## Requirements

### Requirement: Admin update-available notice

The system SHALL show an admin-visible notice when the update checker reports a newer version than the one currently installed.

#### Scenario: Newer version available

- **WHEN** an admin loads a page and the cached update check shows a newer version than installed
- **THEN** the admin UI displays a notice stating a newer version is available, including the latest version number

#### Scenario: No update available

- **WHEN** the cached update check shows the installed version is current (or the check failed)
- **THEN** the admin UI SHALL NOT display an update notice

### Requirement: Manual update instructions

The system SHALL provide manual update instructions rather than applying an update automatically.

#### Scenario: Admin views update instructions

- **WHEN** an admin interacts with the update-available notice
- **THEN** the system shows instructions to download the new release ZIP and extract it over the existing install, and does not attempt to apply the update itself
