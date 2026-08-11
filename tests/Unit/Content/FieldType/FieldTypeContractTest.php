<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Content\FieldType;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stead\Content\FieldType\BooleanFieldType;
use Stead\Content\FieldType\DateFieldType;
use Stead\Content\FieldType\FieldType;
use Stead\Content\FieldType\MediaFieldType;
use Stead\Content\FieldType\NumberFieldType;
use Stead\Content\FieldType\RelationFieldType;
use Stead\Content\FieldType\RichtextFieldType;
use Stead\Content\FieldType\SelectFieldType;
use Stead\Content\FieldType\TextFieldType;

/**
 * Runs the same four scenarios (validation, normalization, persistence
 * round-trip, form rendering) against every built-in field type so per-type
 * coverage stays small.
 */
final class FieldTypeContractTest extends TestCase
{
    public function testKeyAndLabelAreNonEmpty(): void
    {
        foreach ($this->buildAllTypes() as $type) {
            $this->assertNotSame('', $type->key(), $type::class . ' must declare a non-empty short key.');
            $this->assertNotSame('', $type->label(), $type::class . ' must declare a non-empty human label.');
        }
    }

    public function testKeysAreUniqueAcrossBuiltins(): void
    {
        $keys = [];
        foreach ($this->buildAllTypes() as $type) {
            $this->assertArrayNotHasKey($type->key(), $keys, 'Duplicate short key: ' . $type->key());
            $keys[$type->key()] = $type::class;
        }
    }

    /**
     * @return array<string, array{FieldType, array<string, mixed>, mixed, mixed|null, list<string>}>
     *         0: type, 1: field definition, 2: valid value, 3: invalid value (null means "type has no invalid case by design"), 4: required-empty errors
     */
    public static function contractCases(): array
    {
        $textField = ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true];
        $richtextField = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body', 'required' => false];
        $numberField = ['key' => 'count', 'type' => 'number', 'label' => 'Count', 'required' => true, 'min' => 0, 'max' => 100];
        $booleanField = ['key' => 'featured', 'type' => 'boolean', 'label' => 'Featured'];
        $dateField = ['key' => 'published_at', 'type' => 'date', 'label' => 'Published at', 'required' => true];
        $selectField = ['key' => 'status', 'type' => 'select', 'label' => 'Status', 'required' => true, 'options' => ['draft', 'published']];
        $mediaField = ['key' => 'hero', 'type' => 'media', 'label' => 'Hero image'];
        $relationField = ['key' => 'author', 'type' => 'relation', 'label' => 'Author'];

        return [
            'text' => [
                new TextFieldType(),
                $textField,
                'Hello',
                str_repeat('x', 300),
                ['This field is required.'],
            ],
            'richtext' => [
                new RichtextFieldType(),
                $richtextField,
                "Multi\nline\nbody",
                null,
                [],
            ],
            'number' => [
                new NumberFieldType(),
                $numberField,
                42.5,
                'not-a-number',
                ['This field is required.'],
            ],
            'boolean' => [
                new BooleanFieldType(),
                $booleanField,
                true,
                ['not-a-bool'],
                [],
            ],
            'date' => [
                new DateFieldType(),
                $dateField,
                '2025-01-15',
                'not-a-date',
                ['This field is required.'],
            ],
            'select' => [
                new SelectFieldType(),
                $selectField,
                'draft',
                'not-in-list',
                ['This field is required.'],
            ],
            'media' => [
                new MediaFieldType(),
                $mediaField,
                ['id' => 0],
                null,
                [],
            ],
            'relation' => [
                new RelationFieldType(),
                $relationField,
                ['target_collection' => null, 'target_id' => 0],
                null,
                [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $requiredEmptyErrors
     */
    #[DataProvider('contractCases')]
    public function testValidateAcceptsValidValueAndRejectsInvalidValue(
        FieldType $type,
        array $field,
        mixed $validValue,
        mixed $invalidValue,
        array $requiredEmptyErrors,
    ): void {
        $this->assertSame([], $type->validate($validValue, $field), $type::class . ' must accept the canonical valid value.');
        if ($invalidValue !== null && $invalidValue !== []) {
            $this->assertNotSame([], $type->validate($invalidValue, $field), $type::class . ' must reject the canonical invalid value.');
        }
        $required = $field['required'] ?? false;
        if ($required) {
            $errors = $type->validate('', $field);
            foreach ($requiredEmptyErrors as $needle) {
                $this->assertContains($needle, $errors, $type::class . ' must report a required error for empty input.');
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $requiredEmptyErrors
     */
    #[DataProvider('contractCases')]
    public function testNormalizeMergesSchemaDefaultsWithProvidedValues(
        FieldType $type,
        array $field,
        mixed $validValue,
        mixed $invalidValue,
        array $requiredEmptyErrors,
    ): void {
        $normalized = $type->normalize($field);

        foreach (['key', 'type', 'label'] as $preserve) {
            $this->assertArrayHasKey($preserve, $normalized, $type::class . ' must preserve ' . $preserve . ' through normalize.');
        }

        foreach ($type->schemaDefaults() as $defaultKey => $defaultValue) {
            if (!array_key_exists($defaultKey, $field)) {
                $this->assertArrayHasKey($defaultKey, $normalized, $type::class . ' must surface default key ' . $defaultKey);
                $this->assertSame($defaultValue, $normalized[$defaultKey]);
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $requiredEmptyErrors
     */
    #[DataProvider('contractCases')]
    public function testPersistenceRoundTrip(
        FieldType $type,
        array $field,
        mixed $validValue,
        mixed $invalidValue,
        array $requiredEmptyErrors,
    ): void {
        $bound = $type->bindForWrite(99, (string) $field['key'], $validValue);

        foreach ($type->databaseColumns() as $column) {
            $this->assertArrayHasKey($column, $bound, $type::class . ' must populate ' . $column . ' on bindForWrite.');
        }

        $row = array_merge([
            'entry_id' => 99,
            'field_key' => (string) $field['key'],
        ], $bound);

        $this->assertSame($validValue, $type->bindForRead($row), $type::class . ' round-trip must return the same value.');
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $requiredEmptyErrors
     */
    #[DataProvider('contractCases')]
    public function testRenderFormContainsTheFieldKeyAndLabel(
        FieldType $type,
        array $field,
        mixed $validValue,
        mixed $invalidValue,
        array $requiredEmptyErrors,
    ): void {
        $html = $type->renderForm($field, $validValue, []);

        $this->assertStringContainsString((string) $field['key'], $html, $type::class . ' must reference the field key in the rendered HTML.');
        $this->assertStringContainsString((string) $field['label'], $html, $type::class . ' must render the field label.');
        $this->assertStringContainsString('<', $html, $type::class . ' must emit at least one HTML tag.');
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string>         $requiredEmptyErrors
     */
    #[DataProvider('contractCases')]
    public function testRenderFormEscapesDynamicValues(
        FieldType $type,
        array $field,
        mixed $validValue,
        mixed $invalidValue,
        array $requiredEmptyErrors,
    ): void {
        $field = array_merge($field, ['key' => 'evil"><script>', 'label' => 'Evil <img>']);
        $unsafeValue = is_string($validValue) ? '<script>alert(1)</script>' : $validValue;

        $html = $type->renderForm($field, $unsafeValue, ['a <error>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $type::class . ' must not leak raw <script> tags.');
        $this->assertStringContainsString('&lt;script&gt;', $html, $type::class . ' must htmlspecialchars user-supplied values.');
        $this->assertStringContainsString('a &lt;error&gt;', $html, $type::class . ' must htmlspecialchars error messages.');
    }

    /**
     * @return list<FieldType>
     */
    private function buildAllTypes(): array
    {
        return [
            new TextFieldType(),
            new RichtextFieldType(),
            new NumberFieldType(),
            new BooleanFieldType(),
            new DateFieldType(),
            new SelectFieldType(),
            new MediaFieldType(),
            new RelationFieldType(),
        ];
    }
}
