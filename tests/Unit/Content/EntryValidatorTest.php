<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Pressless\Content\Collection;
use Pressless\Content\EntryValidator;
use Pressless\Content\FieldType\BooleanFieldType;
use Pressless\Content\FieldType\DateFieldType;
use Pressless\Content\FieldType\FieldType;
use Pressless\Content\FieldType\FieldTypeRegistry;
use Pressless\Content\FieldType\MediaFieldType;
use Pressless\Content\FieldType\NumberFieldType;
use Pressless\Content\FieldType\RelationFieldType;
use Pressless\Content\FieldType\RichtextFieldType;
use Pressless\Content\FieldType\SelectFieldType;
use Pressless\Content\FieldType\TextFieldType;
use Pressless\Content\ValidationResult;

/**
 * Covers EntryValidator's dispatch through the field-type registry and the
 * ValidationResult shape that controllers and templates branch on.
 */
final class EntryValidatorTest extends TestCase
{
    private FieldTypeRegistry $registry;
    private EntryValidator $validator;

    protected function setUp(): void
    {
        $this->registry = new FieldTypeRegistry([
            new TextFieldType(),
            new RichtextFieldType(),
            new NumberFieldType(),
            new BooleanFieldType(),
            new DateFieldType(),
            new SelectFieldType(),
            new MediaFieldType(),
            new RelationFieldType(),
        ]);
        $this->validator = new EntryValidator($this->registry);
    }

    public function testAcceptsAValidPayload(): void
    {
        $collection = $this->sampleCollection();
        $result = $this->validator->validate($collection, [
            'title' => 'Hello',
            'body' => 'Body text.',
            'rating' => 4,
            'published_at' => '2025-01-15',
            'status' => 'published',
        ]);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->hasErrors(), 'valid payload must produce an empty result.');
        $this->assertSame([], $result->errors());
    }

    public function testRejectsRequiredEmptyText(): void
    {
        $collection = $this->sampleCollection();
        $result = $this->validator->validate($collection, [
            'title' => '',
            'body' => 'Body text.',
            'rating' => 4,
            'published_at' => '2025-01-15',
            'status' => 'published',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertContains('This field is required.', $result->errorsFor('title'));
    }

    public function testAccumulatesErrorsAcrossFields(): void
    {
        $collection = $this->sampleCollection();
        $result = $this->validator->validate($collection, [
            'title' => '',
            'body' => 'Body.',
            'rating' => 'not-a-number',
            'published_at' => 'tomorrow',
            'status' => 'unknown',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertNotSame([], $result->errorsFor('title'));
        $this->assertNotSame([], $result->errorsFor('rating'));
        $this->assertNotSame([], $result->errorsFor('published_at'));
        $this->assertNotSame([], $result->errorsFor('status'));
    }

    public function testOptionalFieldsPassWhenEmpty(): void
    {
        $collection = $this->sampleCollection();
        $result = $this->validator->validate($collection, [
            'title' => 'Hi',
            'body' => '',
            'rating' => '',
            'published_at' => '',
            'status' => '',
        ]);

        $this->assertFalse($result->hasErrors());
    }

    public function testSelectRejectsValueOutsideConfiguredOptions(): void
    {
        $collection = $this->sampleCollection();
        $result = $this->validator->validate($collection, [
            'title' => 'Hi',
            'body' => 'Body.',
            'rating' => 3,
            'published_at' => '2025-01-01',
            'status' => 'archive',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertNotSame([], $result->errorsFor('status'));
    }

    public function testNumberOutOfRangeIsRejected(): void
    {
        $collection = $this->sampleCollection();
        $result = $this->validator->validate($collection, [
            'title' => 'Hi',
            'body' => 'Body.',
            'rating' => 999,
            'published_at' => '2025-01-01',
            'status' => 'draft',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertNotSame([], $result->errorsFor('rating'));
    }

    public function testDateInWrongFormatIsRejected(): void
    {
        $collection = $this->sampleCollection();
        $result = $this->validator->validate($collection, [
            'title' => 'Hi',
            'body' => 'Body.',
            'rating' => 3,
            'published_at' => '01-15-2025',
            'status' => 'draft',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertNotSame([], $result->errorsFor('published_at'));
    }

    public function testNoErrorsFastPathReturnsSameOkInstance(): void
    {
        $collection = $this->sampleCollection();
        $payload = [
            'title' => 'Hi',
            'body' => 'Body.',
            'rating' => 3,
            'published_at' => '2025-01-01',
            'status' => 'draft',
        ];

        $first = $this->validator->validate($collection, $payload);
        $second = $this->validator->validate($collection, $payload);

        $this->assertFalse($first->hasErrors());
        $this->assertFalse($second->hasErrors());
    }

    private function sampleCollection(): Collection
    {
        return new Collection(1, 'posts', 'Posts', [
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'],
                ['key' => 'rating', 'type' => 'number', 'label' => 'Rating', 'min' => 0, 'max' => 5],
                ['key' => 'published_at', 'type' => 'date', 'label' => 'Published at'],
                ['key' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => ['draft', 'published']],
            ],
        ]);
    }
}
