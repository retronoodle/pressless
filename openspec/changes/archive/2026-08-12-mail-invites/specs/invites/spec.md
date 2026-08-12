## ADDED Requirements

### Requirement: Admin sends invite
An admin SHALL be able to invite a new user by email, assigning a role at invite time. The system SHALL generate a single-use invite token and send it to the invitee via the mail transport.

#### Scenario: Sending an invite
- **WHEN** an admin submits an email address and a role in the invite form
- **THEN** the system creates an invite record with an expiring token and sends an email containing an acceptance link to that address

#### Scenario: Inviting an already-registered email
- **WHEN** an admin submits an email address that already belongs to an existing user
- **THEN** the system rejects the invite with a clear error and does not create a duplicate account or token

#### Scenario: Re-inviting a pending invitee
- **WHEN** an admin sends a new invite for an email that has an existing, unaccepted invite
- **THEN** the system invalidates the previous token and issues a new one

### Requirement: Invite token security
Invite tokens SHALL be single-use, expire after a fixed time window, and be stored hashed so a database compromise does not expose usable tokens.

#### Scenario: Expired token
- **WHEN** an invitee opens an acceptance link after the invite's expiry window has passed
- **THEN** the system rejects the token as expired and does not create an account

#### Scenario: Already-accepted token
- **WHEN** an invitee (or anyone with the link) opens an acceptance link for a token that has already been accepted
- **THEN** the system rejects the token as already used

#### Scenario: Invalid or unknown token
- **WHEN** a request is made to the acceptance page with a token that does not match any stored invite
- **THEN** the system shows a generic invalid-invite error without revealing whether any invite exists for that value

### Requirement: Invite acceptance
An invitee with a valid, unexpired token SHALL be able to set a password and have an account created with the role assigned at invite time.

#### Scenario: Accepting an invite
- **WHEN** an invitee submits a valid password on the acceptance page for a valid, unexpired, unused token
- **THEN** the system creates a user account with the invited email and role, marks the invite as accepted, and logs the new user in or redirects to login

#### Scenario: Weak password on acceptance
- **WHEN** an invitee submits a password that fails the system's password policy
- **THEN** the system rejects the submission with a validation error and does not create an account or consume the token
