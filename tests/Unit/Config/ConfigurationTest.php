<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Stead\Config\Configuration;
use Stead\Config\Dotenv;
use Stead\Config\Validator;
use Stead\Exception\SafeException;

final class ConfigurationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/stead-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
        mkdir($this->tmpDir . '/var/cache', 0775, true);
        mkdir($this->tmpDir . '/var/log', 0775, true);
        mkdir($this->tmpDir . '/config', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeYaml(string $yaml): void
    {
        file_put_contents($this->tmpDir . '/config/app.yaml', $yaml);
    }

    private function writeEnv(string $env): void
    {
        file_put_contents($this->tmpDir . '/.env', $env);
    }

    public function testLoadsYamlDefaults(): void
    {
        $this->writeYaml("defaults:\n  database:\n    host: 127.0.0.1\n    port: 3306\n");
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        $this->assertSame('127.0.0.1', $config->getString('database.host'));
        $this->assertSame(3306, $config->getInt('database.port'));
    }

    public function testEnvironmentSectionOverridesDefaults(): void
    {
        $this->writeYaml("defaults:\n  database:\n    host: 127.0.0.1\ndevelopment:\n  database:\n    host: localhost\n");
        $config = Configuration::fromProjectRoot($this->tmpDir, 'development');
        $this->assertSame('localhost', $config->getString('database.host'));
    }

    public function testDotenvValuesApply(): void
    {
        $this->writeYaml("defaults:\n  database:\n    host: 127.0.0.1\n");
        $this->writeEnv("DB_HOST=db.example\nDB_PORT=3307\n");
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        $this->assertSame('db.example', $config->getString('database.host'));
        $this->assertSame(3307, $config->getInt('database.port'));
    }

    public function testExportedEnvWinsOverDotenv(): void
    {
        $this->writeYaml("defaults:\n  database:\n    host: 127.0.0.1\n");
        $this->writeEnv("DB_HOST=from_dotenv\n");
        putenv('DB_HOST=from_real_env');
        try {
            $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
            $this->assertSame('from_real_env', $config->getString('database.host'));
        } finally {
            putenv('DB_HOST');
        }
    }

    public function testPathResolutionIsProjectRelative(): void
    {
        $this->writeYaml(
            "defaults:\n" .
            "  paths:\n" .
            "    cache: var/cache\n" .
            "    log: var/log\n"
        );
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        $this->assertSame(realpath($this->tmpDir . '/var/cache'), $config->path('paths.cache'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->writeYaml("defaults: {}\n");
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        $this->assertSame('fallback', $config->get('missing.key', 'fallback'));
        $this->assertSame(42, $config->getInt('missing.int', 42));
        $this->assertTrue($config->getBool('missing.bool', true));
    }

    public function testValidatorRejectsUnknownDriver(): void
    {
        $this->writeYaml("defaults:\n  database:\n    connection: postgres\n    host: x\n    database: x\n    username: x\n");
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        $this->expectException(SafeException::class);
        $this->expectExceptionMessage('Unsupported database driver');
        Validator::validate($config);
    }

    public function testValidatorAllowsSqlite(): void
    {
        $this->writeYaml(
            "defaults:\n" .
            "  database:\n" .
            "    connection: sqlite\n" .
            "    database: ':memory:'\n" .
            "  paths:\n" .
            "    cache: var/cache\n" .
            "    log: var/log\n" .
            "  sessions:\n" .
            "    name: stead_session\n"
        );
        $config = Configuration::fromProjectRoot($this->tmpDir, 'development');
        Validator::validate($config);
        $this->assertSame('sqlite', $config->getString('database.connection'));
    }

    public function testValidatorReportsMissingSettingNameWithoutValue(): void
    {
        $this->writeYaml("defaults:\n  database:\n    connection: mysql\n    host: ''\n    database: ''\n    username: ''\n");
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        try {
            Validator::validate($config);
            $this->fail('Expected SafeException');
        } catch (SafeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('database.host', $message);
            $this->assertStringNotContainsString('password', strtolower($message));
            $context = $e->context();
            $this->assertSame('database.host', $context['setting']);
        }
    }

    public function testValidatorReportsMissingPasswordWithoutRevealingValue(): void
    {
        $this->writeYaml(
            "defaults:\n" .
            "  database:\n" .
            "    connection: mysql\n" .
            "    host: localhost\n" .
            "    database: stead\n" .
            "    username: ''\n" .
            "  sessions:\n" .
            "    name: stead_session\n"
        );
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        try {
            Validator::validate($config);
            $this->fail('Expected SafeException');
        } catch (SafeException $e) {
            $message = strtolower($e->getMessage());
            $this->assertStringContainsString('database.username', $message);
            $this->assertStringNotContainsString('supersecret', $message);
        }
    }

    public function testValidatorRejectsInvalidSameSite(): void
    {
        $this->writeYaml(
            "defaults:\n" .
            "  database:\n" .
            "    connection: sqlite\n" .
            "    database: ':memory:'\n" .
            "  paths:\n" .
            "    cache: var/cache\n" .
            "    log: var/log\n" .
            "  sessions:\n" .
            "    name: sess\n" .
            "    cookie_samesite: Bogus\n"
        );
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        $this->expectException(SafeException::class);
        $this->expectExceptionMessage('SameSite');
        Validator::validate($config);
    }

    public function testValidatorRequiresPositiveSessionLifetime(): void
    {
        $this->writeYaml(
            "defaults:\n" .
            "  database:\n" .
            "    connection: sqlite\n" .
            "    database: ':memory:'\n" .
            "  paths:\n" .
            "    cache: var/cache\n" .
            "    log: var/log\n" .
            "  sessions:\n" .
            "    name: sess\n" .
            "    lifetime: 0\n"
        );
        $config = Configuration::fromProjectRoot($this->tmpDir, 'production');
        $this->expectException(SafeException::class);
        $this->expectExceptionMessage('Session lifetime');
        Validator::validate($config);
    }
}
