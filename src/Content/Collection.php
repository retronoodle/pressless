<?php

declare(strict_types=1);

namespace Stead\Content;

use Stead\Exception\SafeException;

/**
 * Immutable value object carrying one collection's metadata and its decoded
 * schema (`{ fields: [...] }`).
 *
 * Collections are addressable through their id (persistence primary key) and
 * their slug (public handle); both are immutable for the lifetime of the
 * instance.
 */
final class Collection
{
    /**
     * @param array{fields: list<array<string, mixed>>} $schema
     */
    public function __construct(
        private readonly int $id,
        private readonly string $slug,
        private readonly string $name,
        private readonly array $schema,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return $this->schema['fields'] ?? [];
    }

    /**
     * Returns the same collection with the schema replaced, preserving id,
     * slug, and name. Use this when editing an existing collection.
     *
     * @param array{fields: list<array<string, mixed>>} $schema
     */
    public function withSchema(array $schema): self
    {
        return new self($this->id, $this->slug, $this->name, $schema);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->slug, $this->name, $this->schema);
    }

    public function withName(string $name): self
    {
        return new self($this->id, $this->slug, $name, $this->schema);
    }

    public function withSlug(string $slug): self
    {
        return new self($this->id, $slug, $this->name, $this->schema);
    }

    /**
     * Encodes the schema as JSON for storage. Throws a safe error if the
     * schema is not encodable; callers are expected to have validated the
     * shape before reaching this point.
     */
    public function encodeSchema(): string
    {
        $json = json_encode($this->schema, JSON_THROW_ON_ERROR);
        if (!is_string($json)) {
            throw new SafeException('Could not encode collection schema as JSON.');
        }
        return $json;
    }

    /**
     * Builds a {@see Collection} from a database row, including the decoded
     * `schema_definition` JSON column.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $id = (int) ($row['id'] ?? 0);
        $slug = (string) ($row['slug'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $raw = $row['schema_definition'] ?? '{}';

        if (!is_string($raw)) {
            $raw = '{}';
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = [];
        }

        if (!is_array($decoded) || !isset($decoded['fields']) || !is_array($decoded['fields'])) {
            $decoded = ['fields' => []];
        }

        /** @var array{fields: list<array<string, mixed>>} $schema */
        $schema = ['fields' => array_values($decoded['fields'])];

        return new self($id, $slug, $name, $schema);
    }
}
