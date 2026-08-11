<?php

declare(strict_types=1);

namespace Pressless\Content;

/**
 * Immutable value object carrying one entry's metadata and its typed
 * field values (`field_key => typed value`).
 *
 * Entries are addressable through their id (persistence primary key), the
 * owning collection id, and their per-collection slug (public handle).
 */
final class Entry
{
    /**
     * @param array<string, mixed> $values map of field_key => typed value
     */
    public function __construct(
        private readonly int $id,
        private readonly int $collectionId,
        private readonly string $slug,
        private readonly array $values,
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

    /**
     * Returns the same entry with the supplied values merged in (existing
     * keys are overwritten). Identity fields are preserved.
     *
     * @param array<string, mixed> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->id, $this->collectionId, $this->slug, $values);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->collectionId, $this->slug, $this->values);
    }

    public function withSlug(string $slug): self
    {
        return new self($this->id, $this->collectionId, $slug, $this->values);
    }
}
