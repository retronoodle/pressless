## MODIFIED Requirements

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
