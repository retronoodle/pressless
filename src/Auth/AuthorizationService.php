<?php

declare(strict_types=1);

namespace Stead\Auth;

use Stead\Content\Collection;
use Stead\Content\CollectionRepository;
use Stead\Content\Entry;

/**
 * Answers authorization questions on behalf of route handlers.
 *
 * The service is the single source of truth for "is this user allowed to do
 * this action on this collection / entry?" — `canEntry()` delegates to
 * `can()` first, then layers ownership scoping for the `author` role.
 * Admin short-circuits both checks before any database lookup.
 */
final class AuthorizationService
{
    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly PermissionRepository $permissions,
    ) {
    }

    public function isAdmin(?User $user): bool
    {
        return $user !== null && $user->isAdmin();
    }

    /**
     * Collection-scoped authorization check. Returns true for admins
     * regardless of the `permissions` table; otherwise requires a row in
     * `permissions` matching the user's role, the collection, and the
     * requested action.
     */
    public function can(?User $user, string $collectionSlug, string $action): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $collection = $this->collections->findBySlug($collectionSlug);
        if ($collection === null) {
            return false;
        }
        return in_array($action, $this->permissions->actionsFor($user->roleId, $collection->id()), true);
    }

    /**
     * Like {@see can()} but additionally applies ownership scoping for the
     * `author` role on mutating actions. `view` and `create` are not
     * ownership-scoped — an author may list and create entries in a
     * granted collection regardless of who authored the existing entries.
     */
    public function canEntry(?User $user, string $collectionSlug, string $action, Entry $entry): bool
    {
        if (!$this->can($user, $collectionSlug, $action)) {
            return false;
        }
        if ($user === null || !$user->isAdmin()) {
            if ($user !== null && $user->roleName === User::ROLE_AUTHOR && $this->isOwnershipScoped($action)) {
                return $entry->authorId() === $user->id;
            }
        }
        return true;
    }

    /**
     * Returns the slugs of every collection the user has at least one
     * grant on for the given action. Admins receive every collection slug.
     *
     * @return list<string>
     */
    public function grantedCollectionSlugs(?User $user, string $action = PermissionRepository::ACTION_VIEW): array
    {
        if ($user === null) {
            return [];
        }
        if ($user->isAdmin()) {
            $out = [];
            foreach ($this->collections->all() as $collection) {
                $out[] = $collection->slug();
            }
            return $out;
        }
        $ids = $this->permissions->collectionIdsFor($user->roleId);
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->collections->all() as $collection) {
            if (!in_array($collection->id(), $ids, true)) {
                continue;
            }
            if (in_array($action, $this->permissions->actionsFor($user->roleId, $collection->id()), true)) {
                $out[] = $collection->slug();
            }
        }
        return $out;
    }

    /**
     * Returns the slugs of every collection the user can at least view.
     * Convenience for callers that only need the visible set.
     *
     * @return list<string>
     */
    public function visibleCollectionSlugs(?User $user): array
    {
        return $this->grantedCollectionSlugs($user, PermissionRepository::ACTION_VIEW);
    }

    private function isOwnershipScoped(string $action): bool
    {
        return in_array(
            $action,
            [
                PermissionRepository::ACTION_EDIT,
                PermissionRepository::ACTION_DELETE,
                PermissionRepository::ACTION_PUBLISH,
            ],
            true,
        );
    }
}
