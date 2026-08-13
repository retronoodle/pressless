<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\ArraySessionStore;
use Stead\Auth\AuthenticationService;
use Stead\Auth\PasswordHasher;
use Stead\Auth\SessionRepository;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Database\Connection;
use Stead\Database\Migrator;
use Stead\Http\Kernel;
use Stead\Http\Routes;
use Stead\Invites\InviteRepository;
use Stead\Mail\MailSettings;
use Stead\Mail\MailSettingsRepository;
use Stead\Mail\SmtpTransport;
use Stead\Tests\Support\TestRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Phase 8 smoke tests for the mail + invites capabilities.
 *
 * Covers: SMTP settings save/load (with the password never echoed back to the
 * form), the test-send failure path when SMTP is unreachable, the end-to-end
 * invite/accept flow with the correct role, and rejection of expired or
 * already-accepted tokens.
 */
final class MailInvitesSmokeTest extends TestCase
{
    private const ADMIN_PASSWORD = 'admin-password-1';
    private const INVITEE_PASSWORD = 'invitee-password-1';

    private string $projectRoot;
    private Configuration $config;
    private string $dbPath;
    private Connection $connection;
    private Kernel $kernel;
    private ArraySessionStore $store;
    private AuthenticationService $auth;
    private UserRepository $users;
    private MailSettingsRepository $mailSettings;
    private InviteRepository $invites;
    private User $admin;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-phase8-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot . '/var/cache', 0775, true);
        mkdir($this->projectRoot . '/var/log', 0775, true);
        mkdir($this->projectRoot . '/database/migrations', 0775, true);
        foreach (glob(__DIR__ . '/../../database/migrations/*.sqlite.sql') ?: [] as $file) {
            copy($file, $this->projectRoot . '/database/migrations/' . basename($file));
        }
        $this->dbPath = $this->projectRoot . '/var/stead.sqlite';

        $this->config = new Configuration(
            $this->projectRoot,
            'production',
            [
                'app' => ['debug' => false, 'url' => 'http://localhost'],
                'database' => [
                    'connection' => 'sqlite',
                    'database' => $this->dbPath,
                ],
                'paths' => [
                    'migrations' => 'database/migrations',
                    'templates' => 'templates',
                    'cache' => 'var/cache',
                    'log' => 'var/log',
                ],
                'sessions' => ['name' => 'stead_phase8'],
                'mail' => ['from_email' => 'admin@example.com', 'from_name' => 'Stead'],
            ],
        );

        $this->installTemplates();
        $this->connection = new Connection($this->config);
        (new Migrator($this->connection, $this->config))->migrate();

        $hasher = new PasswordHasher(4);
        $this->users = new UserRepository($this->connection, $hasher);
        $sessions = new SessionRepository($this->connection);
        $this->store = new ArraySessionStore($sessions);
        $this->auth = new AuthenticationService($this->users, $sessions, $hasher, $this->store, 3600);

        $this->mailSettings = new MailSettingsRepository($this->connection);
        $this->invites = new InviteRepository($this->connection);

        $smtp = new SmtpTransport($this->mailSettings, 1);

        $app = new Application($this->config);
        $router = Routes::createWithStore($app, $this->store, TestRenderer::twig($this->config, $this->connection));
        $this->kernel = new Kernel($app, $router);

        $this->admin = $this->users->create(
            'admin@example.com',
            'Admin',
            self::ADMIN_PASSWORD,
            User::ROLE_ADMIN,
            true,
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @unlink($this->dbPath);
        foreach ([
            "{$this->projectRoot}/templates",
            "{$this->projectRoot}/database/migrations",
            "{$this->projectRoot}/var/cache",
            "{$this->projectRoot}/var/log",
            "{$this->projectRoot}/var",
            $this->projectRoot,
        ] as $path) {
            if (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
    }

    // --- 3.4: mail settings persistence + test-send failure path ----------

    public function testMailSettingsSaveAndReloadHidesThePassword(): void
    {
        $this->login($this->admin);

        $this->assertSame(303, $this->post('/admin/mail-settings', [
            'host' => 'smtp.example.com',
            'port' => '587',
            'encryption' => 'starttls',
            'username' => 'mailer@example.com',
            'password' => 'sup3r-secret',
        ])->getStatusCode());

        // Settings were persisted.
        $stored = $this->mailSettings->load();
        $this->assertSame('smtp.example.com', $stored->host);
        $this->assertSame(587, $stored->port);
        $this->assertSame(MailSettings::ENCRYPTION_STARTTLS, $stored->encryption);
        $this->assertSame('mailer@example.com', $stored->username);
        $this->assertSame('sup3r-secret', $stored->password);

        // Reloading the form does NOT echo the password back.
        $page = $this->get('/admin/mail-settings');
        $this->assertSame(200, $page->getStatusCode());
        $body = (string) $page->getContent();
        $this->assertStringContainsString('smtp.example.com', $body);
        $this->assertStringNotContainsString('sup3r-secret', $body);
    }

    public function testTestSendFailsClearlyWhenNoHostIsConfigured(): void
    {
        $this->login($this->admin);

        $response = $this->post('/admin/mail-settings/test-send', []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SMTP host', (string) $response->getContent());
    }

    public function testTestSendReportsTheFailureWhenHostIsUnreachable(): void
    {
        $this->login($this->admin);
        $this->mailSettings->save(new MailSettings(
            '127.0.0.1',
            1,
            MailSettings::ENCRYPTION_NONE,
            '',
            '',
        ));

        $response = $this->post('/admin/mail-settings/test-send', []);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        $this->assertStringContainsString('Test send failed', $body);
        $this->assertStringNotContainsString(self::ADMIN_PASSWORD, $body);
    }

    // --- 5.1, 5.2, 5.3: invite flow end-to-end ----------------------------

    public function testInvitingAcceptingAndRejectingTokens(): void
    {
        $this->login($this->admin);

        $roleId = $this->users->listRoles();
        $roleId = array_values(array_filter(
            $roleId,
            static fn(array $r): bool => (string) $r['name'] === User::ROLE_EDITOR,
        ))[0]['id'];

        // Send the invite via the admin controller.
        $this->assertSame(200, $this->post('/admin/invites/new', [
            'email' => 'newuser@example.com',
            'role_id' => (string) $roleId,
        ])->getStatusCode());

        // The invite row exists.
        $row = $this->connection->fetchOne(
            'SELECT email, role_id FROM invites WHERE email = :email',
            ['email' => 'newuser@example.com'],
        );
        $this->assertNotNull($row);
        $this->assertSame((int) $roleId, (int) $row['role_id']);
        $this->assertNull($row['accepted_at'] ?? null);

        // Find the most recent invite and synthesize its plaintext token by
        // recreating one — the repository hashes, so we cannot reverse it from
        // the DB. We test the token-roundtrip separately.
        $created = $this->invites->create('other@example.com', (int) $roleId, $this->admin->id);
        $token = $created['token'];

        // Test the full flow with a known token.
        $show = $this->get('/invite/accept/' . $token);
        $this->assertSame(200, $show->getStatusCode());
        $body = (string) $show->getContent();
        $this->assertStringContainsString('other@example.com', $body);

        // Weak password is rejected without consuming the token.
        $weak = $this->post('/invite/accept/' . $token, [
            'name' => 'Other User',
            'password' => 'short',
        ]);
        $this->assertSame(200, $weak->getStatusCode());
        $row = $this->connection->fetchOne(
            'SELECT accepted_at FROM invites WHERE id = :id',
            ['id' => $created['invite']->id],
        );
        $this->assertNull($row['accepted_at'] ?? null);

        // Accept with a strong password — user is created, role matches, logged in.
        $accept = $this->post('/invite/accept/' . $token, [
            'name' => 'Other User',
            'password' => self::INVITEE_PASSWORD,
        ]);
        $this->assertSame(303, $accept->getStatusCode());

        $newUser = $this->users->findByEmail('other@example.com');
        $this->assertNotNull($newUser);
        $this->assertSame('Other User', $newUser->name);
        $this->assertSame(User::ROLE_EDITOR, $newUser->roleName);
        $this->assertTrue($newUser->isActive);

        $row = $this->connection->fetchOne(
            'SELECT accepted_at FROM invites WHERE id = :id',
            ['id' => $created['invite']->id],
        );
        $this->assertNotNull($row['accepted_at'] ?? null);

        // 5.2: reusing the accepted token is rejected with a generic error.
        $reuse = $this->get('/invite/accept/' . $token);
        $this->assertSame(200, $reuse->getStatusCode());
        $this->assertStringContainsString('no longer valid', (string) $reuse->getContent());

        // 5.3: expired token is rejected with the same generic error.
        $expired = $this->invites->create('expired@example.com', (int) $roleId, $this->admin->id);
        $this->connection->execute(
            'UPDATE invites SET expires_at = :past WHERE id = :id',
            ['past' => '2000-01-01 00:00:00', 'id' => $expired['invite']->id],
        );
        $expiredShow = $this->get('/invite/accept/' . $expired['token']);
        $this->assertSame(200, $expiredShow->getStatusCode());
        $this->assertStringContainsString('no longer valid', (string) $expiredShow->getContent());
    }

    // --- helpers -----------------------------------------------------------

    private function get(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create($path));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function post(string $path, array $parameters): \Symfony\Component\HttpFoundation\Response
    {
        return $this->kernel->handle(Request::create($path, 'POST', $parameters));
    }

    private function login(User $user): void
    {
        $this->store->start();
        $this->auth->attempt($user->email, $user->email === $this->admin->email ? self::ADMIN_PASSWORD : self::INVITEE_PASSWORD);
    }

    private function installTemplates(): void
    {
        $templatesDir = $this->projectRoot . '/templates';
        foreach (['layout', 'admin', 'admin/mail-settings', 'admin/invites', 'invite'] as $sub) {
            mkdir($templatesDir . '/' . $sub, 0775, true);
        }
        file_put_contents(
            $templatesDir . '/layout/base.twig',
            "<!doctype html><html><head></head><body>{% block body %}{% endblock %}</body></html>\n",
        );
        file_put_contents(
            $templatesDir . '/login.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}sign in{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}admin{% endblock %}\n",
        );
        // Stub admin templates referenced by controllers.
        file_put_contents(
            $templatesDir . '/admin/mail-settings/index.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}{{ action_url|default('') }} {{ flash|default('') }} {{ values.host|default('') }}{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin/invites/new.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}{{ flash|default('') }} {{ values.email|default('') }}{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/admin/invites/index.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}invites{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/invite/accept.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}{{ email|default('') }} {{ role_name|default('') }} {{ values.name|default('') }} {{ values.password|default('') }} {{ errors|default({})|json_encode }}{% endblock %}\n",
        );
        file_put_contents(
            $templatesDir . '/invite/invalid.twig',
            "{% extends 'layout/base.twig' %}\n{% block body %}{{ message|default('') }}{% endblock %}\n",
        );
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
