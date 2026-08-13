## ADDED Requirements

### Requirement: Manual default-collection seeding
The system SHALL provide an authenticated, admin-only action at `POST /admin/settings/seed-default-collections` that creates any of the `posts`/`pages` collections that don't already exist, using the same seeding routine the installer uses, and redirects to `/admin/settings` reporting how many collections were created.

#### Scenario: Both collections missing
- **WHEN** an administrator submits the seed-default-collections action and neither `posts` nor `pages` exists
- **THEN** both collections are created and the response redirects with a flash message reporting 2 collections created

#### Scenario: Collections already present
- **WHEN** an administrator submits the seed-default-collections action and both `posts` and `pages` already exist
- **THEN** no collections are created, no existing collection is modified, and the response redirects with a flash message stating they are already present

#### Scenario: Non-admin cannot trigger seeding
- **WHEN** an authenticated non-admin user submits `POST /admin/settings/seed-default-collections`
- **THEN** the request is rejected the same way other admin-only actions reject non-admins
