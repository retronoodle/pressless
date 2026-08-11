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

### Requirement: Protected admin access
The system SHALL require an authenticated session for every protected admin route and SHALL preserve the originally requested path when redirecting an unauthenticated browser to login where safe.

#### Scenario: Unauthenticated admin request
- **WHEN** a browser requests `/admin` without a valid session
- **THEN** the response redirects to `/admin/login` and does not render protected admin content

#### Scenario: Expired session
- **WHEN** a session record is expired, revoked, or cannot be loaded
- **THEN** the request is treated as unauthenticated and the stale session is not used

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
