<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stead\Themes\ThemeManifestReader;

final class ThemeManifestReaderTest extends TestCase
{
    private string $root;
    private ThemeManifestReader $reader;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/stead-manifest-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        $this->reader = new ThemeManifestReader();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testValidSettingsArrayIsParsed(): void
    {
        $manifest = $this->reader->parseManifestJson(json_encode([
            'name' => 'Acme',
            'version' => '1.0.0',
            'author' => 'Ada',
            'settings' => [
                ['key' => 'hero_title', 'label' => 'Hero title', 'type' => 'text', 'default' => 'Hello'],
                ['key' => 'accent', 'type' => 'color', 'default' => '#abc123'],
                ['key' => 'layout', 'type' => 'select', 'options' => ['one', 'two']],
                ['key' => 'show_sidebar', 'type' => 'boolean', 'default' => '1'],
                ['key' => 'hero_image', 'type' => 'image'],
                ['key' => 'body', 'type' => 'textarea', 'default' => '<p>hi</p>'],
            ],
        ]), 'custom-theme');

        $this->assertSame('Acme', $manifest['name']);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertSame('Ada', $manifest['author']);
        $this->assertCount(6, $manifest['settings']);
        $this->assertSame('hero_title', $manifest['settings'][0]['key']);
        $this->assertSame('Hero title', $manifest['settings'][0]['label']);
        $this->assertSame('text', $manifest['settings'][0]['type']);
        $this->assertSame('Hello', $manifest['settings'][0]['default']);
        $this->assertSame([], $manifest['settings'][0]['options']);
        $this->assertSame(['one', 'two'], $manifest['settings'][2]['options']);
    }

    public function testEntryMissingKeyOrTypeIsDroppedSilently(): void
    {
        $manifest = $this->reader->parseManifestJson(json_encode([
            'name' => 'Acme',
            'settings' => [
                ['key' => '', 'type' => 'text'],
                ['type' => 'text'],
                ['key' => 'no-type'],
                ['key' => 'good', 'type' => 'text'],
            ],
        ]), 'slug');

        $this->assertCount(1, $manifest['settings']);
        $this->assertSame('good', $manifest['settings'][0]['key']);
    }

    public function testEntryWithUnsupportedTypeIsDropped(): void
    {
        $manifest = $this->reader->parseManifestJson(json_encode([
            'name' => 'Acme',
            'settings' => [
                ['key' => 'weird', 'type' => 'markdown'],
                ['key' => 'okay', 'type' => 'text'],
            ],
        ]), 'slug');

        $this->assertCount(1, $manifest['settings']);
        $this->assertSame('okay', $manifest['settings'][0]['key']);
    }

    public function testDuplicateKeysAreDeduplicated(): void
    {
        $manifest = $this->reader->parseManifestJson(json_encode([
            'name' => 'Acme',
            'settings' => [
                ['key' => 'a', 'type' => 'text'],
                ['key' => 'a', 'type' => 'boolean'],
            ],
        ]), 'slug');

        $this->assertCount(1, $manifest['settings']);
        $this->assertSame('text', $manifest['settings'][0]['type']);
    }

    public function testAbsentSettingsKeyBehavesAsEmptySchema(): void
    {
        $manifest = $this->reader->parseManifestJson(json_encode([
            'name' => 'Acme',
        ]), 'my-theme');

        $this->assertSame('Acme', $manifest['name']);
        $this->assertSame([], $manifest['settings']);
    }

    public function testMalformedJsonFallsBackToSlugName(): void
    {
        $manifest = $this->reader->parseManifestJson('{not-json', 'plain-theme');

        $this->assertSame('Plain Theme', $manifest['name']);
        $this->assertSame('', $manifest['version']);
        $this->assertSame('', $manifest['author']);
        $this->assertSame([], $manifest['settings']);
    }

    public function testNonStringNameFallsBackToSlugName(): void
    {
        $manifest = $this->reader->parseManifestJson(json_encode([
            'name' => 42,
            'version' => ['nope'],
        ]), 'fallback-theme');

        $this->assertSame('Fallback Theme', $manifest['name']);
        $this->assertSame('', $manifest['version']);
    }

    public function testReadFromDiskReturnsParsedManifest(): void
    {
        $themeDir = $this->root . '/custom-theme';
        mkdir($themeDir, 0775, true);
        file_put_contents($themeDir . '/theme.json', json_encode([
            'name' => 'Custom',
            'settings' => [
                ['key' => 'a', 'type' => 'text', 'default' => 'one'],
            ],
        ]));

        $manifest = $this->reader->readFrom($themeDir);

        $this->assertNotNull($manifest);
        $this->assertSame('Custom', $manifest['name']);
        $this->assertCount(1, $manifest['settings']);
        $this->assertSame('a', $manifest['settings'][0]['key']);
    }

    public function testReadFromDiskReturnsNullWhenManifestMissing(): void
    {
        $themeDir = $this->root . '/no-manifest';
        mkdir($themeDir, 0775, true);

        $this->assertNull($this->reader->readFrom($themeDir));
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
