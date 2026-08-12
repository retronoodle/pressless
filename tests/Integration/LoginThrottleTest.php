<?php

declare(strict_types=1);

namespace Stead\Tests\Integration;

use Stead\Auth\AuthenticationService;
use Stead\Auth\LoginAttemptRepository;
use Stead\Auth\LoginThrottle;
use Stead\Auth\PasswordHasher;
use Stead\Auth\User;
use Stead\Auth\UserRepository;
use Stead\Config\Configuration;
use Stead\Http\Controller\LoginController;
use Stead\View\SimpleRenderer;
use Symfony\Component\HttpFoundation\Request;

final class LoginThrottleTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    protected static function driver(): string
    {
        return 'sqlite';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrator->migrate();
    }

    /**
     * Short thresholds + tiny windows so the smoke tests run in milliseconds
     * without mocking the clock. Real deployments use the production defaults
     * (5 attempts / 15 min / 60s base / 1hr cap).
     */
    private function fastConfig(int $maxAttempts = 3, int $windowSeconds = 60, int $baseSeconds = 5, int $maxSeconds = 60): Configuration
    {
        $values = $this->config->all();
        $values['login'] = [
            'max_attempts' => $maxAttempts,
            'window_seconds' => $windowSeconds,
            'lockout_base_seconds' => $baseSeconds,
            'lockout_max_seconds' => $maxSeconds,
        ];

        return new Configuration($this->config->projectRoot(), $this->config->environment(), $values);
    }

    private function controller(Configuration $config): LoginController
    {
        $hasher = new PasswordHasher(4);
        $users = new UserRepository($this->connection, $hasher);
        $auth = new AuthenticationService(
            $users,
            new \Stead\Auth\SessionRepository($this->connection),
            $hasher,
            new \Stead\Auth\ArraySessionStore(new \Stead\Auth\SessionRepository($this->connection)),
            3600,
        );
        $throttle = new LoginThrottle(new LoginAttemptRepository($this->connection), $config);

        return new LoginController($auth, new SimpleRenderer(), $throttle);
    }

    private function loginRequest(string $email, string $password, string $ip = '203.0.113.10'): Request
    {
        return Request::create('/admin/login', 'POST', ['email' => $email, 'password' => $password], [], [], ['REMOTE_ADDR' => $ip]);
    }

    public function testEmailThresholdLocksOutAndClearsAfterTheWindow(): void
    {
        $this->users()->create('ada@example.com', 'Ada', self::PASSWORD, User::ROLE_ADMIN, true);
        $config = $this->fastConfig(maxAttempts: 3, windowSeconds: 60, baseSeconds: 5, maxSeconds: 60);
        $controller = $this->controller($config);

        // Three failed attempts (matching the threshold) leave the next
        // request free of lockout but the failed attempts are recorded.
        for ($i = 0; $i < 3; $i++) {
            $response = $controller->login($this->loginRequest('ada@example.com', 'wrong'));
            $this->assertSame(401, $response->getStatusCode());
        }

        // The next attempt must be rejected with the same status and body as
        // an ordinary bad-password response, so lockout isn't distinguishable.
        $locked = $controller->login($this->loginRequest('ada@example.com', self::PASSWORD));
        $this->assertSame(401, $locked->getStatusCode());
        $this->assertStringContainsString('do not match our records', (string) $locked->getContent());

        // Backward-time-shift the recorded failures past the lockout window so
        // the test can confirm clearance without sleeping.
        $this->connection->execute(
            'UPDATE login_attempts SET created_at = :old WHERE email = :email',
            [
                'old' => LoginAttemptRepository::formatTimestamp(time() - 600),
                'email' => 'ada@example.com',
            ],
        );

        // A successful login now passes and redirects to /admin.
        $recovered = $controller->login($this->loginRequest('ada@example.com', self::PASSWORD));
        $this->assertSame(302, $recovered->getStatusCode());
        $this->assertSame('/admin', $recovered->getTargetUrl());
    }

    public function testIpLockoutTriggersAcrossDifferentEmails(): void
    {
        $this->users()->create('ada@example.com', 'Ada', self::PASSWORD, User::ROLE_ADMIN, true);
        $config = $this->fastConfig(maxAttempts: 3, windowSeconds: 60, baseSeconds: 5, maxSeconds: 60);
        $controller = $this->controller($config);

        // Three failures from the same IP across three DIFFERENT emails so
        // only the IP crosses the threshold; each individual email has just
        // one recorded failure.
        foreach (['ada@example.com', 'nobody@example.com', 'whoever@example.com'] as $email) {
            $response = $controller->login($this->loginRequest($email, 'wrong'));
            $this->assertSame(401, $response->getStatusCode());
        }

        // A different IP from a known email is not yet locked — both
        // identifier sides are below the threshold.
        $unrelatedIp = $controller->login($this->loginRequest(
            'ada@example.com',
            self::PASSWORD,
            ip: '198.51.100.7',
        ));
        $this->assertSame(302, $unrelatedIp->getStatusCode());

        // The original IP is locked even on a totally fresh (nonexistent) email.
        $lockedFresh = $controller->login($this->loginRequest('whoever@example.com', self::PASSWORD));
        $this->assertSame(401, $lockedFresh->getStatusCode());
        $this->assertStringContainsString('do not match our records', (string) $lockedFresh->getContent());

        // Lockout response must not distinguish "unknown email" from "real email".
        $lockedReal = $controller->login($this->loginRequest('ada@example.com', self::PASSWORD));
        $this->assertSame($lockedFresh->getStatusCode(), $lockedReal->getStatusCode());
        // Same generic error message — the only thing that must be indistinguishable.
        $this->assertStringContainsString('do not match our records', (string) $lockedReal->getContent());
    }

    public function testSuccessfulLoginClearsEmailFailuresButLeavesIpFailures(): void
    {
        $this->users()->create('ada@example.com', 'Ada', self::PASSWORD, User::ROLE_ADMIN, true);
        $config = $this->fastConfig(maxAttempts: 3, windowSeconds: 60, baseSeconds: 5, maxSeconds: 60);
        $controller = $this->controller($config);

        // Two failures — under the threshold — followed by a success.
        $controller->login($this->loginRequest('ada@example.com', 'wrong'));
        $controller->login($this->loginRequest('ada@example.com', 'wrong'));
        $controller->login($this->loginRequest('ada@example.com', self::PASSWORD));

        $repository = new LoginAttemptRepository($this->connection);
        $this->assertSame(0, $repository->countRecentEmailFailures('ada@example.com', 60));
        // IP failures are intentionally retained.
        $this->assertSame(2, $repository->countRecentIpFailures('203.0.113.10', 60));
    }

    private function users(): UserRepository
    {
        return new UserRepository($this->connection, new PasswordHasher(4));
    }
}
