# Capability: authentication

## Purpose

TBD

## Requirements

### Requirement: Bcrypt password storage
The authentication system SHALL store passwords only as bcrypt hashes created by PHP's password hashing API and SHALL verify submitted passwords without comparing or logging plaintext values.

#### Scenario: Create administrator credential
- **WHEN** a seed or account service creates a user with a password
- **THEN** the stored value is a bcrypt hash and is not equal to the submitted plaintext

#### Scenario: Verify credential
- **WHEN** a user submits the correct password
- **THEN** password verification succeeds using the stored hash

### Requirement: Session-backed login
The system SHALL authenticate an active user from a login request, establish a native PHP session with a database-backed lifecycle record, regenerate the session identifier after successful authentication, and redirect the user to the admin shell.

#### Scenario: Successful login
- **WHEN** an active user submits a valid email and password to `POST /admin/login`
- **THEN** the response establishes an authenticated session, records its expiry/lifecycle data, and redirects to `/admin`

#### Scenario: Invalid login
- **WHEN** a user submits an unknown email, inactive account, or incorrect password
- **THEN** authentication fails, no authenticated session is created, and the login response gives a generic error

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

### Requirement: Protected admin access
The system SHALL require an authenticated session for every protected admin route and SHALL preserve the originally requested path when redirecting an unauthenticated browser to login where safe. Beyond authentication, collection-scoped and entry-scoped admin routes SHALL additionally require authorization per the `roles-permissions` capability; an authenticated user who is not authorized for the requested action SHALL receive a 403 response rather than the unauthenticated redirect.

#### Scenario: Unauthenticated admin request
- **WHEN** a browser requests `/admin` without a valid session
- **THEN** the response redirects to `/admin/login` and does not render protected admin content

#### Scenario: Expired session
- **WHEN** a session record is expired, revoked, or cannot be loaded
- **THEN** the request is treated as unauthenticated and the stale session is not used

#### Scenario: Authenticated but not authorized
- **WHEN** an authenticated user without the required role or collection permission requests a protected admin route
- **THEN** the response is 403 and no protected data for that route is returned

### Requirement: Logout and session invalidation
The system SHALL support logout that invalidates the current session record, clears the native session, and prevents the same session from accessing protected routes afterward.

#### Scenario: Successful logout
- **WHEN** an authenticated user submits the logout action
- **THEN** the session is destroyed and the response redirects to the login page

### Requirement: Secure session handling
Authentication SHALL use secure cookie attributes appropriate to the configured environment, regenerate identifiers on privilege establishment, and avoid revealing whether an email address exists in login errors.

#### Scenario: Production cookie configuration
- **WHEN** the application environment is production and a session is started
- **THEN** the session cookie uses HTTP-only behavior, an appropriate SameSite policy, and the Secure attribute when HTTPS is configured
