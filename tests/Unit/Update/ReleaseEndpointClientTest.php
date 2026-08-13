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
 * The smoke test in tasks.md (5.1/5.3) ultimately runs against the real
 * GitHub Releases API; these tests pin the in-process behaviour so a
 * regression to "endpoint unreachable" → null vs → throw is caught here,
 * before it can ever reach the admin UI.
 *
 * The fake server stands in for `https://api.github.com`. The test
 * subclass of {@see ReleaseEndpointClient} overrides `apiUrl()` to point
 * at the local server so we don't need DNS spoofing or HTTPS in the
 * sandbox; the production URL path (`/repos/<owner>/<repo>/releases/latest`)
 * is preserved so the test exercises the same path-based routing.
 */
final class ReleaseEndpointClientTest extends TestCase
{
    private ?FakeReleaseServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testReturnsLatestAndZipAssetUrlOnSuccess(): void
    {
        $body = json_encode([
            'tag_name' => 'v1.2.3',
            'assets' => [
                [
                    'name' => 'stead-1.2.3.zip',
                    'browser_download_url' => 'https://example.test/stead-1.2.3.zip',
                ],
                [
                    'name' => 'stead-1.2.3.tar.gz',
                    'browser_download_url' => 'https://example.test/stead-1.2.3.tar.gz',
                ],
            ],
            'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.2.3',
        ]);
        $this->assertNotFalse($body);

        $this->server = new FakeReleaseServer(200, $body);
        $client = $this->makeClient($this->server);
        $payload = $client->fetchLatest();
        $this->assertSame(
            ['latest' => '1.2.3', 'url' => 'https://example.test/stead-1.2.3.zip'],
            $payload,
        );
    }

    public function testFallsBackToZipballUrlWhenNoZipAssetAttached(): void
    {
        $body = json_encode([
            'tag_name' => 'v1.2.3',
            'assets' => [
                ['name' => 'stead-1.2.3.tar.gz', 'browser_download_url' => 'https://example.test/x.tar.gz'],
            ],
            'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.2.3',
        ]);
        $this->assertNotFalse($body);

        $this->server = new FakeReleaseServer(200, $body);
        $client = $this->makeClient($this->server);
        $payload = $client->fetchLatest();
        $this->assertSame(
            ['latest' => '1.2.3', 'url' => 'https://api.github.com/repos/o/r/zipball/v1.2.3'],
            $payload,
        );
    }

    public function testFallsBackToZipballUrlWhenAssetsArrayMissing(): void
    {
        $body = json_encode([
            'tag_name' => 'v1.2.3',
            'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.2.3',
        ]);
        $this->assertNotFalse($body);

        $this->server = new FakeReleaseServer(200, $body);
        $client = $this->makeClient($this->server);
        $payload = $client->fetchLatest();
        $this->assertSame(
            ['latest' => '1.2.3', 'url' => 'https://api.github.com/repos/o/r/zipball/v1.2.3'],
            $payload,
        );
    }

    public function testReturnsNullWhenNoUsableUrl(): void
    {
        $body = json_encode([
            'tag_name' => 'v1.2.3',
            'assets' => [],
            'zipball_url' => '',
        ]);
        $this->assertNotFalse($body);

        $this->server = new FakeReleaseServer(200, $body);
        $client = $this->makeClient($this->server);
        $this->assertNull($client->fetchLatest());
    }

    public function testReturnsNullOnNonTwoXxStatus(): void
    {
        $this->server = new FakeReleaseServer(
            500,
            json_encode(['tag_name' => 'v1.2.3', 'assets' => [], 'zipball_url' => 'x']) ?: '',
        );
        $client = $this->makeClient($this->server);
        $this->assertNull($client->fetchLatest());
    }

    public function testReturnsNullOnMalformedJson(): void
    {
        $this->server = new FakeReleaseServer(200, '<html>oops</html>');
        $client = $this->makeClient($this->server);
        $this->assertNull($client->fetchLatest());
    }

    public function testReturnsNullWhenTagNameMissing(): void
    {
        $body = json_encode([
            'assets' => [['name' => 'stead-1.2.3.zip', 'browser_download_url' => 'https://x.test/z.zip']],
            'zipball_url' => 'https://x.test/z',
        ]);
        $this->assertNotFalse($body);

        $this->server = new FakeReleaseServer(200, $body);
        $client = $this->makeClient($this->server);
        $this->assertNull($client->fetchLatest());
    }

    public function testReturnsNullWhenEndpointUnreachable(): void
    {
        // No server bound at this address; connect should fail and the
        // client must report null (not throw). We subclass to point at
        // a known-closed port without spinning up a server.
        $client = new class('owner/repo', 1, 'http://127.0.0.1:1/') extends ReleaseEndpointClient {
            public function __construct(string $githubRepo, int $timeoutSeconds, private readonly string $overrideBaseUrl)
            {
                parent::__construct($githubRepo, $timeoutSeconds);
            }

            protected function apiUrl(): string
            {
                return $this->overrideBaseUrl;
            }
        };
        $this->assertNull($client->fetchLatest());
    }

    public function testEmptyGithubRepoIsANoOp(): void
    {
        $client = new ReleaseEndpointClient('', 5);
        $this->assertSame('', $client->githubRepo());
        $this->assertNull($client->fetchLatest());
    }

    public function testMalformedGithubRepoIsANoOp(): void
    {
        $client = new ReleaseEndpointClient('not-a-slash', 5);
        $this->assertNull($client->fetchLatest());
    }

    public function testTagNameWithoutLeadingVIsUsedAsIs(): void
    {
        $body = json_encode([
            'tag_name' => '1.2.3',
            'assets' => [['name' => 'stead-1.2.3.zip', 'browser_download_url' => 'https://x.test/z.zip']],
            'zipball_url' => '',
        ]);
        $this->assertNotFalse($body);

        $this->server = new FakeReleaseServer(200, $body);
        $client = $this->makeClient($this->server);
        $payload = $client->fetchLatest();
        $this->assertSame(['latest' => '1.2.3', 'url' => 'https://x.test/z.zip'], $payload);
    }

    private function makeClient(FakeReleaseServer $server): ReleaseEndpointClient
    {
        return new class('owner/repo', 5, $server->url() . 'repos/owner/repo/releases/latest') extends ReleaseEndpointClient {
            public function __construct(string $githubRepo, int $timeoutSeconds, private readonly string $overrideBaseUrl)
            {
                parent::__construct($githubRepo, $timeoutSeconds);
            }

            protected function apiUrl(): string
            {
                return $this->overrideBaseUrl;
            }
        };
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