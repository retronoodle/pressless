<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use Stead\Update\ReleaseEndpointClient;

/**
 * Drives the cURL-based release-endpoint client against a tiny PHP-built-in
 * server that lives for the duration of one test. Each test starts its
 * own server on an ephemeral port so the suite is parallel-safe.
 *
 * The smoke test in tasks.md (5.1/5.3) ultimately runs against a real
 * HTTPS endpoint; these tests pin the in-process behaviour so a regression
 * to "endpoint unreachable" → null vs → throw is caught here, before it
 * can ever reach the admin UI.
 */
final class ReleaseEndpointClientTest extends TestCase
{
    private ?FakeReleaseServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testReturnsLatestAndUrlOnSuccess(): void
    {
        $this->server = new FakeReleaseServer(
            responseStatus: 200,
            responseBody: '{"latest":"1.2.3","url":"https://example.test/stead-1.2.3.zip"}',
        );
        $client = new ReleaseEndpointClient($this->server->url(), timeoutSeconds: 5);
        $payload = $client->fetchLatest();
        $this->assertSame(['latest' => '1.2.3', 'url' => 'https://example.test/stead-1.2.3.zip'], $payload);
    }

    public function testReturnsNullWhenEndpointReturnsNonJson(): void
    {
        $this->server = new FakeReleaseServer(200, '<html>oops</html>');
        $client = new ReleaseEndpointClient($this->server->url(), 5);
        $this->assertNull($client->fetchLatest());
    }

    public function testReturnsNullWhenEndpointReturnsMissingFields(): void
    {
        $this->server = new FakeReleaseServer(200, '{"latest":"1.2.3"}');
        $client = new ReleaseEndpointClient($this->server->url(), 5);
        $this->assertNull($client->fetchLatest());
    }

    public function testReturnsNullOnNonTwoXxStatus(): void
    {
        $this->server = new FakeReleaseServer(500, '{"latest":"1.2.3","url":"x"}');
        $client = new ReleaseEndpointClient($this->server->url(), 5);
        $this->assertNull($client->fetchLatest());
    }

    public function testReturnsNullWhenEndpointUnreachable(): void
    {
        // No server bound at this address; connect should fail and the
        // client must report null (not throw).
        $client = new ReleaseEndpointClient('http://127.0.0.1:1/', timeoutSeconds: 1);
        $this->assertNull($client->fetchLatest());
    }

    public function testEmptyEndpointUrlIsANoOp(): void
    {
        $client = new ReleaseEndpointClient('', 5);
        $this->assertSame('', $client->endpointUrl());
        $this->assertNull($client->fetchLatest());
    }
}

/**
 * One-shot HTTP server used by the client tests. Backed by the PHP CLI
 * built-in server (`php -S`) so we get a real HTTP listener — curl in the
 * client tests behaves exactly as it will in production against this.
 */
final class FakeReleaseServer
{
    /** @var resource|null */
    private $process = null;
    private string $url;
    private string $routerPath;

    public function __construct(int $responseStatus, string $responseBody)
    {
        $this->routerPath = '';

        // Pick a free port by binding a throwaway socket.
        $probe = @stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('Could not pick a port for the fake release server.');
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $this->url = 'http://' . $name . '/';

        $routerPath = tempnam(sys_get_temp_dir(), 'stead-fake-release-');
        if ($routerPath === false) {
            throw new \RuntimeException('Could not write a router script for the fake release server.');
        }
        // Nowdoc (single-quoted heredoc label) keeps the response body
        // literal — we don't want addslashes on a JSON blob because that
        // would inject backslashes into the rendered response. JSON itself
        // is safe here (no PHP variable interpolation characters).
        file_put_contents($routerPath, <<<'PHP'
<?php
header('Content-Type: application/json');
PHP);
        file_put_contents(
            $routerPath,
            "\nhttp_response_code({$responseStatus});\necho " . var_export($responseBody, true) . ";\n",
            FILE_APPEND,
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            sprintf('php -S %s %s', escapeshellarg($name), escapeshellarg($routerPath)),
            $descriptors,
            $pipes,
        );
        if (!is_resource($proc)) {
            @unlink($routerPath);
            throw new \RuntimeException('Could not start fake release server.');
        }
        $this->process = $proc;
        $this->routerPath = $routerPath;

        // Wait for the server to be reachable. Built-in `php -S` boots in
        // ~50ms; we poll a few times with curl to avoid a race where the
        // first request hits a not-yet-listening port.
        $deadline = microtime(true) + 2.0;
        do {
            $probe = @curl_init($this->url);
            if ($probe !== false) {
                curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
                curl_exec($probe);
                $status = (int) curl_getinfo($probe, CURLINFO_RESPONSE_CODE);
                curl_close($probe);
                if ($status === $responseStatus) {
                    return;
                }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
    }

    public function url(): string
    {
        return $this->url;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process, 9);
            @proc_close($this->process);
        }
        if ($this->routerPath !== '' && is_file($this->routerPath)) {
            @unlink($this->routerPath);
        }
    }
}
