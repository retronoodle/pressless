<?php

declare(strict_types=1);

namespace Stead\Themes;

use Stead\Database\Connection;
use Stead\Exception\SafeException;

/**
 * Reads and writes installed themes. Exactly one row may be active at a time.
 */
final class ThemeRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<Theme>
     */
    public function listAll(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT id, slug, name, version, author, is_active, created_at, updated_at
               FROM themes
           ORDER BY is_active DESC, name ASC, id ASC',
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = Theme::fromRow($row);
        }
        return $out;
    }

    public function findActive(): ?Theme
    {
        $row = $this->connection->fetchOne(
            'SELECT id, slug, name, version, author, is_active, created_at, updated_at
               FROM themes
              WHERE is_active = 1
              LIMIT 1',
        );
        return $row === null ? null : Theme::fromRow($row);
    }

    public function findBySlug(string $slug): ?Theme
    {
        $row = $this->connection->fetchOne(
            'SELECT id, slug, name, version, author, is_active, created_at, updated_at
               FROM themes
              WHERE slug = :slug',
            ['slug' => $slug],
        );
        return $row === null ? null : Theme::fromRow($row);
    }

    public function findById(int $id): ?Theme
    {
        $row = $this->connection->fetchOne(
            'SELECT id, slug, name, version, author, is_active, created_at, updated_at
               FROM themes
              WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : Theme::fromRow($row);
    }

    public function create(string $slug, string $name, string $version = '', string $author = '', bool $isActive = false): Theme
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->execute(
            'INSERT INTO themes (slug, name, version, author, is_active, created_at, updated_at)
             VALUES (:slug, :name, :version, :author, :is_active, :created_at, :updated_at)',
            [
                'slug' => $slug,
                'name' => $name,
                'version' => $version,
                'author' => $author,
                'is_active' => $isActive ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $id = (int) $this->connection->pdo()->lastInsertId();
        if ($isActive) {
            $this->activate($id);
        }
        $created = $this->findById($id);
        if ($created === null) {
            throw new SafeException('Theme could not be created.');
        }
        return $created;
    }

    public function activate(int $id): void
    {
        $theme = $this->findById($id);
        if ($theme === null) {
            throw new SafeException('Theme not found.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->transaction(function () use ($id, $now): void {
            $this->connection->execute(
                'UPDATE themes SET is_active = 0, updated_at = :updated_at',
                ['updated_at' => $now],
            );
            $this->connection->execute(
                'UPDATE themes SET is_active = 1, updated_at = :updated_at WHERE id = :id',
                ['updated_at' => $now, 'id' => $id],
            );
        });
    }

    public function delete(int $id): void
    {
        $theme = $this->findById($id);
        if ($theme === null) {
            throw new SafeException('Theme not found.');
        }
        if ($theme->isActive) {
            throw new SafeException('The active theme cannot be deleted.');
        }
        $this->connection->execute(
            'DELETE FROM themes WHERE id = :id',
            ['id' => $id],
        );
    }
}
