## ADDED Requirements

### Requirement: Login attempt throttling
The system SHALL track failed login attempts per submitted email address and per requesting IP address, and SHALL lock out further login attempts for an identifier that exceeds a configurable failure threshold within a rolling time window. Lockout duration SHALL increase with exponential backoff on repeated lockouts, up to a configured maximum, and SHALL clear automatically once the backoff period elapses. Failures SHALL be recorded regardless of whether the submitted email corresponds to an existing account, and a locked-out response SHALL NOT reveal whether the account exists.

#### Scenario: Exceeding the failure threshold locks out the identifier
- **WHEN** an email address or IP address submits enough failed login attempts to reach the configured threshold within the configured window
- **THEN** further login attempts from that identifier are rejected with a generic lockout message and no credential check is performed

#### Scenario: Lockout does not leak account existence
- **WHEN** a locked-out identifier submits a login attempt, whether or not the submitted email corresponds to a real account
- **THEN** the response is the same generic lockout message in both cases

#### Scenario: Repeated lockouts escalate via exponential backoff
- **WHEN** an identifier is locked out, its lockout period elapses, and it then fails the threshold again
- **THEN** the next lockout period is longer than the previous one, up to the configured maximum

#### Scenario: Backoff clears after the window elapses
- **WHEN** an identifier's lockout period has fully elapsed
- **THEN** that identifier can attempt login again and is evaluated against the current failure count, not treated as still locked

#### Scenario: Successful login resets the email's failure count
- **WHEN** a user successfully authenticates
- **THEN** prior recorded failures for that email address no longer count toward that email's lockout threshold
