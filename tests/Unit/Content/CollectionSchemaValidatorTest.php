<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Pressless\Content\CollectionSchemaValidator;
use Pressless\Content\FieldType\FieldType;
use Pressless\Content\FieldType\FieldTypeRegistry;
use Pressless\Content\SchemaValidationException;
use Pressless\Tests\Fixtures\Content\RecordingFieldType;

/**
 * Covers CollectionSchemaValidator's structural rules: required fields,
 * malformed keys, duplicate keys, unknown types, and the no-op fast path.
 */
final class CollectionSchemaValidatorTest extends TestCase
{
    private FieldTypeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new FieldTypeRegistry([
            new RecordingFieldType('text', 'Text', ['required' => false, 'default' => null], ['value_text'], []),
            new RecordingFieldType(
                'select',
                'Select',
                ['required' => false, 'default' => null, 'options' => []],
                ['value_text'],
                []
            ),
        ]);
    }

    public function testAcceptsAValidSchema(): void
    {
        $validator = new CollectionSchemaValidator($this->registry);

        $validator->validateSchema([
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                ['key' => 'body', 'type' => 'text', 'label' => 'Body'],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function testRejectsAnEmptyFieldSet(): void
    {
        $validator = new CollectionSchemaValidator($this->registry);

        try {
            $validator->validateSchema(['fields' => []]);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('__schema', $e->errors());
        }
    }

    public function testRejectsMissingKey(): void
    {
        $validator = new CollectionSchemaValidator($this->registry);

        try {
            $validator->validateFields([
                ['type' => 'text'],
            ]);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('__field_0', $e->errors());
        }
    }

    public function testRejectsMalformedKey(): void
    {
        $validator = new CollectionSchemaValidator($this->registry);

        try {
            $validator->validateFields([
                ['key' => '1bad', 'type' => 'text'],
                ['key' => 'Title', 'type' => 'text'],
                ['key' => 'has-dash', 'type' => 'text'],
            ]);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('1bad', $errors);
            $this->assertArrayHasKey('Title', $errors);
            $this->assertArrayHasKey('has-dash', $errors);
        }
    }

    public function testRejectsDuplicateKeys(): void
    {
        $validator = new CollectionSchemaValidator($this->registry);

        try {
            $validator->validateFields([
                ['key' => 'title', 'type' => 'text'],
                ['key' => 'title', 'type' => 'text'],
            ]);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
            $this->assertContains('Duplicate field key.', $e->errors()['title']);
        }
    }

    public function testRejectsUnknownType(): void
    {
        $validator = new CollectionSchemaValidator($this->registry);

        try {
            $validator->validateFields([
                ['key' => 'mood', 'type' => 'emoji'],
            ]);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertArrayHasKey('mood', $e->errors());
            $this->assertContains('Field type "emoji" is not registered.', $e->errors()['mood']);
        }
    }

    public function testRejectsMissingType(): void
    {
        $validator = new CollectionSchemaValidator($this->registry);

        try {
            $validator->validateFields([
                ['key' => 'mood'],
            ]);
            $this->fail('Expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertContains('Field type is required.', $e->errors()['mood'] ?? []);
        }
    }

    public function testNormalizeSchemaDelegatesToEachFieldType(): void
    {
        $registry = new FieldTypeRegistry([
            new RecordingFieldType(
                'text',
                'Text',
                ['required' => false, 'default' => null, 'max_length' => 255],
                ['value_text'],
                []
            ),
        ]);
        $validator = new CollectionSchemaValidator($registry);

        $normalized = $validator->normalizeSchema([
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'extra' => 'passthrough'],
            ],
        ]);

        $this->assertSame('text', $normalized['fields'][0]['type']);
        $this->assertTrue($validator === $validator);
    }
}
