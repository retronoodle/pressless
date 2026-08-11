<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Stead\Bootstrap\Application;
use Stead\Config\Configuration;
use Stead\Http\Kernel;
use Stead\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class KernelTest extends TestCase
{
    private function kernel(Router $router, string $environment = 'test'): Kernel
    {
        $config = new Configuration(sys_get_temp_dir(), $environment, [
            'app' => ['debug' => false],
            'paths' => ['log' => 'var/log'],
            'logging' => ['level' => 'debug', 'file' => 'stead-test.log'],
        ]);

        return new Kernel(new Application($config), $router);
    }

    private function router(): Router
    {
        $router = new Router();
        $router->get('/admin', static fn(): Response => new Response('shell'));
        $router->post('/admin/login', static fn(): Response => new Response('submitted'));
        $router->get(
            '/admin/entries/{id}',
            static fn(Request $r, array $p): Response => new Response('entry ' . $p['id']),
        );

        return $router;
    }

    public function testDispatchesHandlerResponse(): void
    {
        $response = $this->kernel($this->router())->handle(Request::create('/admin'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('shell', $response->getContent());
    }

    public function testPassesRouteParametersToHandler(): void
    {
        $response = $this->kernel($this->router())->handle(Request::create('/admin/entries/7'));

        $this->assertSame('entry 7', $response->getContent());
    }

    public function testUnknownPathReturns404(): void
    {
        $response = $this->kernel($this->router())->handle(Request::create('/does-not-exist'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not found', (string) $response->getContent());
    }

    public function testUnsupportedMethodReturns405WithAllowHeader(): void
    {
        $response = $this->kernel($this->router())->handle(Request::create('/admin', 'DELETE'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD', $response->headers->get('Allow'));
    }

    public function testRoutingFailuresDoNotLeakStackTraces(): void
    {
        $notFound = $this->kernel($this->router())->handle(Request::create('/nope'));
        $notAllowed = $this->kernel($this->router())->handle(Request::create('/admin', 'PUT'));

        foreach ([$notFound, $notAllowed] as $response) {
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString('#0', $body);
            $this->assertStringNotContainsString('Stack trace', $body);
            $this->assertStringNotContainsString(__DIR__, $body);
        }
    }

    public function testHandlerExceptionIsSafeInProduction(): void
    {
        $router = new Router();
        $router->get('/boom', static function (): Response {
            throw new \RuntimeException('database password is hunter2');
        });

        $response = $this->kernel($router, 'production')->handle(Request::create('/boom'));
        $body = (string) $response->getContent();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString(__FILE__, $body);
    }

    /**
     * Debug output is intentional outside production; this pins the boundary so
     * the two environments cannot silently converge.
     */
    public function testHandlerExceptionIsDetailedOutsideProduction(): void
    {
        $router = new Router();
        $router->get('/boom', static function (): Response {
            throw new \RuntimeException('detailed failure');
        });

        $response = $this->kernel($router, 'development')->handle(Request::create('/boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('detailed failure', (string) $response->getContent());
    }

    public function testJsonClientsReceiveJsonErrors(): void
    {
        $response = $this->kernel($this->router())->handle(
            Request::create('/nope', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(['error' => 'Not Found'], json_decode((string) $response->getContent(), true));
    }
}
