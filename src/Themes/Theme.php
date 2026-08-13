<?php

declare(strict_types=1);

namespace Stead\Themes;

/**
 * An installed theme and whether it is the currently active one.
 */
final class Theme
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly bool $isActive,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['slug'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['version'] ?? ''),
            (string) ($row['author'] ?? ''),
            (int) ($row['is_active'] ?? 0) === 1,
            isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
            isset($row['updated_at']) && $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        );
    }
}
