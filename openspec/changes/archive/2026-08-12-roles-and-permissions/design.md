## Context

Authentication (Phase 1) already establishes a session and loads a `User` onto the request via `AuthGuard::protect()` (`src/Auth/AuthGuard.php:27-40`). Authorization does not exist: every authenticated user can reach every admin route. The only structural pieces already in place for this phase are `users.is_admin` (boolean, currently unused for gating) and `entries.author_id` (already written on every entry save, currently unused for gating). Routes are registered individually in `Routes::create()` (`src/Http/Routes.php`) as `$guard->protect($controller->action(...))` — there is no middleware pipeline, just composable closures.

## Goals / Non-Goals

**Goals:**
- Three fixed roles — `admin`, `editor`, `author` — each user has exactly one.
- Admins have full access; editors and authors are scoped per-collection via a `permissions` table (view/create/edit/delete/publish).
- Authors are additionally restricted to their own entries (`entries.author_id`) for edit/delete/publish/unpublish.
- Authorization composes with the existing `AuthGuard::protect()` pattern rather than replacing it.
- Admin UI to list users, assign a role, and edit a role's per-collection permissions.

**Non-Goals:**
- Inviting users by email (Phase 8) — this change creates users directly from the admin UI.
- Media library permission granularity — media admin routes stay reachable by any authenticated user in this change; scoping media by role/collection is deferred.
- Per-entry sharing/collaboration beyond the fixed author-ownership rule (e.g. no "grant this one entry to another author").
- Custom/configurable roles beyond the fixed three.

## Decisions

**Roles are rows in a `roles` table, not a hardcoded enum.** Keeps `users.role_id` a normal FK (referential integrity, easy display joins) even though the set of three is fixed in this phase. Alternative considered: a `VARCHAR` role column with an app-level enum — rejected because the PRD explicitly calls for a `roles` table and a join-table permission model, and a real table gives the permission editor a stable id to key off.

**`admin` bypasses the `permissions` table entirely.** An admin's authorization check short-circuits to "allowed," rather than requiring rows in `permissions` for every collection/action. Alternative considered: seed full permission rows for `admin` on every collection — rejected as unnecessary write amplification every time a collection is created, and a foot-gun if a row is missing.

**`editor` and `author` are both scoped via `permissions` (role_id, collection_id, action); only `author` additionally applies ownership scoping.** This matches the PRD's role list (`admin`/`editor`/`author`) and task 6, which calls out ownership scoping as an `author`-specific behavior, not a general rule. `editor` with a granted `edit` permission may edit any entry in that collection.

**Authorization is enforced in two places, chosen per what data is available at each point:**
1. **Collection-scoped actions** (list entries, create, access the collection's admin surface at all) are gated by a new wrapper, `CollectionAuthorization::requireAction(string $action, callable $handler)`, composed the same way `AuthGuard::protect()` is: `$guard->protect($collectionAuth->requireAction('view', $controller->index(...)))`. It reads the collection slug from the route's `{slug}` parameter at request time (registration time only has the pattern, not the value) and calls `AuthorizationService::can($user, $slug, $action)`.
2. **Entry-specific actions** (edit/delete/publish/unpublish on one entry) need the loaded entry to apply ownership scoping, and the controllers already load the entry before acting. Rather than adding an entry-fetching authorization wrapper (which would fetch the entry twice), each entry-mutating controller method calls `AuthorizationService::canEntry($user, $slug, $action, $entry)` right after it loads the entry, before mutating, returning 403 if false.

   Alternative considered: a single wrapper that pre-fetches the entry and attaches it to the request for the controller to reuse — rejected as a bigger change to the controller signatures for a marginal duplicate-query cost, and it complicates the "who owns fetching the entry" contract.

**Collection *schema* management (create/edit/delete a collection's field definitions) is admin-only**, not covered by the `permissions` table. The PRD's per-collection permission editor is about entry actions within a collection an editor/author is granted; letting a non-admin restructure the schema of a collection they're scoped to is a different, larger capability the PRD doesn't ask for here.

**Filtering list views (e.g. "which collections does this user see in the sidebar") uses a new `AuthorizationService::grantedCollectionSlugs(User $user, string $action = 'view'): array`**, called by `AdminController`/`CollectionAdminController` index views to build the visible collection list, rather than gating collection listing entirely at the route level.

## Risks / Trade-offs

- **[Breaking] `users.is_admin` removal breaks any code or template still branching on it.** → `src/Console/Seeder.php` and any Twig admin views need updating in the same change; grep confirms these are the only current call sites (per research), so the blast radius is small and known.
- **[Data] Existing non-admin users (`is_admin = 0`) have no natural target role on migration.** → See Open Questions; migration defaults them to `editor` with no collection permissions granted (safest default — they lose access rather than silently gaining scoped access to collections not intended for them), and the smoke test / release notes should call out that an admin must grant permissions post-migration.
- **[Perf] Two authorization paths (wrapper + inline) could drift if a new entry action is added and only one path is updated.** → `AuthorizationService::can()`/`canEntry()` are the single source of truth; both call sites are thin, and `canEntry()` internally calls `can()` first, so a new action only needs the `can()` check to be correct everywhere; the ownership layer is additive.
- **[UX] An `author` with no granted collections sees an empty admin.** → Acceptable for this phase (matches "verify other collections are inaccessible" smoke test); a friendlier empty state is admin-UX polish (Phase 14), not blocking.

## Migration Plan

1. New migration `..._roles_and_permissions.{mysql,sqlite}.sql`:
   - `CREATE TABLE roles` seeded with fixed rows `admin`, `editor`, `author`.
   - `CREATE TABLE permissions (role_id, collection_id, action, UNIQUE(role_id, collection_id, action))`.
   - `ALTER TABLE users ADD COLUMN role_id` (FK to `roles`), backfill `role_id = admin` where `is_admin = 1`, else `role_id = editor` (see Open Questions), then `ALTER TABLE users DROP COLUMN is_admin` in the same migration (single-step, no dual-write window — pre-1.0, no external consumers of this column).
2. Update `User`, `UserRepository` to read/write `role_id`/role name instead of `is_admin`; update `Seeder` and any admin Twig views branching on `is_admin`.
3. Add `AuthorizationService`, `CollectionAuthorization` wrapper, apply to routes in `Routes.php`, add inline `canEntry()` checks to `EntryAdminController`.
4. Add `/admin/users` (list), `/admin/users/{id}/role` (assign role), `/admin/permissions` or per-role editor (grant collection/action) admin UI + controllers.
5. Rollback: revert the migration (re-add `is_admin`, backfill from `role_id = admin`, drop `roles`/`permissions`) plus the corresponding code revert — acceptable given this ships as one atomic change with no intermediate deployed state.

## Open Questions

- **Default role for existing non-admin accounts on migration.** Proposed default: `editor` with zero granted permissions (safe-by-default, admin must explicitly grant). Confirm before implementing — this affects real accounts on any already-installed dev/eval instance.
- **Can an `editor` be granted `delete`/`publish` independently of `edit`,** or are these always granted together in the UI? Proposed: independent rows in `permissions`, so the editor UI can offer fine-grained checkboxes per action; simpler to under-grant and expand later than to walk back a bundled grant.
