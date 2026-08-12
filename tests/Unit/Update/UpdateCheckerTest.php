<?php

declare(strict_types=1);

namespace Stead\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stead\Config\Configuration;
use Stead\Update\InstalledVersion;
use Stead\Update\ReleaseEndpointClient;
use Stead\Update\UpdateCheckCache;
use Stead\Update\UpdateChecker;

/**
 * Covers the parts of the update-checker behaviour that don't require a
 * live network: installed-version reading, fail-closed on a disabled
 * endpoint, fail-closed on a network error surfaced as null, and the
 * cache-freshness reuse logic.
 *
 * The endpoint-client behaviour against a real socket is covered by
 * {@see ReleaseEndpointClientTest}.
 */
final class UpdateCheckerTest extends TestCase
{
    private string $projectRoot;
    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/stead-checker-' . bin2hex(random_bytes(4));
        mkdir($this->projectRoot, 0775, true);
        $this->cacheRoot = sys_get_temp_dir() . '/stead-cache-' . bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/VERSION');
        @rmdir($this->projectRoot);
        foreach (glob($this->cacheRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheRoot);
    }

    public function testReturnsUnknownResultWhenVersionFileMissing(): void
    {
        $checker = $this->makeChecker(endpointUrl: '');
        $result = $checker->check();
        $this->assertTrue($result->isUpToDate);
        $this->assertFalse($result->hasUpdate());
        $this->assertNull($result->latestVersion);
        $this->assertSame('unknown', $result->installedVersion);
    }

    public function testReturnsUpToDateWhenEndpointNotConfigured(): void
    {
        $this->writeVersion('1.0.0');
        $checker = $this->makeChecker(endpointUrl: '');
        $result = $checker->check();
        $this->assertTrue($result->isUpToDate);
        $this->assertFalse($result->hasUpdate());
        $this->assertSame('1.0.0', $result->installedVersion);
    }

    public function testFailsClosedWhenClientReturnsNull(): void
    {
        $this->writeVersion('1.0.0');
        $client = $this->makeClient(returnValue: null);
        $checker = $this->makeChecker(client: $client);
        $result = $checker->check();
        $this->assertTrue($result->isUpToDate, 'Endpoint failure must report up-to-date.');
        $this->assertFalse($result->hasUpdate(), 'Endpoint failure must not surface a banner.');
        $this->assertSame('1.0.0', $result->installedVersion);
    }

    public function testReportsNewVersionWhenLatestIsGreater(): void
    {
        $this->writeVersion('1.0.0');
        $client = $this->makeClient(
            returnValue: ['latest' => '1.1.0', 'url' => 'https://example.test/x.zip'],
            endpointUrl: 'http://endpoint.test/',
        );
        $checker = $this->makeChecker(endpointUrl: 'http://endpoint.test/', client: $client);
        $result = $checker->check();
        $this->assertFalse($result->isUpToDate);
        $this->assertTrue($result->hasUpdate());
        $this->assertSame('1.1.0', $result->latestVersion);
        $this->assertSame('https://example.test/x.zip', $result->downloadUrl);
    }

    public function testReportsUpToDateWhenLatestEqualsInstalled(): void
    {
        $this->writeVersion('1.0.0');
        $client = $this->makeClient(
            returnValue: ['latest' => '1.0.0', 'url' => 'https://example.test/x.zip'],
            endpointUrl: 'http://endpoint.test/',
        );
        $checker = $this->makeChecker(endpointUrl: 'http://endpoint.test/', client: $client);
        $result = $checker->check();
        $this->assertTrue($result->isUpToDate);
        $this->assertFalse($result->hasUpdate());
    }

    public function testReusesFreshCachedResultWithoutHittingClient(): void
    {
        $this->writeVersion('1.0.0');
        // Seed the cache with a fresh result.
        $cache = new UpdateCheckCache($this->cacheRoot);
        $cache->save(new \Stead\Update\UpdateCheckResult(
            installedVersion: '1.0.0',
            latestVersion: '2.0.0',
            downloadUrl: 'https://example.test/cached.zip',
            isUpToDate: false,
            error: null,
            fromCache: false,
            checkedAt: time(),
        ));

        // Client throws if called.
        $client = new class extends ReleaseEndpointClient {
            public function __construct()
            {
                parent::__construct('', 1);
            }
            public function fetchLatest(): ?array
            {
                throw new \RuntimeException('client should not be called when cache is fresh');
            }
        };

        $config = $this->makeConfig(endpointUrl: 'http://example.test/');
        $checker = new UpdateChecker(
            $config,
            new InstalledVersion($this->projectRoot),
            $client,
            $cache,
            new NullLogger(),
        );
        $result = $checker->check();
        $this->assertTrue($result->fromCache);
        $this->assertSame('2.0.0', $result->latestVersion);
    }

    private function makeChecker(
        string $endpointUrl = '',
        ?ReleaseEndpointClient $client = null,
        ?UpdateCheckCache $cache = null,
    ): UpdateChecker {
        $config = $this->makeConfig(endpointUrl: $endpointUrl);
        return new UpdateChecker(
            $config,
            new InstalledVersion($this->projectRoot),
            $client ?? new ReleaseEndpointClient($endpointUrl, 1),
            $cache ?? new UpdateCheckCache($this->cacheRoot),
            new NullLogger(),
        );
    }

    private function makeConfig(string $endpointUrl): Configuration
    {
        return new Configuration(
            $this->projectRoot,
            'production',
            [
                'update' => [
                    'endpoint_url' => $endpointUrl,
                    'check_interval_hours' => 24,
                    'timeout_seconds' => 1,
                ],
                'paths' => ['cache' => $this->cacheRoot],
            ],
        );
    }

    /**
     * @param array{latest: string, url: string}|null $returnValue
     */
    private function makeClient(?array $returnValue, string $endpointUrl = ''): ReleaseEndpointClient
    {
        return new class($returnValue, $endpointUrl) extends ReleaseEndpointClient {
            /**
             * @param array{latest: string, url: string}|null $returnValue
             */
            public function __construct(
                private readonly ?array $returnValue,
                string $endpointUrl,
            ) {
                parent::__construct($endpointUrl, 1);
            }

            /**
             * @return array{latest: string, url: string}|null
             */
            public function fetchLatest(): ?array
            {
                return $this->returnValue;
            }
        };
    }

    private function writeVersion(string $version): void
    {
        file_put_contents($this->projectRoot . '/VERSION', $version . "\n");
    }
}
