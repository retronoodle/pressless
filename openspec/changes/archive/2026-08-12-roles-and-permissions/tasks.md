## 1. Schema

- [x] 1.1 Write migration `..._roles_and_permissions.mysql.sql` / `.sqlite.sql`: `CREATE TABLE roles` seeded with `admin`, `editor`, `author`
- [x] 1.2 Same migration: `CREATE TABLE permissions (role_id, collection_id, action, UNIQUE(role_id, collection_id, action))` with FKs to `roles`/`collections`
- [x] 1.3 Same migration: add `users.role_id` FK, backfill `admin` from `is_admin = 1` and `editor` (no grants) from `is_admin = 0`, then drop `users.is_admin`

## 2. Role-aware user model

- [x] 2.1 Update `src/Auth/User.php` to carry role (id/name) instead of `isAdmin`; update `jsonSerialize()`/`__debugInfo()`
- [x] 2.2 Update `src/Auth/UserRepository.php` reads/writes (`findByEmail`, `findById`, `create`) for `role_id`
- [x] 2.3 Update `src/Console/Seeder.php` and any other `is_admin` call sites found by grep

## 3. Authorization service

- [x] 3.1 Build `AuthorizationService`: `isAdmin()`, `can($user, $collectionSlug, $action)`, `canEntry($user, $collectionSlug, $action, $entry)`, `grantedCollectionSlugs($user, $action)`
- [x] 3.2 Build `CollectionAuthorization::requireAction($action, $handler)` wrapper composing with `AuthGuard::protect()`
- [x] 3.3 Unit test `AuthorizationService` for admin bypass, editor grant/deny, author ownership scoping (own vs. others' entries), view/create unaffected by ownership

## 4. Wire authorization into routes

- [x] 4.1 Apply `CollectionAuthorization` to collection-scoped entry routes in `Routes.php` (list/create actions)
- [x] 4.2 Add inline `canEntry()` checks in `EntryAdminController` for edit/delete/publish/unpublish, returning 403 on denial
- [x] 4.3 Restrict collection schema management (create/edit/delete collection) to `admin` role only
- [x] 4.4 Filter the admin collection list / sidebar via `grantedCollectionSlugs()` so ungranted collections aren't shown

## 5. Admin UI — users & roles

- [x] 5.1 Build `UserAdminController` + `/admin/users` list view (name, email, role), admin-only
- [x] 5.2 Build role assignment view/action per user, admin-only
- [x] 5.3 Build permission editor view/action for `editor`/`author` × collection × action, admin-only

## 6. Smoke test

- [x] 6.1 Create an editor user, grant `view`/`create`/`edit`/`publish` on Posts only → verify Posts is manageable and all other collections return 403/are hidden
- [x] 6.2 Create an author user with `edit` on Posts → verify they can edit their own entry and are denied editing another author's entry in the same collection
