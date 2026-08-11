<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pressless\Auth\AuthGuard;
use Symfony\Component\HttpFoundation\Request;

final class AuthGuardTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function unsafeTargets(): array
    {
        return [
            ['https://evil.example.com/steal'],
            ['http://evil.example.com'],
            ['//evil.example.com'],
            ['/\\evil.example.com'],
            ['\\\\evil.example.com'],
            ['admin'],
            [''],
            ["/admin\nLocation: https://evil.example.com"],
            ["/admin\r\nSet-Cookie: a=b"],
            ["/admin\0"],
            ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeTargetsAreRejected(string $target): void
    {
        $this->assertFalse(AuthGuard::isSafeTarget($target));
        $this->assertSame('/admin', AuthGuard::resolveTarget($target));
    }

    /**
     * @return list<array{string}>
     */
    public static function safeTargets(): array
    {
        return [
            ['/admin'],
            ['/admin/entries'],
            ['/admin/entries?page=2'],
            ['/admin/entries/posts/42'],
        ];
    }

    #[DataProvider('safeTargets')]
    public function testSafeTargetsAreAccepted(string $target): void
    {
        $this->assertTrue(AuthGuard::isSafeTarget($target));
        $this->assertSame($target, AuthGuard::resolveTarget($target));
    }

    public function testNullTargetFallsBackToAdmin(): void
    {
        $this->assertSame('/admin', AuthGuard::resolveTarget(null));
    }

    public function testLoginUrlPreservesTheRequestedPath(): void
    {
        $url = AuthGuard::loginUrlFor(Request::create('/admin/entries?page=2'));

        $this->assertSame('/admin/login?redirect=%2Fadmin%2Fentries%3Fpage%3D2', $url);
    }

    public function testLoginUrlDoesNotRedirectBackToItself(): void
    {
        $this->assertSame('/admin/login', AuthGuard::loginUrlFor(Request::create('/admin/login')));
    }
}
