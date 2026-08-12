<?php

declare(strict_types=1);

namespace Stead\Content;

/**
 * Immutable value object carrying one entry's metadata and its typed
 * field values (`field_key => typed value`).
 *
 * Entries are addressable through their id (persistence primary key), the
 * owning collection id, and their per-collection slug (public handle).
 * Status tracks the draft/published lifecycle; `publishedAt` is the first
 * time the entry became public and is preserved across unpublish so a
 * republished entry keeps its original publish date.
 */
final class Entry
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /**
     * @param array<string, mixed> $values map of field_key => typed value
     */
    public function __construct(
        private readonly int $id,
        private readonly int $collectionId,
        private readonly string $slug,
        private readonly array $values,
        private readonly string $status = self::STATUS_DRAFT,
        private readonly ?string $publishedAt = null,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function collectionId(): int
    {
        return $this->collectionId;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function value(string $fieldKey): mixed
    {
        return $this->values[$fieldKey] ?? null;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function publishedAt(): ?string
    {
        return $this->publishedAt;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Returns the same entry with the supplied values merged in (existing
     * keys are overwritten). Identity fields are preserved.
     *
     * @param array<string, mixed> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->id, $this->collectionId, $this->slug, $values, $this->status, $this->publishedAt);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->collectionId, $this->slug, $this->values, $this->status, $this->publishedAt);
    }

    public function withSlug(string $slug): self
    {
        return new self($this->id, $this->collectionId, $slug, $this->values, $this->status, $this->publishedAt);
    }

    public function withStatus(string $status): self
    {
        return new self($this->id, $this->collectionId, $this->slug, $this->values, $status, $this->publishedAt);
    }

    public function withPublishedAt(?string $publishedAt): self
    {
        return new self($this->id, $this->collectionId, $this->slug, $this->values, $this->status, $publishedAt);
    }
}