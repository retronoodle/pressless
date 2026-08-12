## Why

Today every authenticated user is either a full admin (`users.is_admin = 1`) or effectively powerless — there is no way to invite an editor who can manage only the Posts collection, which is the explicit Phase 7 goal in the PRD. Ownership data already exists (`entries.author_id`) but nothing enforces it. Without role-scoped and collection-scoped authorization, every collaborator on a site must be trusted with full admin access.

## What Changes

- Add a `roles` table (fixed set: `admin`, `editor`, `author`) and assign each user exactly one role via `users.role_id` (or equivalent). **BREAKING**: `users.is_admin` is replaced by role membership — admin behavior becomes "user has the `admin` role" rather than a boolean flag.
- Add a `permissions` table scoping non-admin roles to specific collections and actions (view/create/edit/delete/publish), keyed by role × collection × action.
- Build an authorization layer that composes with the existing `AuthGuard`: route handlers can require a role and/or a collection-scoped permission before running.
- Enforce ownership scoping for the `author` role: authors may only edit/delete/publish their own entries (`entries.author_id = current user`), never other authors' entries, even within collections they're granted.
- Add admin UI: a user list (`/admin/users`), a per-user role assignment view, and a per-role, per-collection permission editor.
- Gate existing collection/entry admin routes and the collections list itself by the new authorization checks, so a restricted user only sees collections they have at least one permission on.
- Seed data / migration: existing `is_admin = 1` users become `admin` role; existing `is_admin = 0` users become... TBD (see Impact) — needs an explicit default so no account is silently locked out.

Out of scope for this change (explicitly deferred to later phases per the PRD): inviting users by email (Phase 8), login rate limiting (Phase 9). Creating a new user in this change is done directly by an admin from the admin UI (no invite/token flow yet).

## Capabilities

### New Capabilities
- `roles-permissions`: role and permission data model (roles, permissions tables), authorization checks (role-required, collection-permission-required), ownership scoping for the `author` role, and the admin UI for managing users' roles and collection permissions.

### Modified Capabilities
- `authentication`: the authenticated user's authorization context now includes role and permission data (not just "is a valid session"); `AuthGuard`-protected routes gain an additional authorization step beyond "is logged in." No change to login/session/logout mechanics themselves.

## Impact

- **Schema**: new `roles` and `permissions` tables; `users` table changes (`is_admin` column replaced by `role_id` FK, or role_id added and is_admin dropped in a follow-up statement within the same migration). Existing `entries.author_id` is read (not altered) for ownership scoping.
- **Code**: `src/Auth/User.php`, `src/Auth/UserRepository.php` (role-aware reads/writes), `src/Auth/AuthGuard.php` (new authorization wrapper alongside `protect()`), `src/Http/Routes.php` (apply authorization to collection/entry/media routes), new controllers for user list, role assignment, and permission editing under `/admin/users`.
- **Callers of `User`**: anything reading `User::$isAdmin` or `jsonSerialize()`'s `is_admin` key needs updating to the new role model — primarily `src/Console/Seeder.php` and any Twig templates/admin views that branch on admin status.
- **Migrations**: new migration file(s) under `database/migrations/` (MySQL + SQLite variants), following the `YYYYMMDDHHMMSS_description` convention.
