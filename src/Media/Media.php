<?php

declare(strict_types=1);

namespace Stead\Media;

/**
 * Immutable value object for one media row.
 *
 * Mirrors the columns on the `media` table. The `path` field is the
 * server-controlled relative path inside the storage root (e.g.
 * `5/photo.jpg`); the original filename is kept as metadata only.
 */
final class Media
{
    public function __construct(
        private readonly int $id,
        private readonly string $filename,
        private readonly string $mimeType,
        private readonly int $sizeBytes,
        private readonly string $path,
        private readonly ?int $uploadedBy,
        private readonly string $createdAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function uploadedBy(): ?int
    {
        return $this->uploadedBy;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['filename'] ?? ''),
            (string) ($row['mime_type'] ?? ''),
            (int) ($row['size_bytes'] ?? 0),
            (string) ($row['path'] ?? ''),
            isset($row['uploaded_by']) ? (int) $row['uploaded_by'] : null,
            (string) ($row['created_at'] ?? ''),
        );
    }
}
