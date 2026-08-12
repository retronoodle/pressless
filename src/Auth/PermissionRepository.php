<?php

declare(strict_types=1);

namespace Stead\Auth;

use Stead\Database\Connection;

/**
 * Reads and writes `permissions` rows.
 *
 * A permission is a row keyed by (role_id, collection_id, action). The set
 * of actions is fixed by the spec: view, create, edit, delete, publish.
 * The collection id is the row's primary key in `collections`; the caller
 * resolves the slug to an id before calling this repository.
 */
final class PermissionRepository
{
    public const ACTION_VIEW = 'view';
    public const ACTION_CREATE = 'create';
    public const ACTION_EDIT = 'edit';
    public const ACTION_DELETE = 'delete';
    public const ACTION_PUBLISH = 'publish';

    public const ACTIONS = [
        self::ACTION_VIEW,
        self::ACTION_CREATE,
        self::ACTION_EDIT,
        self::ACTION_DELETE,
        self::ACTION_PUBLISH,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Returns the set of actions granted to the role on the collection.
     *
     * @return list<string>
     */
    public function actionsFor(int $roleId, int $collectionId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT action FROM permissions WHERE role_id = :role_id AND collection_id = :collection_id',
            ['role_id' => $roleId, 'collection_id' => $collectionId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) ($row['action'] ?? '');
        }
        return $out;
    }

    /**
     * Returns the collection ids the role has at least one grant on.
     *
     * @return list<int>
     */
    public function collectionIdsFor(int $roleId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT collection_id FROM permissions WHERE role_id = :role_id',
            ['role_id' => $roleId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = (int) ($row['collection_id'] ?? 0);
        }
        return $out;
    }

    /**
     * Replaces the role's grants on a collection with the supplied set of
     * actions. Rows in the new set are inserted; rows no longer in the
     * set are deleted. The operation runs in a single transaction.
     *
     * @param list<string> $actions
     */
    public function setActions(int $roleId, int $collectionId, array $actions): void
    {
        $this->connection->transaction(function () use ($roleId, $collectionId, $actions): void {
            $current = array_flip($this->actionsFor($roleId, $collectionId));
            $desired = array_flip(array_values(array_filter(
                $actions,
                static fn(string $a): bool => in_array($a, self::ACTIONS, true),
            )));

            $toRemove = array_diff_key($current, $desired);
            foreach (array_keys($toRemove) as $action) {
                $this->connection->execute(
                    'DELETE FROM permissions WHERE role_id = :role_id AND collection_id = :collection_id AND action = :action',
                    [
                        'role_id' => $roleId,
                        'collection_id' => $collectionId,
                        'action' => $action,
                    ],
                );
            }

            foreach (array_keys($desired) as $action) {
                if (isset($current[$action])) {
                    continue;
                }
                $this->connection->execute(
                    'INSERT INTO permissions (role_id, collection_id, action) VALUES (:role_id, :collection_id, :action)',
                    [
                        'role_id' => $roleId,
                        'collection_id' => $collectionId,
                        'action' => $action,
                    ],
                );
            }
        });
    }

    /**
     * Returns the full matrix of permissions for the editor/author roles
     * across every collection, keyed as `[role_id][collection_id][action]`.
     *
     * @return array<int, array<int, array<string, true>>>
     */
    public function fullMatrix(): array
    {
        $rows = $this->connection->fetchAll('SELECT role_id, collection_id, action FROM permissions');
        $out = [];
        foreach ($rows as $row) {
            $roleId = (int) ($row['role_id'] ?? 0);
            $collectionId = (int) ($row['collection_id'] ?? 0);
            $action = (string) ($row['action'] ?? '');
            $out[$roleId][$collectionId][$action] = true;
        }
        return $out;
    }
}
