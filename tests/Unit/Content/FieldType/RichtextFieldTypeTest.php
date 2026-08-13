<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Content\FieldType;

use PHPUnit\Framework\TestCase;
use Stead\Content\FieldType\RichtextFieldType;

/**
 * Covers the `richtext` field type's sanitization contract, max-length
 * measured on extracted plain text, and required-empty behaviour on HTML
 * that sanitizes to nothing.
 */
final class RichtextFieldTypeTest extends TestCase
{
    public function testSanitizationStripsDisallowedTagsAndAttributes(): void
    {
        $type = new RichtextFieldType();
        $value = '<p onclick="alert(1)">Hello <script>alert(1)</script><strong>world</strong></p>';
        $field = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'];

        $bound = $type->bindForWrite(1, 'body', $value);

        $this->assertArrayHasKey('value_text', $bound);
        $stored = (string) $bound['value_text'];
        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('<strong>world</strong>', $stored);
    }

    public function testSanitizationStripsDisallowedUrlSchemes(): void
    {
        $type = new RichtextFieldType();
        $value = '<p><a href="javascript:alert(1)">bad</a><a href="https://example.com">good</a></p>';
        $field = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'];

        $bound = $type->bindForWrite(1, 'body', $value);
        $stored = (string) $bound['value_text'];

        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringContainsString('https://example.com', $stored);
    }

    public function testRequiredValidationRejectsHtmlThatExtractsToEmptyText(): void
    {
        $type = new RichtextFieldType();
        $field = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body', 'required' => true];

        $errors = $type->validate('<p></p>', $field);
        $this->assertContains('This field is required.', $errors);

        $errors = $type->validate('<script>alert(1)</script>', $field);
        $this->assertContains('This field is required.', $errors);
    }

    public function testRequiredValidationAcceptsHtmlThatExtractsToNonEmptyText(): void
    {
        $type = new RichtextFieldType();
        $field = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body', 'required' => true];

        $this->assertSame([], $type->validate('<p>Hello</p>', $field));
    }

    public function testMaxLengthIsMeasuredOnExtractedPlainTextNotHtmlMarkup(): void
    {
        $type = new RichtextFieldType();
        $field = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body', 'max_length' => 9];

        $html = '<p><strong>' . str_repeat('x', 9) . '</strong></p>';
        $this->assertSame([], $type->validate($html, $field));

        $htmlLong = '<p>' . str_repeat('a', 20) . '</p>';
        $this->assertNotSame([], $type->validate($htmlLong, $field));
    }

    public function testOptionalFieldWithEmptyHtmlPasses(): void
    {
        $type = new RichtextFieldType();
        $field = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'];

        $this->assertSame([], $type->validate('', $field));
        $this->assertSame([], $type->validate('<p></p>', $field));
    }

    public function testBindForWriteStoresSanitizedHtmlNotRawSubmittedString(): void
    {
        $type = new RichtextFieldType();
        $value = '<p>Hi <script>alert(1)</script></p>';
        $bound = $type->bindForWrite(1, 'body', $value);

        $this->assertIsString($bound['value_text']);
        $this->assertStringNotContainsString('<script>', $bound['value_text']);
        $this->assertStringContainsString('<p>Hi', $bound['value_text']);
    }

    public function testBindForWriteStoresNullForEmptyAfterSanitization(): void
    {
        $type = new RichtextFieldType();
        $bound = $type->bindForWrite(1, 'body', '<script>alert(1)</script>');
        $this->assertNull($bound['value_text']);
    }

    public function testBindForWriteRoundTripsThroughReadForStoredSanitizedHtml(): void
    {
        $type = new RichtextFieldType();
        $value = '<p><strong>Hello</strong> <em>world</em></p>';
        $bound = $type->bindForWrite(1, 'body', $value);
        $read = $type->bindForRead($bound);
        $this->assertSame($bound['value_text'], $read);
    }

    public function testRenderFormContainsTiptapContainerAndHiddenInput(): void
    {
        $type = new RichtextFieldType();
        $field = ['key' => 'body', 'type' => 'richtext', 'label' => 'Body'];
        $html = $type->renderForm($field, '<p>Stored</p>', []);

        $this->assertStringContainsString('data-stead-richtext="body"', $html);
        $this->assertStringContainsString('class="stead-richtext"', $html);
        $this->assertStringContainsString('class="stead-richtext__editor"', $html);
        $this->assertStringContainsString('name="fields[body]"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }

    public function testRenderFormEscapesMismatchedKeyAndLabel(): void
    {
        $type = new RichtextFieldType();
        $field = ['key' => 'evil"><script>', 'label' => 'Evil <img>', 'type' => 'richtext'];
        $html = $type->renderForm($field, '<p>Safe</p>', []);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img&gt;', $html);
    }
}
