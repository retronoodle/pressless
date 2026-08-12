<?php

declare(strict_types=1);

namespace Stead\Content;

/**
 * Immutable value object carrying one stored URL redirect.
 *
 * Paths are stored as full literal paths (e.g. `/{collectionSlug}/{entrySlug}`)
 * so the public route's path lookup can match them exactly without pattern
 * handling.
 */
final class Redirect
{
    public function __construct(
        private readonly int $id,
        private readonly string $oldPath,
        private readonly string $newPath,
        private readonly ?string $createdAt = null,
        private readonly ?string $updatedAt = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function oldPath(): string
    {
        return $this->oldPath;
    }

    public function newPath(): string
    {
        return $this->newPath;
    }

    public function createdAt(): ?string
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['old_path'] ?? ''),
            (string) ($row['new_path'] ?? ''),
            isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
            isset($row['updated_at']) && $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        );
    }
}