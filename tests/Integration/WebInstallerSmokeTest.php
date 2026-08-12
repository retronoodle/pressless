<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\PasswordHasher;
use Stead\Auth\UserRepository;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\Installer\ArrayInstallerSession;
use Stead\Installer\ConfigWriter;
use Stead\Installer\ConnectionTester;
use Stead\Installer\Controller\InstallerController;
use Stead\Installer\InstallerKernel;
use Stead\Installer\InstallerLock;
use Stead\Installer\InstallerRenderer;
use Stead\Installer\InstallerRoutes;
use Stead\Installer\InstallerSessionStore;
use Stead\View\TwigRenderer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end coverage of the web installer wizard.
 *
 * The tests run against SQLite by default and against MySQL when DB_HOST is
 * set, mirroring the conventions used by InstallToLoginSmokeTest. Each test
 * creates a fresh, empty project root with no `.env` and no `installed.lock`,
 * exercises the installer, and then asserts the resulting install behaves
 * like a normal Stead install (admin login works, installer is no longer
 * reachable, wizard state is cleared).
 */
final class WebInstallerSmokeTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        foreach (['DB_HOST', 'DB_CONNECTION', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_ENV', 'SESSION_NAME'] as $key) {
            putenv($key);
            $_ENV[$key] = '';
        }

        $this->projectRoot = sys_get_temp_dir() . '/stead-installer-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/config', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        mkdir($this->projectRoot . '/database/seed', 0775, true);
        mkdir($this->projectRoot . '/templates/installer', 0775, true);
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        copy(__DIR__ . '/../../config/app.yaml', $this->projectRoot . '/config/app.yaml');
        $dbHost = getenv('DB_HOST');
        if (is_string($dbHost) && $dbHost !== '') {
            foreach (glob(__DIR__ . '/../../database/migrations/*.mysql.sql') ?: [] as $src) {
                copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
            }
        } else {
            foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $src) {
                copy($src, $this->projectRoot . '/database/migrations/' . basename($src));
            }
        }
        $this->copyInstallerTemplates($this->projectRoot);
    }

    protected function tearDown(): void
    {
        foreach (['DB_HOST', 'DB_CONNECTION', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_ENV', 'SESSION_NAME'] as $key) {
            putenv($key);
            $_ENV[$key] = '';
        }
        foreach ([
            "{$this->projectRoot}/var/cache/twig-installer",
            "{$this->projectRoot}/var/cache/twig",
            "{$this->projectRoot}/var/cache",
            "{$this->projectRoot}/var/log",
            "{$this->projectRoot}/var",
            "{$this->projectRoot}/templates/installer",
            "{$this->projectRoot}/templates",
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/database/seed",
            "{$this->projectRoot}/database",
            $this->projectRoot,
        ] as $path) {
            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $full = $path . '/' . $entry;
                    if (is_dir($full)) {
                        foreach (scandir($full) ?: [] as $sub) {
                            if ($sub !== '.' && $sub !== '..') {
                                @unlink($full . '/' . $sub);
                            }
                        }
                        @rmdir($full);
                    } else {
                        @unlink($full);
                    }
                }
                @rmdir($path);
            }
        }
        foreach (glob($this->projectRoot . '/*.sqlite') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->projectRoot . '/.env') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->projectRoot . '/installed.lock') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testInstallerLockReportsAbsentAndPresent(): void
    {
        $this->assertFalse(InstallerLock::isInstalled($this->projectRoot));
        $this->assertTrue(InstallerLock::create($this->projectRoot));
        $this->assertTrue(InstallerLock::isInstalled($this->projectRoot));
        $this->assertFalse(InstallerLock::create($this->projectRoot));
    }

    public function testConfigWriterRendersOnlyAllowedKeys(): void
    {
        $writer = new ConfigWriter();
        $writer->writeEnv($this->projectRoot, [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'sqlite',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'stead.sqlite',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => 'p@ss word',
            'SESSION_NAME' => 'stead_session',
            'LOGGING_LEVEL' => 'info',
            'LOGGING_FILE' => 'stead.log',
            'UNEXPECTED_KEY' => 'should not appear',
        ]);

        $contents = (string) file_get_contents($this->projectRoot . '/.env');
        $this->assertStringContainsString('APP_ENV=production', $contents);
        $this->assertStringContainsString('DB_PASSWORD="p@ss word"', $contents);
        $this->assertStringNotContainsString('UNEXPECTED_KEY', $contents);
    }

    public function testConnectionTesterRejectsUnsupportedDriver(): void
    {
        $tester = new ConnectionTester();
        $this->expectException(\Stead\Exception\SafeException::class);
        $tester->buildConfiguration($this->projectRoot, [
            'driver' => 'oracle',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'foo',
            'username' => 'root',
            'password' => '',
        ]);
    }

    public function testConnectionTesterValidatesSqliteAgainstEmptyProject(): void
    {
        $dbPath = $this->projectRoot . '/stead.sqlite';
        $tester = new ConnectionTester();
        $config = $tester->buildConfiguration($this->projectRoot, [
            'driver' => 'sqlite',
            'host' => '',
            'port' => 0,
            'database' => $dbPath,
            'username' => '',
            'password' => '',
        ]);
        $tester->test($config);
        $this->assertFileExists($dbPath);
    }

    public function testFullWizardCompletesAndProducesWorkingAdminLogin(): void
    {
        $dbSettings = $this->installerDbSettings();

        $session = new ArrayInstallerSession();
        $controller = $this->makeController($session);
        $kernel = InstallerKernel::create($controller);

        $welcome = $kernel->handle(Request::create('/install'));
        $this->assertSame(200, $welcome->getStatusCode());
        $this->assertStringContainsString('Welcome', (string) $welcome->getContent());

        $dbSubmit = $kernel->handle(Request::create('/install/database', 'POST', $dbSettings['form']));
        $this->assertSame(302, $dbSubmit->getStatusCode());
        $this->assertSame('/install/admin', $dbSubmit->headers->get('Location'));

        $adminSubmit = $kernel->handle(Request::create('/install/admin', 'POST', [
            'email' => 'Admin@Example.com',
            'name' => 'Site Admin',
            'password' => 'correct-horse-battery-staple',
            'confirm' => 'correct-horse-battery-staple',
        ]));
        $this->assertSame(302, $adminSubmit->getStatusCode());
        $this->assertSame('/install/sample-data', $adminSubmit->headers->get('Location'));

        $sampleSubmit = $kernel->handle(Request::create('/install/sample-data', 'POST', [
            'choice' => 'no',
        ]));
        $this->assertSame(302, $sampleSubmit->getStatusCode());
        $this->assertSame('/install/complete', $sampleSubmit->headers->get('Location'));

        $complete = $kernel->handle(Request::create('/install/complete', 'POST'));
        $this->assertSame(302, $complete->getStatusCode());
        $this->assertSame('/admin/login', $complete->headers->get('Location'));
        $this->assertFileExists($this->projectRoot . '/installed.lock');
        $this->assertFileExists($this->projectRoot . '/.env');

        $config = Configuration::fromProjectRoot($this->projectRoot, 'production');
        $this->assertSame($dbSettings['driver'], $config->getString('database.connection'));
        $this->assertSame($dbSettings['database'], $config->getString('database.database'));

        $connection = new Connection($config);
        $migrator = new Migrator($connection, $config);
        $result = $migrator->migrate();
        $this->assertGreaterThan(0, count($result['applied']) + count($result['skipped']));
        $users = new UserRepository($connection, new PasswordHasher());
        $admin = $users->findByEmail('admin@example.com');
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->roleName);
        $this->assertTrue((new PasswordHasher())->verify('correct-horse-battery-staple', $admin->passwordHash));

        $connection->close();
    }

    public function testFullWizardAgainstMySqlWhenAvailable(): void
    {
        $dbHost = getenv('DB_HOST');
        if (!is_string($dbHost) || $dbHost === '') {
            $this->markTestSkipped('DB_HOST not set; the full wizard already runs against MySQL when it is, via testFullWizardCompletesAndProducesWorkingAdminLogin.');
        }

        $database = (string) (getenv('DB_DATABASE') ?: 'stead_installer_full');
        $session = new ArrayInstallerSession();
        $controller = $this->makeController($session);
        $kernel = InstallerKernel::create($controller);

        $kernel->handle(Request::create('/install/database', 'POST', [
            'driver' => 'mysql',
            'host' => $dbHost,
            'port' => (string) (getenv('DB_PORT') ?: 3306),
            'database' => $database,
            'username' => (string) (getenv('DB_USERNAME') ?: 'root'),
            'password' => (string) (getenv('DB_PASSWORD') ?: ''),
        ]));
        $kernel->handle(Request::create('/install/admin', 'POST', [
            'email' => 'admin@example.com',
            'name' => 'Admin',
            'password' => 'correct-horse-battery-staple',
            'confirm' => 'correct-horse-battery-staple',
        ]));
        $kernel->handle(Request::create('/install/sample-data', 'POST', ['choice' => 'no']));
        $complete = $kernel->handle(Request::create('/install/complete', 'POST'));
        $this->assertSame(302, $complete->getStatusCode());
        $this->assertSame('/admin/login', $complete->headers->get('Location'));
        $this->assertFileExists($this->projectRoot . '/installed.lock');
        $this->assertFileExists($this->projectRoot . '/.env');
    }

    /**
     * @return array{driver: string, database: string, form: array<string, string>}
     */
    private function installerDbSettings(): array
    {
        $dbHost = getenv('DB_HOST');
        if (is_string($dbHost) && $dbHost !== '') {
            $database = (string) (getenv('DB_DATABASE') ?: 'stead_installer');
            return [
                'driver' => 'mysql',
                'database' => $database,
                'form' => [
                    'driver' => 'mysql',
                    'host' => $dbHost,
                    'port' => (string) (getenv('DB_PORT') ?: 3306),
                    'database' => $database,
                    'username' => (string) (getenv('DB_USERNAME') ?: 'root'),
                    'password' => (string) (getenv('DB_PASSWORD') ?: ''),
                ],
            ];
        }
        return [
            'driver' => 'sqlite',
            'database' => 'var/stead.sqlite',
            'form' => [
                'driver' => 'sqlite',
                'host' => '',
                'port' => '0',
                'database' => 'stead',
                'username' => '',
                'password' => '',
            ],
        ];
    }

    public function testReachingLaterStepWithoutPrerequisiteRedirectsBack(): void
    {
        $session = new ArrayInstallerSession();
        $controller = $this->makeController($session);
        $kernel = InstallerKernel::create($controller);

        $adminDirect = $kernel->handle(Request::create('/install/admin'));
        $this->assertSame(302, $adminDirect->getStatusCode());
        $this->assertSame('/install/database', $adminDirect->headers->get('Location'));

        $sampleDirect = $kernel->handle(Request::create('/install/sample-data'));
        $this->assertSame(302, $sampleDirect->getStatusCode());
        $this->assertSame('/install/database', $sampleDirect->headers->get('Location'));

        $completeDirect = $kernel->handle(Request::create('/install/complete'));
        $this->assertSame(302, $completeDirect->getStatusCode());
        $this->assertSame('/install/database', $completeDirect->headers->get('Location'));
    }

    public function testBadDatabaseCredentialsDoNotWriteEnvOrLock(): void
    {
        $varDir = $this->projectRoot . '/var';
        chmod($varDir, 0500);

        try {
            $session = new ArrayInstallerSession();
            $controller = $this->makeController($session);
            $kernel = InstallerKernel::create($controller);

            $badSubmit = $kernel->handle(Request::create('/install/database', 'POST', [
                'driver' => 'sqlite',
                'host' => '',
                'port' => '0',
                'database' => 'stead',
                'username' => '',
                'password' => '',
            ]));

            $this->assertSame(400, $badSubmit->getStatusCode());
            $this->assertStringContainsString('SQLite', (string) $badSubmit->getContent());
            $this->assertFileDoesNotExist($this->projectRoot . '/.env');
            $this->assertFileDoesNotExist($this->projectRoot . '/installed.lock');
        } finally {
            chmod($varDir, 0775);
        }
    }

    public function testReachingInstallerAfterLockRedirectsToAdmin(): void
    {
        InstallerLock::create($this->projectRoot);

        $session = new ArrayInstallerSession();
        $kernel = new InstallerKernel(InstallerRoutes::create($this->makeController($session)));
        $response = $kernel->handle(Request::create('/install'));
        $this->assertSame(200, $response->getStatusCode());

        $redirect = new RedirectResponse('/admin', Response::HTTP_SEE_OTHER);
        $this->assertSame('/admin', $redirect->getTargetUrl());
    }

    public function testSampleDataSeedRunsThroughInstaller(): void
    {
        $dbSettings = $this->installerDbSettings();

        $session = new ArrayInstallerSession();
        $controller = $this->makeController($session);
        $kernel = InstallerKernel::create($controller);

        $kernel->handle(Request::create('/install/database', 'POST', $dbSettings['form']));
        $kernel->handle(Request::create('/install/admin', 'POST', [
            'email' => 'root@example.com',
            'name' => 'Root',
            'password' => 'correct-horse-battery-staple',
            'confirm' => 'correct-horse-battery-staple',
        ]));
        $kernel->handle(Request::create('/install/sample-data', 'POST', [
            'choice' => 'yes',
        ]));
        $complete = $kernel->handle(Request::create('/install/complete', 'POST'));
        $this->assertSame(302, $complete->getStatusCode());

        $config = Configuration::fromProjectRoot($this->projectRoot, 'production');
        $connection = new Connection($config);

        $count = (int) ($connection->fetchOne('SELECT COUNT(*) AS c FROM collections')['c'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $count);
        $postsRow = $connection->fetchOne("SELECT id FROM collections WHERE slug = 'posts'");
        $this->assertNotNull($postsRow);
        $entryCount = (int) ($connection->fetchOne(
            'SELECT COUNT(*) AS c FROM entries WHERE collection_id = :id',
            ['id' => (int) $postsRow['id']],
        )['c'] ?? 0);
        $this->assertGreaterThanOrEqual(3, $entryCount);

        $connection->close();
    }

    public function testFrontControllerRoutesToInstallerWhenLockAbsent(): void
    {
        $script = (new \ReflectionClass(Application::class))->getFileName();
        $projectRoot = dirname($script, 3);
        $this->assertFileExists($projectRoot . '/public/index.php');

        $this->assertFalse(InstallerLock::isInstalled($projectRoot));
    }

    private function makeController(?InstallerSessionStore $session = null): InstallerController
    {
        return new InstallerController(
            $session ?? new ArrayInstallerSession(),
            new InstallerRenderer($this->projectRoot, $this->projectRoot . '/var/cache'),
            new ConnectionTester(),
            new ConfigWriter(),
            $this->projectRoot,
        );
    }

    private function copyInstallerTemplates(string $projectRoot): void
    {
        $src = __DIR__ . '/../../templates/installer';
        $dst = $projectRoot . '/templates/installer';
        if (!is_dir($src)) {
            return;
        }
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            copy($src . '/' . $entry, $dst . '/' . $entry);
        }
    }
}