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
        $checker = $this->makeChecker(githubRepo: '');
        $result = $checker->check();
        $this->assertTrue($result->isUpToDate);
        $this->assertFalse($result->hasUpdate());
        $this->assertNull($result->latestVersion);
        $this->assertSame('unknown', $result->installedVersion);
    }

    public function testReturnsUpToDateWhenEndpointNotConfigured(): void
    {
        $this->writeVersion('1.0.0');
        $checker = $this->makeChecker(githubRepo: '');
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
            returnValue: ['latest' => '1.1.0', 'url' => 'https://example.test/x.zip', 'published_at' => '2026-08-13T12:34:56Z'],
            githubRepo: 'owner/repo',
        );
        $checker = $this->makeChecker(githubRepo: 'owner/repo', client: $client);
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
            returnValue: ['latest' => '1.0.0', 'url' => 'https://example.test/x.zip', 'published_at' => '2026-08-13T12:34:56Z'],
            githubRepo: 'owner/repo',
        );
        $checker = $this->makeChecker(githubRepo: 'owner/repo', client: $client);
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
            installedVersionReleasedAt: '2026-01-01T00:00:00Z',
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

        $config = $this->makeConfig(githubRepo: 'owner/repo');
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
        $this->assertSame('2026-01-01T00:00:00Z', $result->installedVersionReleasedAt);
    }

    public function testInstalledEqualsLatestPopulatesInstalledVersionReleasedAtFromLatest(): void
    {
        $this->writeVersion('1.2.3');
        $client = $this->makeClient(
            returnValue: ['latest' => '1.2.3', 'url' => 'https://example.test/x.zip', 'published_at' => '2026-08-13T12:34:56Z'],
            githubRepo: 'owner/repo',
        );
        $checker = $this->makeChecker(githubRepo: 'owner/repo', client: $client);
        $result = $checker->check();
        $this->assertSame('2026-08-13T12:34:56Z', $result->installedVersionReleasedAt);
    }

    public function testInstalledDiffersFromLatestCallsFetchReleaseByTagWithVPrefix(): void
    {
        $this->writeVersion('1.0.0');
        $client = $this->makeClientWithTag(
            fetchLatestReturn: ['latest' => '2.0.0', 'url' => 'https://example.test/x.zip', 'published_at' => '2026-09-01T00:00:00Z'],
            fetchReleaseByTagReturn: ['version' => '1.0.0', 'published_at' => '2026-01-01T00:00:00Z', 'download_url' => 'https://example.test/1.0.0.zip'],
            githubRepo: 'owner/repo',
        );
        $checker = $this->makeChecker(githubRepo: 'owner/repo', client: $client);
        $result = $checker->check();
        $this->assertSame('2026-01-01T00:00:00Z', $result->installedVersionReleasedAt);
        /** @phpstan-ignore-next-line accessing-test-double-state */
        $this->assertSame('v1.0.0', $client->lastTagFetched);
    }

    public function testFetchReleaseByTagFailureLeavesInstalledVersionReleasedAtNull(): void
    {
        $this->writeVersion('1.0.0');
        $client = $this->makeClientWithTag(
            fetchLatestReturn: ['latest' => '2.0.0', 'url' => 'https://example.test/x.zip', 'published_at' => '2026-09-01T00:00:00Z'],
            fetchReleaseByTagReturn: null,
            githubRepo: 'owner/repo',
        );
        $checker = $this->makeChecker(githubRepo: 'owner/repo', client: $client);
        $result = $checker->check();
        $this->assertNull($result->installedVersionReleasedAt);
        // The check itself still completes normally with the latest info.
        $this->assertSame('2.0.0', $result->latestVersion);
    }

    public function testFetchReleaseByTagMissingMethodDoesNotThrow(): void
    {
        $this->writeVersion('1.0.0');
        // A client without the new method must still be tolerated by
        // the checker, leaving the date null and continuing.
        $client = $this->makeClientWithoutFetchReleaseByTag(
            returnValue: ['latest' => '2.0.0', 'url' => 'https://example.test/x.zip', 'published_at' => '2026-09-01T00:00:00Z'],
            githubRepo: 'owner/repo',
        );
        $checker = $this->makeChecker(githubRepo: 'owner/repo', client: $client);
        $result = $checker->check();
        $this->assertNull($result->installedVersionReleasedAt);
        $this->assertSame('2.0.0', $result->latestVersion);
    }

    public function testNoRepoConfiguredSetsInstalledVersionReleasedAtToNull(): void
    {
        $this->writeVersion('1.0.0');
        $checker = $this->makeChecker(githubRepo: '');
        $result = $checker->check();
        $this->assertNull($result->installedVersionReleasedAt);
        $this->assertTrue($result->isUpToDate);
    }

    private function makeChecker(
        string $githubRepo = '',
        ?ReleaseEndpointClient $client = null,
        ?UpdateCheckCache $cache = null,
    ): UpdateChecker {
        $config = $this->makeConfig(githubRepo: $githubRepo);
        return new UpdateChecker(
            $config,
            new InstalledVersion($this->projectRoot),
            $client ?? new ReleaseEndpointClient($githubRepo, 1),
            $cache ?? new UpdateCheckCache($this->cacheRoot),
            new NullLogger(),
        );
    }

    private function makeConfig(string $githubRepo): Configuration
    {
        return new Configuration(
            $this->projectRoot,
            'production',
            [
                'update' => [
                    'github_repo' => $githubRepo,
                    'check_interval_hours' => 24,
                    'timeout_seconds' => 1,
                ],
                'paths' => ['cache' => $this->cacheRoot],
            ],
        );
    }

    /**
     * @param array{latest: string, url: string, published_at: string}|null $returnValue
     */
    private function makeClient(?array $returnValue, string $githubRepo = ''): ReleaseEndpointClient
    {
        return new class($returnValue, $githubRepo) extends ReleaseEndpointClient {
            /**
             * @param array{latest: string, url: string, published_at: string}|null $returnValue
             */
            public function __construct(
                private readonly ?array $returnValue,
                string $githubRepo,
            ) {
                parent::__construct($githubRepo, 1);
            }

            /**
             * @return array{latest: string, url: string, published_at: string}|null
             */
            public function fetchLatest(): ?array
            {
                return $this->returnValue;
            }
        };
    }

    /**
     * @param array{latest: string, url: string, published_at: string}|null $fetchLatestReturn
     * @param array{version: string, published_at: string, download_url: string|null}|null $fetchReleaseByTagReturn
     */
    private function makeClientWithTag(?array $fetchLatestReturn, ?array $fetchReleaseByTagReturn, string $githubRepo = ''): ReleaseEndpointClient
    {
        return new class($fetchLatestReturn, $fetchReleaseByTagReturn, $githubRepo) extends ReleaseEndpointClient {
            public ?string $lastTagFetched = null;

            /**
             * @param array{latest: string, url: string, published_at: string}|null $fetchLatestReturn
             * @param array{version: string, published_at: string, download_url: string|null}|null $fetchReleaseByTagReturn
             */
            public function __construct(
                private readonly ?array $fetchLatestReturn,
                private readonly ?array $fetchReleaseByTagReturn,
                string $githubRepo,
            ) {
                parent::__construct($githubRepo, 1);
            }

            /**
             * @return array{latest: string, url: string, published_at: string}|null
             */
            public function fetchLatest(): ?array
            {
                return $this->fetchLatestReturn;
            }

            /**
             * @return array{version: string, published_at: string, download_url: string|null}|null
             */
            public function fetchReleaseByTag(string $tag): ?array
            {
                $this->lastTagFetched = $tag;
                return $this->fetchReleaseByTagReturn;
            }
        };
    }

    /**
     * @param array{latest: string, url: string, published_at: string}|null $returnValue
     */
    private function makeClientWithoutFetchReleaseByTag(?array $returnValue, string $githubRepo = ''): ReleaseEndpointClient
    {
        return new class($returnValue, $githubRepo) extends ReleaseEndpointClient {
            /**
             * @param array{latest: string, url: string, published_at: string}|null $returnValue
             */
            public function __construct(
                private readonly ?array $returnValue,
                string $githubRepo,
            ) {
                parent::__construct($githubRepo, 1);
            }

            /**
             * @return array{latest: string, url: string, published_at: string}|null
             */
            public function fetchLatest(): ?array
            {
                return $this->returnValue;
            }

            // Intentionally NO fetchReleaseByTag() override.
        };
    }

    private function writeVersion(string $version): void
    {
        file_put_contents($this->projectRoot . '/VERSION', $version . "\n");
    }
}
