<?php

declare(strict_types=1);

namespace Pressless\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pressless\Http\RouteMatch;
use Pressless\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/admin/login', static fn(): Response => new Response('login form'));
        $router->post('/admin/login', static fn(): Response => new Response('login submit'));
        $router->post('/admin/logout', static fn(): Response => new Response('logout'));
        $router->get('/admin', static fn(): Response => new Response('shell'));
        $router->get(
            '/admin/entries/{collection}/{id}',
            static fn(Request $r, array $p): Response => new Response($p['collection'] . ':' . $p['id']),
        );

        return $router;
    }

    public function testDispatchesMatchingRoute(): void
    {
        $match = $this->router()->matchMethodAndPath('GET', '/admin');

        $this->assertTrue($match->isFound());
        $this->assertNotNull($match->route);
        $this->assertSame('/admin', $match->route->path);

        $response = ($match->route->handler())(Request::create('/admin'), []);
        $this->assertSame('shell', $response->getContent());
    }

    public function testDistinguishesMethodsOnTheSamePath(): void
    {
        $router = $this->router();

        $get = $router->matchMethodAndPath('GET', '/admin/login');
        $post = $router->matchMethodAndPath('POST', '/admin/login');

        $this->assertTrue($get->isFound());
        $this->assertTrue($post->isFound());
        $this->assertNotNull($get->route);
        $this->assertNotNull($post->route);
        $this->assertSame('GET', $get->route->method);
        $this->assertSame('POST', $post->route->method);

        $getBody = ($get->route->handler())(Request::create('/admin/login'), []);
        $postBody = ($post->route->handler())(Request::create('/admin/login', 'POST'), []);
        $this->assertSame('login form', $getBody->getContent());
        $this->assertSame('login submit', $postBody->getContent());
    }

    public function testExtractsRouteParameters(): void
    {
        $match = $this->router()->matchMethodAndPath('GET', '/admin/entries/posts/42');

        $this->assertTrue($match->isFound());
        $this->assertSame(['collection' => 'posts', 'id' => '42'], $match->parameters);
    }

    public function testParametersDoNotSpanPathSegments(): void
    {
        $match = $this->router()->matchMethodAndPath('GET', '/admin/entries/posts/42/extra');

        $this->assertSame(RouteMatch::NOT_FOUND, $match->status);
    }

    public function testUnknownPathIsNotFound(): void
    {
        $match = $this->router()->matchMethodAndPath('GET', '/nope');

        $this->assertSame(RouteMatch::NOT_FOUND, $match->status);
        $this->assertNull($match->route);
    }

    public function testUnsupportedMethodReportsAllowedMethods(): void
    {
        $match = $this->router()->matchMethodAndPath('DELETE', '/admin/login');

        $this->assertSame(RouteMatch::METHOD_NOT_ALLOWED, $match->status);
        $this->assertSame(['GET', 'HEAD', 'POST'], $match->allowedMethods);
    }

    public function testGetOnlyPathRejectsPost(): void
    {
        $match = $this->router()->matchMethodAndPath('POST', '/admin');

        $this->assertSame(RouteMatch::METHOD_NOT_ALLOWED, $match->status);
        $this->assertSame(['GET', 'HEAD'], $match->allowedMethods);
    }

    public function testHeadIsServedByTheGetHandler(): void
    {
        $match = $this->router()->matchMethodAndPath('HEAD', '/admin');

        $this->assertTrue($match->isFound());
        $this->assertNotNull($match->route);
        $this->assertSame('GET', $match->route->method);
    }

    public function testMatchesFromRequestObject(): void
    {
        $match = $this->router()->match(Request::create('/admin/login', 'POST'));

        $this->assertTrue($match->isFound());
        $this->assertNotNull($match->route);
        $this->assertSame('POST', $match->route->method);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function normalizationCases(): array
    {
        return [
            ['/admin/', '/admin'],
            ['/admin//login', '/admin/login'],
            ['admin', '/admin'],
            ['', '/'],
            ['/', '/'],
        ];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalizesPaths(string $input, string $expected): void
    {
        $this->assertSame($expected, Router::normalize($input));
    }

    public function testTrailingSlashResolvesToTheSameRoute(): void
    {
        $match = $this->router()->matchMethodAndPath('GET', '/admin/login/');

        $this->assertTrue($match->isFound());
    }
}
