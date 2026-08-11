<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Content\FieldType;

use PHPUnit\Framework\TestCase;
use Pressless\Content\FieldType\FieldType;
use Pressless\Content\FieldType\FieldTypeRegistry;
use Pressless\Content\FieldType\UnknownFieldTypeException;
use Pressless\Exception\SafeException;
use Pressless\Tests\Fixtures\Content\RecordingFieldType;

final class FieldTypeRegistryTest extends TestCase
{
    public function testHasReturnsTrueForRegisteredKeysAndFalseOtherwise(): void
    {
        $registry = new FieldTypeRegistry([new RecordingFieldType('text', 'Text', [], ['value_text'], [])]);

        $this->assertTrue($registry->has('text'));
        $this->assertFalse($registry->has('richtext'));
    }

    public function testGetReturnsTheRegisteredInstance(): void
    {
        $fixture = new RecordingFieldType('text', 'Text', [], ['value_text'], []);
        $registry = new FieldTypeRegistry([$fixture]);

        $this->assertSame($fixture, $registry->get('text'));
    }

    public function testGetRaisesTypedExceptionForUnknownKey(): void
    {
        $registry = new FieldTypeRegistry([]);

        $this->expectException(UnknownFieldTypeException::class);
        $registry->get('nope');
    }

    public function testUnknownKeyExceptionIsASafeException(): void
    {
        $registry = new FieldTypeRegistry([]);

        try {
            $registry->get('nope');
            $this->fail('Expected UnknownFieldTypeException');
        } catch (UnknownFieldTypeException $e) {
            $this->assertInstanceOf(SafeException::class, $e);
            $this->assertSame('nope', $e->context()['key'] ?? null);
        }
    }

    public function testAllReturnsEveryRegisteredType(): void
    {
        $a = new RecordingFieldType('text', 'Text', [], ['value_text'], []);
        $b = new RecordingFieldType('number', 'Number', [], ['value_number'], []);
        $registry = new FieldTypeRegistry([$a, $b]);

        $this->assertSame([$a, $b], $registry->all());
    }

    public function testKeysListsShortNamesInRegistrationOrder(): void
    {
        $registry = new FieldTypeRegistry([
            new RecordingFieldType('text', 'Text', [], ['value_text'], []),
            new RecordingFieldType('date', 'Date', [], ['value_date'], []),
        ]);

        $this->assertSame(['text', 'date'], $registry->keys());
    }

    public function testDuplicateKeyThrowsSafeException(): void
    {
        $this->expectException(SafeException::class);
        new FieldTypeRegistry([
            new RecordingFieldType('text', 'Text', [], ['value_text'], []),
            new RecordingFieldType('text', 'Text 2', [], ['value_text'], []),
        ]);
    }

    public function testRegistrySurfacesFullInterfaceSurface(): void
    {
        $fixture = new RecordingFieldType(
            'demo',
            'Demo',
            ['required' => false, 'default' => null],
            ['value_text'],
            ['value_text' => 'wrote'],
            'read-back',
            '<input type="text">',
        );
        $registry = new FieldTypeRegistry([$fixture]);
        /** @var FieldType $resolved */
        $resolved = $registry->get('demo');

        $this->assertSame('demo', $resolved->key());
        $this->assertSame('Demo', $resolved->label());
        $this->assertSame(['required' => false, 'default' => null], $resolved->schemaDefaults());
        $this->assertSame(['required' => false, 'default' => null, 'extra' => 1], $resolved->normalize(['extra' => 1]));
        $this->assertSame([], $resolved->validate('x', []));
        $this->assertSame(['value_text'], $resolved->databaseColumns());
        $this->assertSame(['value_text' => 'wrote'], $resolved->bindForWrite(7, 'demo', 'x'));
        $this->assertSame('read-back', $resolved->bindForRead(['value_text' => 'whatever']));
        $this->assertSame('<input type="text">', $resolved->renderForm([], null, []));
    }
}
