<?php

declare(strict_types=1);

namespace Stead\Tests\Fixtures\Content;

use Stead\Content\FieldType\FieldType;

/**
 * A self-contained {@see FieldType} implementation used by the registry
 * contract tests and the parameterized field-type scenarios.
 *
 * The fixture exposes the same surface a real implementation must cover; the
 * tests assert that the registry passes arguments through unchanged and that
 * every method is reachable.
 */
final class RecordingFieldType implements FieldType
{
    /**
     * @param array<string, mixed> $schemaDefaults
     * @param list<string>         $databaseColumns
     * @param array<string, mixed> $bindForWriteReturn
     */
    public function __construct(
        private readonly string $typeKey,
        private readonly string $typeLabel,
        private readonly array $schemaDefaults,
        private readonly array $databaseColumns,
        private readonly array $bindForWriteReturn,
        private readonly mixed $bindForReadReturn = null,
        private readonly string $renderFormHtml = '<input>',
    ) {
    }

    public function key(): string
    {
        return $this->typeKey;
    }

    public function label(): string
    {
        return $this->typeLabel;
    }

    public function schemaDefaults(): array
    {
        return $this->schemaDefaults;
    }

    public function normalize(array $field): array
    {
        return array_merge($this->schemaDefaults, $field);
    }

    public function validate(mixed $value, array $field): array
    {
        return [];
    }

    public function databaseColumns(): array
    {
        return $this->databaseColumns;
    }

    public function bindForWrite(int $entryId, string $fieldKey, mixed $value): array
    {
        return $this->bindForWriteReturn;
    }

    public function bindForRead(array $row): mixed
    {
        return $this->bindForReadReturn;
    }

    public function renderForm(array $field, mixed $value, array $errors): string
    {
        return $this->renderFormHtml;
    }
}
