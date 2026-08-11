<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pressless\Auth\NativeSessionStore;
use Pressless\Auth\PasswordHasher;
use Pressless\Config\Configuration;
use Pressless\Exception\SafeException;

final class SessionCookieTest extends TestCase
{
    /**
     * @param array<string, mixed> $sessions
     * @param array<string, mixed> $app
     */
    private function store(array $sessions, array $app = [], string $environment = 'production'): NativeSessionStore
    {
        return new NativeSessionStore(
            new Configuration(sys_get_temp_dir(), $environment, [
                'app' => $app,
                'sessions' => $sessions,
            ]),
        );
    }

    public function testProductionCookieIsHttpOnlyAndSecure(): void
    {
        $params = $this->store([
            'lifetime' => 7200,
            'cookie_secure' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ])->cookieParams();

        $this->assertTrue($params['secure']);
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertSame('/', $params['path']);
        $this->assertSame(7200, $params['lifetime']);
    }

    public function testSecureIsInferredFromAnHttpsAppUrl(): void
    {
        $params = $this->store(
            ['cookie_secure' => false],
            ['url' => 'https://cms.example.com'],
        )->cookieParams();

        $this->assertTrue($params['secure']);
    }

    public function testPlainHttpDevelopmentCookieIsNotSecure(): void
    {
        $params = $this->store(
            ['cookie_secure' => false],
            ['url' => 'http://localhost:8000'],
            'development',
        )->cookieParams();

        $this->assertFalse($params['secure']);
        $this->assertTrue($params['httponly'], 'HttpOnly should hold even in development.');
    }

    public function testSameSiteNoneIsDowngradedWithoutSecure(): void
    {
        $params = $this->store([
            'cookie_secure' => false,
            'cookie_samesite' => 'None',
        ], ['url' => 'http://localhost:8000'])->cookieParams();

        $this->assertSame('Lax', $params['samesite']);
    }

    public function testInvalidSameSiteFallsBackToLax(): void
    {
        $params = $this->store(['cookie_samesite' => 'Whatever'])->cookieParams();

        $this->assertSame('Lax', $params['samesite']);
    }

    public function testHasherRejectsUnusableCredentials(): void
    {
        $hasher = new PasswordHasher(4);

        $this->expectException(SafeException::class);
        $hasher->hash('short');
    }

    public function testHasherRejectsOverlongCredentials(): void
    {
        $hasher = new PasswordHasher(4);

        $this->expectException(SafeException::class);
        $hasher->hash(str_repeat('a', 73));
    }

    public function testHasherRejectionsDoNotEchoTheCredential(): void
    {
        $hasher = new PasswordHasher(4);
        $secret = 'hunter2';

        try {
            $hasher->hash($secret);
            $this->fail('Expected a SafeException.');
        } catch (SafeException $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function testVerifyRejectsEmptyInput(): void
    {
        $hasher = new PasswordHasher(4);
        $hash = $hasher->hash('a-valid-password');

        $this->assertFalse($hasher->verify('', $hash));
        $this->assertFalse($hasher->verify('a-valid-password', ''));
        $this->assertTrue($hasher->verify('a-valid-password', $hash));
    }
}
