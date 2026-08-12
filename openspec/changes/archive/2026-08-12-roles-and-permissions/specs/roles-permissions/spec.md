## ADDED Requirements

### Requirement: Fixed role assignment
The system SHALL define exactly three roles — `admin`, `editor`, `author` — as rows in a `roles` table, and SHALL require every user to have exactly one role via `users.role_id`.

#### Scenario: Create a user
- **WHEN** an administrator creates a user
- **THEN** the user is persisted with exactly one of `admin`, `editor`, or `author` as its role

#### Scenario: Existing admin accounts migrate to the admin role
- **WHEN** the roles migration runs against data from before this change
- **THEN** every user with `is_admin = 1` is assigned the `admin` role and the `is_admin` column no longer exists

### Requirement: Admin role has unrestricted access
A user with the `admin` role SHALL be authorized for every collection and every action without requiring rows in the `permissions` table.

#### Scenario: Admin accesses any collection
- **WHEN** a user with the `admin` role requests any collection's entry admin surface or performs create, edit, delete, or publish
- **THEN** the request is authorized regardless of the `permissions` table's contents

### Requirement: Per-collection, per-action permissions for editor and author roles
The system SHALL store grants for the `editor` and `author` roles in a `permissions` table keyed by role, collection, and action (`view`, `create`, `edit`, `delete`, `publish`). A user SHALL be authorized for a collection-scoped action only if a matching permission row exists for their role.

#### Scenario: Editor granted only Posts
- **WHEN** the `editor` role is granted `view`, `create`, `edit`, `publish` on the "Posts" collection and no permissions on any other collection
- **THEN** a user with the `editor` role can list, create, edit, and publish entries in Posts, and requests to any other collection's admin surface are denied

#### Scenario: Missing permission denies access
- **WHEN** a user's role has no permission row for the requested collection and action
- **THEN** the request is denied with a 403 response and no data for that collection is returned

#### Scenario: Permission editor updates grants
- **WHEN** an administrator changes which actions a role is granted on a collection via the permission editor
- **THEN** subsequent requests by users with that role reflect the updated grants immediately

### Requirement: Author ownership scoping
For the `author` role, the system SHALL additionally restrict the `edit`, `delete`, `publish`, and `unpublish` actions to entries where `entries.author_id` matches the requesting user's id, even when the role's `permissions` grant covers the action for that collection. The `view` and `create` actions SHALL NOT be restricted by ownership.

#### Scenario: Author edits own entry
- **WHEN** a user with the `author` role and an `edit` permission on a collection submits an edit for an entry they authored
- **THEN** the edit is authorized and applied

#### Scenario: Author blocked from another author's entry
- **WHEN** a user with the `author` role and an `edit` permission on a collection submits an edit for an entry authored by a different user
- **THEN** the request is denied with a 403 response and the entry is unchanged

#### Scenario: Author can view collection entries by others
- **WHEN** a user with the `author` role and a `view` permission on a collection requests the entry list
- **THEN** entries authored by other users are visible in the list, since `view` is not ownership-scoped

### Requirement: Admin user list and role assignment UI
The system SHALL provide an authenticated, admin-only admin surface at `/admin/users` listing all users with their current role, and a per-user view to change a user's role.

#### Scenario: List users
- **WHEN** an administrator requests `GET /admin/users`
- **THEN** the response lists every user with their name, email, and current role

#### Scenario: Change a user's role
- **WHEN** an administrator submits a role change for a user
- **THEN** the user's `role_id` is updated and subsequent requests by that user are authorized under the new role

#### Scenario: Non-admin cannot access user management
- **WHEN** a user without the `admin` role requests `/admin/users` or a role-assignment action
- **THEN** the request is denied with a 403 response

### Requirement: Per-role, per-collection permission editor UI
The system SHALL provide an authenticated, admin-only admin surface for editing which actions the `editor` and `author` roles are granted on each collection.

#### Scenario: Grant a permission
- **WHEN** an administrator grants the `author` role `edit` on the "Posts" collection through the permission editor
- **THEN** a corresponding row is created in `permissions` and users with the `author` role gain that grant

#### Scenario: Revoke a permission
- **WHEN** an administrator revokes a previously granted action for a role on a collection
- **THEN** the corresponding `permissions` row is removed and users with that role immediately lose the grant

#### Scenario: Non-admin cannot access the permission editor
- **WHEN** a user without the `admin` role requests the permission editor
- **THEN** the request is denied with a 403 response
