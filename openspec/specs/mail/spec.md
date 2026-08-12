# Capability: mail

## Purpose

TBD

## Requirements

### Requirement: SMTP mail transport
The system SHALL send all outbound email via a configurable SMTP transport (host, port, encryption mode, and optional auth credentials). The system SHALL NOT use PHP's `mail()` function.

#### Scenario: Sending a message
- **WHEN** a message is dispatched through the mail transport with valid SMTP settings configured
- **THEN** the system connects to the configured SMTP host, authenticates if credentials are set, and delivers the message

#### Scenario: SMTP settings missing or invalid
- **WHEN** a message is dispatched but no SMTP host is configured, or the connection/authentication fails
- **THEN** the system raises a mail delivery error identifying the failure reason, and does not silently discard the message

### Requirement: Mail settings admin UI
Admins SHALL be able to view and edit SMTP configuration (host, port, encryption, username, password) from the admin UI, and trigger a test send to verify the configuration.

#### Scenario: Saving mail settings
- **WHEN** an admin submits SMTP host, port, encryption, and credentials in the mail settings form
- **THEN** the system persists the settings and they are used for all subsequent sends

#### Scenario: Test send succeeds
- **WHEN** an admin clicks "send test email" with valid saved SMTP settings
- **THEN** the system sends a test message to the admin's own address and confirms success in the UI

#### Scenario: Test send fails
- **WHEN** an admin clicks "send test email" with invalid or unreachable SMTP settings
- **THEN** the system shows the delivery error in the UI without crashing the request

### Requirement: Mail settings persistence
Mail settings SHALL be stored in the database (not only in environment/config files) so admins can change them without redeploying, with credentials never rendered back into the settings form in plaintext after saving.

#### Scenario: Settings survive across requests
- **WHEN** an admin saves SMTP settings and reloads the mail settings page
- **THEN** the previously saved host, port, and encryption values are shown (password field left blank, not echoed)
