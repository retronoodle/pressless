<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Exception\SafeException;
use Stead\Themes\ThemeInstaller;
use Stead\Themes\ThemeRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ThemeInstallerTest extends TestCase
{
    private string $root;
    private Configuration $config;
    private Connection $connection;
    private ThemeRepository $repository;
    private ThemeInstaller $installer;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/stead-theme-install-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/log', 0775, true);
        mkdir($this->root . '/database/migrations', 0775, true);
        mkdir($this->root . '/themes/starter', 0775, true);
        foreach (glob(__DIR__ . '/../../../database/migrations/*.sqlite.sql') ?: [] as $src) {
            copy($src, $this->root . '/database/migrations/' . basename($src));
        }
        $this->config = new Configuration(
            $this->root,
            'development',
            [
                'database' => ['connection' => 'sqlite', 'database' => ':memory:'],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                    'theme' => 'themes',
                ],
                'theme' => [
                    'active' => 'starter',
                    'max_zip_bytes' => 1024 * 1024,
                ],
            ],
        );
        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();
        $this->repository = new ThemeRepository($this->connection);
        $this->installer = new ThemeInstaller($this->repository, $this->config);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->rrmdir($this->root);
    }

    public function testValidZipIsInstalledInactive(): void
    {
        $theme = $this->installer->install($this->upload($this->zipWith(
            'custom-theme',
            $this->requiredTemplates() + ['theme.json' => json_encode([
                'name' => 'Custom Theme',
                'version' => '2.1.0',
                'author' => 'Ada',
            ], JSON_THROW_ON_ERROR)],
        )));

        $this->assertSame('custom-theme', $theme->slug);
        $this->assertSame('Custom Theme', $theme->name);
        $this->assertSame('2.1.0', $theme->version);
        $this->assertSame('Ada', $theme->author);
        $this->assertFalse($theme->isActive);
        $this->assertFileExists($this->root . '/themes/custom-theme/base.twig');
        $this->assertTrue($this->repository->findActive()?->slug === 'starter');
    }

    public function testTraversalEntryIsRejected(): void
    {
        $path = $this->zipPath();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('evil/base.twig', 'base');
        $zip->addFromString('evil/home.twig', 'home');
        $zip->addFromString('evil/collection.twig', 'collection');
        $zip->addFromString('evil/entry.twig', 'entry');
        $zip->addFromString('../../outside.txt', 'nope');
        $zip->close();

        try {
            $this->installer->install($this->upload($path));
            $this->fail('Expected traversal ZIP to be rejected.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('unsafe', strtolower($e->getMessage()));
        }

        $this->assertFileDoesNotExist($this->root . '/outside.txt');
        $this->assertDirectoryDoesNotExist($this->root . '/themes/evil');
        $this->assertNull($this->repository->findBySlug('evil'));
    }

    public function testMissingRequiredTemplateIsRejected(): void
    {
        $files = $this->requiredTemplates();
        unset($files['entry.twig']);

        try {
            $this->installer->install($this->upload($this->zipWith('broken', $files)));
            $this->fail('Expected missing template to be rejected.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('entry.twig', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->root . '/themes/broken');
    }

    public function testMissingManifestFallsBackToSlugName(): void
    {
        $theme = $this->installer->install($this->upload($this->zipWith(
            'plain-theme',
            $this->requiredTemplates(),
        )));

        $this->assertSame('plain-theme', $theme->slug);
        $this->assertSame('Plain Theme', $theme->name);
        $this->assertSame('', $theme->version);
        $this->assertSame('', $theme->author);
    }

    public function testMalformedManifestFallsBackWithoutFailing(): void
    {
        $theme = $this->installer->install($this->upload($this->zipWith(
            'odd-theme',
            $this->requiredTemplates() + ['theme.json' => '{not-json'],
        )));

        $this->assertSame('Odd Theme', $theme->name);
        $this->assertSame('', $theme->version);
        $this->assertSame('', $theme->author);
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $this->installer->install($this->upload($this->zipWith('twice', $this->requiredTemplates())));

        try {
            $this->installer->install($this->upload($this->zipWith('twice', $this->requiredTemplates())));
            $this->fail('Expected duplicate slug to be rejected.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
    }

    public function testNoSingleTopLevelFolderIsRejected(): void
    {
        $path = $this->zipPath();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($this->requiredTemplates() as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        try {
            $this->installer->install($this->upload($path));
            $this->fail('Expected ZIP with no single top-level folder to be rejected.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('single theme folder', strtolower($e->getMessage()));
        }
    }

    public function testSlugCollidingWithExistingDirectoryIsRejected(): void
    {
        mkdir($this->root . '/themes/orphan', 0775, true);

        try {
            $this->installer->install($this->upload($this->zipWith('orphan', $this->requiredTemplates())));
            $this->fail('Expected slug colliding with an existing directory to be rejected.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
        $this->assertNull($this->repository->findBySlug('orphan'));
    }

    public function testOversizedZipIsRejected(): void
    {
        $tiny = new Configuration($this->root, 'development', array_replace_recursive(
            $this->config->all(),
            ['theme' => ['max_zip_bytes' => 20]],
        ));
        $installer = new ThemeInstaller($this->repository, $tiny);

        try {
            $installer->install($this->upload($this->zipWith('huge', $this->requiredTemplates())));
            $this->fail('Expected oversized ZIP to be rejected.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('too large', strtolower($e->getMessage()));
        }
        $this->assertDirectoryDoesNotExist($this->root . '/themes/huge');
    }

    public function testNonZipIsRejected(): void
    {
        $path = $this->root . '/not-a-zip.txt';
        file_put_contents($path, 'hello');
        $this->tempFiles[] = $path;

        try {
            $this->installer->install($this->upload($path, 'not-a-zip.txt', 'text/plain'));
            $this->fail('Expected non-ZIP to be rejected.');
        } catch (SafeException $e) {
            $this->assertStringContainsString('ZIP', $e->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    private function requiredTemplates(): array
    {
        return [
            'base.twig' => 'base',
            'home.twig' => 'home',
            'collection.twig' => 'collection',
            'entry.twig' => 'entry',
        ];
    }

    /**
     * @param array<string, string> $files
     */
    private function zipWith(string $folder, array $files): string
    {
        $path = $this->zipPath();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addEmptyDir($folder);
        foreach ($files as $name => $contents) {
            $zip->addFromString($folder . '/' . $name, $contents);
        }
        $zip->close();
        return $path;
    }

    private function zipPath(): string
    {
        $path = $this->root . '/upload-' . bin2hex(random_bytes(4)) . '.zip';
        $this->tempFiles[] = $path;
        return $path;
    }

    private function upload(string $path, string $name = 'theme.zip', string $mime = 'application/zip'): UploadedFile
    {
        return new UploadedFile($path, $name, $mime, null, true);
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
