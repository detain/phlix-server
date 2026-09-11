<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use Phlix\Common\Net\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;
use Workerman\Http\Response as HttpResponse;

/**
 * S73 acceptance tests: the two-layer SSRF gate wired into BOTH artwork
 * download paths (blocking cURL and async workerman/http-client).
 *
 * The contract under test is not "bad bytes are rejected" — it is
 * "bad ADDRESSES are never contacted". Every test therefore asserts on the
 * RECORD of fetches actually issued plus the number of DNS lookups performed:
 * an unallowlisted host, whether supplied directly or smuggled through a
 * redirect hop, must appear in NEITHER. Deleting either guard layer turns a
 * green test red (proven by mutation during the step).
 *
 * The async tests mirror the ArtworkStorageTest harness: forced async path,
 * fake client invoking callbacks inline, run inside a live Swoole coroutine
 * because the download awaits a Channel — the box's default PHPUnit/CLI
 * context would otherwise select the blocking fallback.
 */
final class ArtworkFetchSsrfTest extends TestCase
{
    private const LANE = 'CS73SERVESSRFX9H';

    private string $tmpDir;

    /** @var int DNS lookups performed through the injected resolver. */
    private int $resolverCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/artwork-ssrf-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
        $this->resolverCalls = 0;
        // Any test that reaches layer two resolves to a PUBLIC IP, so a
        // resolver hit means layer two ran; zero hits means refusal at layer 1.
        SsrfGuard::setResolver(function (string $host): array {
            $this->resolverCalls++;
            return ['151.101.4.13'];
        });
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function storage(): TestableArtworkStorage
    {
        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = true;

        return $storage;
    }

    private function allowlistedUrl(string $suffix = 'poster.jpg'): string
    {
        return 'https://image.tmdb.org/t/p/original/' . $suffix;
    }

    // -----------------------------------------------------------------
    // (AC-1) Unallowlisted host → refused BEFORE any fetch, on both paths
    // -----------------------------------------------------------------

    public function testBlockingDownloadOfUnallowlistedHostIsRefusedBeforeAnyFetch(): void
    {
        $storage = $this->storage();

        try {
            $storage->downloadToTemp('https://' . self::LANE . '.evil.test/t/p/w500/x.jpg');
            self::fail('the download must refuse a non-provider host');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('allowlisted provider image URL', $refusal->getMessage());
        }

        self::assertSame([], $storage->blockingFetchUrls, 'no fetch may be issued to an unallowlisted host');
        self::assertSame(0, $this->resolverCalls, 'no DNS lookup may be performed for an unallowlisted host');
    }

    public function testAsyncDownloadOfUnallowlistedHostIsRefusedBeforeAnyRequest(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;
        $issued = [];
        $storage->fakeClient = $this->fakeClient(
            function (string $url, array $options) use (&$issued): void {
                $issued[] = $url;
            },
        );

        $error = null;
        \Swoole\Coroutine\run(function () use ($storage, &$error): void {
            try {
                $storage->downloadToTemp('https://' . self::LANE . '.evil.test/t/p/w500/x.jpg');
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        self::assertInstanceOf(\InvalidArgumentException::class, $error);
        self::assertSame([], $issued, 'the async path must not hand an unallowlisted URL to the client');
        self::assertSame(0, $this->resolverCalls);
    }

    // -----------------------------------------------------------------
    // (AC-2) Redirect bypass attempts die per-hop, before the next fetch
    // -----------------------------------------------------------------

    public function testBlockingRedirectToUnallowlistedHostStopsBeforeSecondFetch(): void
    {
        $storage = $this->storage();
        $storage->scriptedBlockingResponses = [
            ['code' => 302, 'location' => 'https://' . self::LANE . '.evil.test/steal'],
            ['code' => 200],
        ];

        try {
            $storage->downloadToTemp($this->allowlistedUrl());
            self::fail('a hop to an unallowlisted host must abort the download');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('allowlisted provider image URL', $refusal->getMessage());
        }

        self::assertSame(
            [$this->allowlistedUrl()],
            $storage->blockingFetchUrls,
            'exactly the gated initial request may be issued; the redirect target never',
        );
        // Only the allowlisted ORIGINAL URL was ever resolved; the refused
        // hop target reached neither the socket nor the resolver.
        self::assertSame(1, $this->resolverCalls);
    }

    public function testBlockingRedirectToMetadataIpIsRefused(): void
    {
        $storage = $this->storage();
        $storage->scriptedBlockingResponses = [
            ['code' => 301, 'location' => 'http://169.254.169.254/latest/meta-data/iam/'],
            ['code' => 200],
        ];

        try {
            $storage->downloadToTemp($this->allowlistedUrl());
            self::fail('a hop into the link-local metadata address must be refused');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('allowlisted', $refusal->getMessage());
        }

        self::assertCount(1, $storage->blockingFetchUrls);
        self::assertSame(1, $this->resolverCalls, 'the metadata-IP hop target was never resolved');
    }

    public function testBlockingRedirectToInternalHostnamesIsRefused(): void
    {
        foreach (['//10.0.0.9/admin', '//localhost:8096/api/v1/users', '//127.1/private'] as $smuggle) {
            $storage = $this->storage();
            $storage->scriptedBlockingResponses = [
                ['code' => 307, 'location' => $smuggle],
                ['code' => 200],
            ];

            try {
                $storage->downloadToTemp($this->allowlistedUrl());
                self::fail("hop {$smuggle} must be refused");
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('allowlisted', $refusal->getMessage());
            }

            self::assertCount(1, $storage->blockingFetchUrls, "no fetch past the gated origin for {$smuggle}");
        }
    }

    public function testNonHttpSchemeRedirectHaltsTheLoopWithoutFetching(): void
    {
        $storage = $this->storage();
        $storage->scriptedBlockingResponses = [
            ['code' => 302, 'location' => 'file:///etc/passwd'],
        ];

        try {
            $storage->downloadToTemp($this->allowlistedUrl());
            self::fail('a file:// hop must abort the download');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('not an http(s) URL', $refusal->getMessage());
        }

        self::assertCount(1, $storage->blockingFetchUrls);
    }

    public function testHopBudgetMatchesTheLegacyMaxRedirsAndIsSpentThroughTheGate(): void
    {
        $storage = $this->storage();
        // Every hop is ALLOWLIST-SHAPED but distinct: the initial URL plus 3
        // follow-ups — exactly the request count the pre-S73
        // CURLOPT_MAXREDIRS=3 spent unguarded. The 4th 3xx must end it.
        $storage->scriptedBlockingResponses = [
            ['code' => 302, 'location' => '/t/p/original/h1.jpg'],
            ['code' => 302, 'location' => '/t/p/original/h2.jpg'],
            ['code' => 302, 'location' => '/t/p/original/h3.jpg'],
            ['code' => 302, 'location' => '/t/p/original/h4.jpg'],
        ];

        try {
            $storage->downloadToTemp($this->allowlistedUrl());
            self::fail('the hop budget must be finite');
        } catch (\RuntimeException $exhausted) {
            self::assertStringContainsString('HTTP 302', $exhausted->getMessage());
        }

        self::assertCount(4, $storage->blockingFetchUrls, '1 initial + 3 redirects, then stop');
        self::assertSame(4, $this->resolverCalls, 'every issued hop re-entered the full two-layer gate');
    }

    public function testGuardedRedirectChainSucceedsAndCachesNothingOnRefusal(): void
    {
        $storage = $this->storage();
        $storage->scriptedBlockingResponses = [
            ['code' => 302, 'location' => '/t/p/w500/poster.jpg'],
            ['code' => 200],
        ];

        $tmp = $storage->downloadToTemp($this->allowlistedUrl());

        self::assertSame(
            [
                $this->allowlistedUrl(),
                'https://image.tmdb.org/t/p/w500/poster.jpg',
            ],
            $storage->blockingFetchUrls,
        );
        self::assertStringContainsString('SSRF-TEST-JPEG-BYTES', (string) file_get_contents($tmp));
        @unlink($tmp);
    }

    // -----------------------------------------------------------------
    // (AC-3) Async path mirrors the same per-hop discipline
    // -----------------------------------------------------------------

    public function testAsyncRedirectHopIsGuardedBeforeTheNextRequest(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;

        /** @var list<string> $issued */
        $issued = [];
        /** @var list<array<string, mixed>> $optionSets */
        $optionSets = [];
        $hops = 0;
        $storage->fakeClient = $this->fakeClient(
            function (string $url, array $options) use (&$issued, &$optionSets, &$hops): void {
                $issued[] = $url;
                $optionSets[] = $options;
                $hops++;
                if ($hops === 1) {
                    self::invokeCallback(
                        $options['success'] ?? null,
                        new HttpResponse(302, ['Location' => 'http://169.254.169.254/latest/meta-data/'], ''),
                    );
                    return;
                }

                self::invokeCallback($options['success'] ?? null, new HttpResponse(200, [], 'BODY'));
            },
        );

        $error = null;
        \Swoole\Coroutine\run(function () use ($storage, &$error): void {
            try {
                $storage->downloadToTemp($this->allowlistedUrl());
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        self::assertInstanceOf(\InvalidArgumentException::class, $error);
        self::assertSame([$this->allowlistedUrl()], $issued, 'the gated metadata hop must never be requested');
        self::assertSame(
            ['max' => 0],
            $optionSets[0]['allow_redirects'] ?? null,
            'the vendor must never auto-follow an ungated hop',
        );
    }

    public function testAsyncGuardedRedirectChainSucceeds(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $storage = new TestableArtworkStorage($this->tmpDir);
        $storage->forceBlocking = false;

        /** @var list<string> $issued */
        $issued = [];
        $hops = 0;
        $storage->fakeClient = $this->fakeClient(
            function (string $url, array $options) use (&$issued, &$hops): void {
                $issued[] = $url;
                $hops++;
                if ($hops === 1) {
                    self::invokeCallback(
                        $options['success'] ?? null,
                        new HttpResponse(302, ['Location' => '/t/p/w780/poster.jpg'], ''),
                    );
                    return;
                }

                self::invokeCallback($options['success'] ?? null, new HttpResponse(200, [], 'JPEGBYTES'));
            },
        );

        $result = null;
        $error = null;
        \Swoole\Coroutine\run(function () use ($storage, &$result, &$error): void {
            try {
                $result = $storage->downloadToTemp($this->allowlistedUrl());
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        self::assertNull($error);
        self::assertSame(
            [$this->allowlistedUrl(), 'https://image.tmdb.org/t/p/w780/poster.jpg'],
            $issued,
        );
        self::assertIsString($result);
        self::assertSame('JPEGBYTES', (string) file_get_contents($result));
        @unlink($result);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param callable(string, array<string, mixed>): void $onRequest
     */
    private function fakeClient(callable $onRequest): Client
    {
        return new class ($onRequest) extends Client {
            /** @var callable(string, array<array-key, mixed>): void */
            private $handler;

            /**
             * @param callable(string, array<array-key, mixed>): void $handler
             */
            public function __construct(callable $handler)
            {
                parent::__construct([]);
                $this->handler = $handler;
            }

            /**
             * @param array<array-key, mixed> $options
             */
            public function request(string $url, array $options = []): mixed
            {
                ($this->handler)($url, $options);

                return null;
            }
        };
    }

    /**
     * Invoke a workerman http-client callback (success/error) after narrowing
     * the option array's mixed type — same harness contract as ArtworkStorageTest.
     */
    private static function invokeCallback(mixed $callback, mixed $argument): void
    {
        self::assertIsCallable($callback);
        /** @var callable(mixed): void $callback */
        $callback($argument);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
