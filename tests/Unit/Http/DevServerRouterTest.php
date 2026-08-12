<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stead\Http\DevServerRouter;

final class DevServerRouterTest extends TestCase
{
    private function publicDir(): string
    {
        return dirname(__DIR__, 3) . '/public';
    }

    public function testServesAnExistingAsset(): void
    {
        $this->assertTrue(
            DevServerRouter::shouldServeStatic($this->publicDir(), '/assets/css/admin.css'),
        );
    }

    public function testServesTheAdminKeyboardShortcutsScript(): void
    {
        $this->assertTrue(
            DevServerRouter::shouldServeStatic(
                $this->publicDir(),
                '/assets/js/admin/keyboard-shortcuts.js',
            ),
        );
    }

    public function testServesAnExistingAssetWithQueryString(): void
    {
        $this->assertTrue(
            DevServerRouter::shouldServeStatic($this->publicDir(), '/assets/css/admin.css?v=2'),
        );
    }

    public function testForwardsDynamicPathToFrontController(): void
    {
        $this->assertFalse(DevServerRouter::shouldServeStatic($this->publicDir(), '/admin/login'));
        $this->assertFalse(DevServerRouter::shouldServeStatic($this->publicDir(), '/'));
        $this->assertFalse(DevServerRouter::shouldServeStatic($this->publicDir(), '/assets/css/missing.css'));
    }

    public function testFrontControllerItselfIsNotServedAsAStaticFile(): void
    {
        $this->assertFalse(DevServerRouter::shouldServeStatic($this->publicDir(), '/index.php'));
        $this->assertFalse(DevServerRouter::shouldServeStatic($this->publicDir(), '/router.php'));
    }

    /**
     * Source, configuration, migration, and seed files live outside the document
     * root and must not be reachable, including through traversal or encoding.
     *
     * @return list<array{string}>
     */
    public static function protectedPaths(): array
    {
        return [
            ['/../composer.json'],
            ['/../src/Bootstrap/Application.php'],
            ['/../.env'],
            ['/../.env.example'],
            ['/../config/app.yaml'],
            ['/../database/migrations/20260811000001_initial_schema.mysql.sql'],
            ['/../phpunit.xml'],
            ['/%2e%2e/composer.json'],
            ['/..%2fcomposer.json'],
            ['/assets/../../composer.json'],
        ];
    }

    #[DataProvider('protectedPaths')]
    public function testDoesNotServeFilesOutsideTheDocumentRoot(string $path): void
    {
        $this->assertFalse(
            DevServerRouter::shouldServeStatic($this->publicDir(), $path),
            sprintf('Path "%s" must not be served directly.', $path),
        );
    }

    public function testExtractsPathFromRequestUri(): void
    {
        $this->assertSame('/admin/login', DevServerRouter::pathFromRequest('/admin/login?next=/admin'));
        $this->assertSame('/a b.css', DevServerRouter::pathFromRequest('/a%20b.css'));
        $this->assertSame('', DevServerRouter::pathFromRequest(''));
    }
}
