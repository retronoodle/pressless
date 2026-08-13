<?php

declare(strict_types=1);

namespace Stead\Installer\Controller;

use Stead\Auth\PasswordHasher;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Config\Configuration;
use Stead\Installer\ConnectionTester;
use Stead\Installer\ConfigWriter;
use Stead\Installer\InstallerLock;
use Stead\Installer\InstallerRenderer;
use Stead\Installer\InstallerSessionStore;
use Stead\Console\Seeder;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Exception\SafeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer wizard controller.
 *
 * Each step renders a form, validates input, and writes the result into the
 * installer session. Step ordering is enforced by reading what previous steps
 * have persisted — a later step always redirects back to the earliest step
 * whose prerequisite is missing, so a wizard refresh / direct URL entry cannot
 * run step N before step N-1 succeeded.
 */
final class InstallerController
{
    private const STEPS = ['welcome', 'database', 'admin', 'sample-data', 'complete'];

    public function __construct(
        private readonly InstallerSessionStore $session,
        private readonly InstallerRenderer $renderer,
        private readonly ConnectionTester $connectionTester,
        private readonly ConfigWriter $configWriter,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     */
    public function welcome(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        return $this->render('welcome', [
            'step' => 'welcome',
            'step_index' => $this->stepIndex('welcome'),
            'steps' => self::STEPS,
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function showDatabase(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        $wizard = $this->session->getWizard();
        $values = $this->databaseDefaults();
        if (isset($wizard['db']) && is_array($wizard['db'])) {
            $values = array_merge($values, $wizard['db']);
            $values['password'] = '';
        }
        return $this->render('database', [
            'step' => 'database',
            'step_index' => $this->stepIndex('database'),
            'steps' => self::STEPS,
            'values' => $values,
            'error' => '',
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function submitDatabase(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        $input = $this->connectionTester->normalize([
            'driver' => (string) $request->request->get('driver', 'mysql'),
            'host' => (string) $request->request->get('host', '127.0.0.1'),
            'port' => (string) $request->request->get('port', '3306'),
            'database' => (string) $request->request->get('database', ''),
            'username' => (string) $request->request->get('username', ''),
            'password' => (string) $request->request->get('password', ''),
        ]);

        try {
            $config = $this->connectionTester->buildConfiguration($this->projectRoot, $input);
            $this->connectionTester->test($config);
        } catch (SafeException $exception) {
            $safeInput = $input;
            $safeInput['password'] = '';
            return $this->render('database', [
                'step' => 'database',
                'step_index' => $this->stepIndex('database'),
                'steps' => self::STEPS,
                'values' => $safeInput,
                'error' => $exception->publicMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $wizard = $this->session->getWizard();
        $wizard['db'] = $input;
        $wizard['db_tested'] = true;
        $wizard['app_env'] = $this->detectAppEnv();
        $wizard['session_name'] = 'stead_session';
        $this->session->setWizard($wizard);

        return new RedirectResponse('/install/admin');
    }

    /**
     * @param array<string, string> $parameters
     */
    public function showAdmin(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        $wizard = $this->session->getWizard();
        if (empty($wizard['db_tested'])) {
            return new RedirectResponse('/install/database');
        }

        $values = ['email' => '', 'name' => '', 'password' => '', 'confirm' => ''];
        if (isset($wizard['admin']) && is_array($wizard['admin'])) {
            $stored = $wizard['admin'];
            $values['email'] = (string) ($stored['email'] ?? '');
            $values['name'] = (string) ($stored['name'] ?? '');
            $values['password'] = '';
            $values['confirm'] = '';
        }

        return $this->render('admin', [
            'step' => 'admin',
            'step_index' => $this->stepIndex('admin'),
            'steps' => self::STEPS,
            'values' => $values,
            'errors' => [],
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function submitAdmin(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        $wizard = $this->session->getWizard();
        if (empty($wizard['db_tested'])) {
            return new RedirectResponse('/install/database');
        }

        $email = trim((string) $request->request->get('email', ''));
        $name = trim((string) $request->request->get('name', ''));
        $password = (string) $request->request->get('password', '');
        $confirm = (string) $request->request->get('confirm', '');

        $values = ['email' => $email, 'name' => $name, 'password' => '', 'confirm' => ''];
        $errors = [];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($name === '') {
            $errors['name'] = 'Enter a display name.';
        } else {
            $name = str_replace(["\r", "\n"], ' ', $name);
        }
        if (strlen($password) < PasswordHasher::MIN_LENGTH) {
            $errors['password'] = sprintf('Password must be at least %d characters.', PasswordHasher::MIN_LENGTH);
        } elseif (strlen($password) > PasswordHasher::MAX_LENGTH) {
            $errors['password'] = sprintf('Password must be at most %d bytes.', PasswordHasher::MAX_LENGTH);
        }
        if ($password !== '' && $confirm !== $password) {
            $errors['confirm'] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            return $this->render('admin', [
                'step' => 'admin',
                'step_index' => $this->stepIndex('admin'),
                'steps' => self::STEPS,
                'values' => $values,
                'errors' => $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $wizard['admin'] = [
            'email' => strtolower($email),
            'name' => $name,
            'password' => $password,
        ];
        $wizard['admin_validated'] = true;
        $this->session->setWizard($wizard);

        return new RedirectResponse('/install/sample-data');
    }

    /**
     * @param array<string, string> $parameters
     */
    public function showSampleData(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        $wizard = $this->session->getWizard();
        if (empty($wizard['db_tested'])) {
            return new RedirectResponse('/install/database');
        }
        if (empty($wizard['admin_validated'])) {
            return new RedirectResponse('/install/admin');
        }

        $choice = isset($wizard['sample_data']) && is_string($wizard['sample_data']) ? $wizard['sample_data'] : '';

        return $this->render('sample-data', [
            'step' => 'sample-data',
            'step_index' => $this->stepIndex('sample-data'),
            'steps' => self::STEPS,
            'choice' => $choice,
            'error' => '',
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function submitSampleData(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        $wizard = $this->session->getWizard();
        if (empty($wizard['db_tested'])) {
            return new RedirectResponse('/install/database');
        }
        if (empty($wizard['admin_validated'])) {
            return new RedirectResponse('/install/admin');
        }

        $choice = (string) $request->request->get('choice', '');
        if (!in_array($choice, ['yes', 'no'], true)) {
            return $this->render('sample-data', [
                'step' => 'sample-data',
                'step_index' => $this->stepIndex('sample-data'),
                'steps' => self::STEPS,
                'choice' => '',
                'error' => 'Choose whether to load sample data.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $wizard['sample_data'] = $choice;
        $wizard['sample_data_chosen'] = true;
        $this->session->setWizard($wizard);

        return new RedirectResponse('/install/complete');
    }

    /**
     * @param array<string, string> $parameters
     */
    public function complete(Request $request, array $parameters = []): Response
    {
        $this->session->start();
        $wizard = $this->session->getWizard();

        if (empty($wizard['db_tested'])) {
            return new RedirectResponse('/install/database');
        }
        if (empty($wizard['admin_validated'])) {
            return new RedirectResponse('/install/admin');
        }
        if (empty($wizard['sample_data_chosen'])) {
            return new RedirectResponse('/install/sample-data');
        }

        $db = $wizard['db'] ?? null;
        $admin = $wizard['admin'] ?? null;
        if (!is_array($db) || !is_array($admin)) {
            return new RedirectResponse('/install/database');
        }

        if (InstallerLock::isInstalled($this->projectRoot)) {
            return $this->finishRedirect();
        }

        $config = $this->buildLiveConfiguration($db, $wizard);
        $connection = new Connection($config);

        $migrator = new Migrator($connection, $config);
        $migrator->migrate();

        $users = new UserRepository($connection, new PasswordHasher());
        $users->create(
            (string) $admin['email'],
            (string) $admin['name'],
            (string) $admin['password'],
            User::ROLE_ADMIN,
            true,
        );

        $seeder = new Seeder($connection, $config);
        $seeder->seedDefaultCollections();

        if (($wizard['sample_data'] ?? 'no') === 'yes') {
            $seeder->seed(allowProduction: true);
        }

        $connection->close();

        // Only point .env (and the installer lock) at this database once it's
        // fully migrated and has an admin user — otherwise a request that
        // lands between the write and the migration would find a real path
        // with no schema behind it yet.
        $this->writeConfig($wizard, $db);
        InstallerLock::create($this->projectRoot);

        return $this->finishRedirect();
    }

    private function finishRedirect(): RedirectResponse
    {
        $this->session->clearWizard();
        return new RedirectResponse('/admin/login');
    }

    /**
     * @param array<string, mixed> $wizard
     * @param array<string, mixed> $db
     */
    private function writeConfig(array $wizard, array $db): void
    {
        $values = [
            'APP_ENV' => (string) ($wizard['app_env'] ?? 'production'),
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => (string) $db['driver'],
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'SESSION_NAME' => (string) ($wizard['session_name'] ?? 'stead_session'),
            'LOGGING_LEVEL' => 'info',
            'LOGGING_FILE' => 'stead.log',
        ];
        $this->configWriter->writeEnv($this->projectRoot, $values);
    }

    /**
     * @param array<string, mixed> $db
     * @param array<string, mixed> $wizard
     */
    private function buildLiveConfiguration(array $db, array $wizard): Configuration
    {
        $values = [
            'app' => [
                'environment' => (string) ($wizard['app_env'] ?? 'production'),
                'debug' => false,
            ],
            'database' => [
                'connection' => (string) $db['driver'],
                'host' => (string) $db['host'],
                'port' => (int) $db['port'],
                'database' => (string) $db['database'],
                'username' => (string) $db['username'],
                'password' => (string) $db['password'],
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'paths' => [
                'migrations' => 'database/migrations',
                'seed' => 'database/seed',
                'templates' => 'templates',
                'cache' => 'var/cache',
                'log' => 'var/log',
                'storage' => 'storage/media',
                'theme' => 'themes',
            ],
            'theme' => ['active' => ''],
            'sessions' => [
                'name' => (string) ($wizard['session_name'] ?? 'stead_session'),
                'lifetime' => 7200,
                'cookie_secure' => false,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ],
            'logging' => ['level' => 'info', 'file' => 'stead.log'],
        ];
        return new Configuration($this->projectRoot, (string) ($wizard['app_env'] ?? 'production'), $values);
    }

    private function stepIndex(string $step): int
    {
        $index = array_search($step, self::STEPS, true);
        return $index === false ? 0 : (int) $index;
    }

    private function detectAppEnv(): string
    {
        if (isset($_SERVER['APP_ENV'])) {
            $env = (string) $_SERVER['APP_ENV'];
            if (in_array($env, ['production', 'development'], true)) {
                return $env;
            }
        }
        return 'production';
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseDefaults(): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $template, array $data, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->renderer->render($template, $data),
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}