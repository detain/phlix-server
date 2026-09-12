<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Catalog\CatalogEntry;
use Phlix\Plugins\Catalog\CatalogFetchException;
use Phlix\Plugins\Catalog\CatalogSourceResolver;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Installer\SourceUrlResolver;
use PHPUnit\Framework\TestCase;

final class PluginCatalogServiceTest extends TestCase
{
    private const DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';

    /** S420 survival marker — a code string literal, not a comment, so it survives `php -w`. */
    private const STEP_MARK = 'S420STABLEINSTX9Q2';

    /** The flagship first-party catalog entry the stable channel must serve. */
    private const SAMPLE_THEME = 'phlix-plugin-sample-theme';

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic, offline SSRF resolution: all catalog test hosts resolve
        // to a public IP so addSource()/fetchCatalog() proceed without real DNS.
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    /**
     * A stateful settings double: `getEffective` reads from `$store`, `set`
     * writes to it, so add/remove round-trip exactly as the DB-backed repo
     * would within a request.
     *
     * @param array<string, mixed> $store
     */
    private function settings(array &$store): SettingsRepository
    {
        $mock = $this->createMock(SettingsRepository::class);
        $mock->method('getEffective')->willReturnCallback(
            // Long closure (not an arrow fn) so $store is captured by reference
            // and reads reflect writes made by the `set` stub below.
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
        );
        $mock->method('set')->willReturnCallback(
            static function (string $key, mixed $value, string $type) use (&$store): void {
                $store[$key] = $value;
            },
        );
        return $mock;
    }

    /**
     * @param array<string, string> $catalogBodies Map of fetched-URL → body.
     */
    private function service(SettingsRepository $settings, array $catalogBodies = []): PluginCatalogService
    {
        return new PluginCatalogService(
            $settings,
            static function (string $url, int $timeout) use ($catalogBodies): string {
                if (!array_key_exists($url, $catalogBodies)) {
                    throw new \RuntimeException('HTTP 404');
                }
                return $catalogBodies[$url];
            },
        );
    }

    public function test_default_source_comes_from_settings(): void
    {
        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        self::assertSame(self::DEFAULT_SOURCE, $this->service($this->settings($store))->defaultSource());
    }

    public function test_default_source_falls_back_when_unset(): void
    {
        $store = [];
        self::assertSame(
            PluginCatalogService::FALLBACK_DEFAULT_SOURCE,
            $this->service($this->settings($store))->defaultSource(),
        );
    }

    public function test_sources_lists_default_then_extras_deduped(): void
    {
        $store = [
            PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE,
            PluginCatalogService::KEY_SOURCES => ['https://example.com/a.json', 'https://example.com/a.json'],
        ];
        $service = $this->service($this->settings($store));

        self::assertSame(
            [self::DEFAULT_SOURCE, 'https://example.com/a.json'],
            $service->sources(),
        );
    }

    public function test_add_source_persists_and_returns_updated_list(): void
    {
        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        $service = $this->service($this->settings($store));

        $result = $service->addSource('https://example.com/extra.json');

        self::assertSame([self::DEFAULT_SOURCE, 'https://example.com/extra.json'], $result);
        self::assertSame(['https://example.com/extra.json'], $store[PluginCatalogService::KEY_SOURCES]);
    }

    public function test_add_source_rejects_the_default_with_a_clear_409(): void
    {
        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        $service = $this->service($this->settings($store));

        // Adding the built-in default now surfaces a clear error (409) instead of
        // silently no-opping and returning 200 with no visible change.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(409);
        $service->addSource(self::DEFAULT_SOURCE);
    }

    public function test_add_source_rejects_a_duplicate_extra_with_409(): void
    {
        $store = [PluginCatalogService::KEY_SOURCES => ['https://example.com/extra.json']];
        $service = $this->service($this->settings($store));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(409);
        $service->addSource('https://example.com/extra.json');
    }

    /**
     * @dataProvider invalidSourceUrls
     */
    public function test_add_source_rejects_non_http_urls(string $url): void
    {
        $store = [];
        $this->expectException(\InvalidArgumentException::class);
        $this->service($this->settings($store))->addSource($url);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidSourceUrls(): array
    {
        return [
            'empty'   => [''],
            'file'    => ['file:///etc/passwd'],
            'no scheme' => ['github.com/detain/phlix-plugins'],
            'ftp'     => ['ftp://example.com/c.json'],
        ];
    }

    public function test_add_source_rejects_private_host_via_ssrf_guard(): void
    {
        SsrfGuard::setResolver(static fn (string $host): array => ['10.0.0.5']);
        $store = [];
        $this->expectException(\InvalidArgumentException::class);
        $this->service($this->settings($store))->addSource('http://internal.example/plugins.json');
    }

    public function test_add_source_rejects_loopback_literal(): void
    {
        $store = [];
        $this->expectException(\InvalidArgumentException::class);
        $this->service($this->settings($store))->addSource('http://127.0.0.1/plugins.json');
    }

    public function test_fetch_catalog_rejects_private_resolved_host(): void
    {
        SsrfGuard::setResolver(static fn (string $host): array => ['169.254.169.254']);
        $store = [];
        $this->expectException(CatalogFetchException::class);
        $this->service($this->settings($store))->fetchCatalog('https://metadata.example/plugins.json');
    }

    public function test_remove_source_drops_the_extra(): void
    {
        $store = [
            PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE,
            PluginCatalogService::KEY_SOURCES => ['https://example.com/a.json', 'https://example.com/b.json'],
        ];
        $service = $this->service($this->settings($store));

        $result = $service->removeSource('https://example.com/a.json');

        self::assertSame([self::DEFAULT_SOURCE, 'https://example.com/b.json'], $result);
        self::assertSame(['https://example.com/b.json'], $store[PluginCatalogService::KEY_SOURCES]);
    }

    public function test_remove_source_cannot_drop_the_default(): void
    {
        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        $service = $this->service($this->settings($store));

        self::assertSame([self::DEFAULT_SOURCE], $service->removeSource(self::DEFAULT_SOURCE));
    }

    public function test_fetch_catalog_parses_plugins(): void
    {
        // SV-S2b: the official catalog resolves to the PINNED ref, not HEAD.
        $rawUrl = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
            . \Phlix\Plugins\Catalog\CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';
        $body = json_encode([
            'schemaVersion' => 1,
            'name' => 'Phlix Official Plugins',
            'plugins' => [
                ['name' => 'phlix-plugin-anidb', 'title' => 'AniDB', 'repo' => 'https://github.com/detain/phlix-plugin-anidb'],
                ['not' => 'valid'], // dropped — no name/repo
            ],
        ], JSON_THROW_ON_ERROR);

        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        $service = $this->service($this->settings($store), [$rawUrl => $body]);

        $catalog = $service->fetchCatalog(self::DEFAULT_SOURCE);

        self::assertSame(self::DEFAULT_SOURCE, $catalog['source']);
        self::assertSame('Phlix Official Plugins', $catalog['name']);
        self::assertCount(1, $catalog['plugins']);
        self::assertSame('phlix-plugin-anidb', $catalog['plugins'][0]->name);
    }

    public function test_channel_defaults_to_stable(): void
    {
        $store = [];
        self::assertSame('stable', $this->service($this->settings($store))->channel());
    }

    public function test_channel_reads_the_dev_setting(): void
    {
        $store = [PluginCatalogService::KEY_CHANNEL => 'dev'];
        self::assertSame('dev', $this->service($this->settings($store))->channel());
    }

    public function test_channel_is_fail_safe_for_unknown_values(): void
    {
        // A typo or stale value must never silently promote to `dev`.
        $store = [PluginCatalogService::KEY_CHANNEL => 'garbage'];
        self::assertSame('stable', $this->service($this->settings($store))->channel());
    }

    public function test_set_channel_persists_normalized_value(): void
    {
        $store = [];
        $service = $this->service($this->settings($store));

        $service->setChannel('DEV'); // case-insensitive
        self::assertSame('dev', $store[PluginCatalogService::KEY_CHANNEL]);
        self::assertSame('dev', $service->channel());

        $service->setChannel('nonsense'); // anything but dev → stable
        self::assertSame('stable', $store[PluginCatalogService::KEY_CHANNEL]);
        self::assertSame('stable', $service->channel());
    }

    /**
     * S27: on the `dev` channel the OFFICIAL catalog is fetched from the moving
     * `master` branch instead of the pinned release tag.
     */
    public function test_dev_channel_fetches_official_catalog_from_master(): void
    {
        $masterRaw = 'https://raw.githubusercontent.com/detain/phlix-plugins/master/plugins.json';
        $body = json_encode([
            'name' => 'Phlix Official Plugins (dev)',
            'plugins' => [
                ['name' => 'phlix-plugin-anidb', 'repo' => 'https://github.com/detain/phlix-plugin-anidb'],
            ],
        ], JSON_THROW_ON_ERROR);

        $store = [
            PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE,
            PluginCatalogService::KEY_CHANNEL => 'dev',
        ];
        $service = $this->service($this->settings($store), [$masterRaw => $body]);

        $catalog = $service->fetchCatalog(self::DEFAULT_SOURCE);

        self::assertSame('Phlix Official Plugins (dev)', $catalog['name']);
        self::assertCount(1, $catalog['plugins']);
    }

    public function test_channel_info_flags_dev_as_advanced_opt_in(): void
    {
        $store = [];
        $info = $this->service($this->settings($store))->channelInfo();

        self::assertSame('stable', $info['channel']);
        $byValue = [];
        foreach ($info['options'] as $option) {
            $byValue[$option['value']] = $option;
        }
        self::assertArrayHasKey('stable', $byValue);
        self::assertArrayHasKey('dev', $byValue);
        self::assertFalse($byValue['stable']['advanced']);
        self::assertTrue($byValue['dev']['advanced']);
        self::assertStringContainsStringIgnoringCase('opt-in', $byValue['dev']['description']);
    }

    public function test_fetch_catalog_throws_on_transport_failure(): void
    {
        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        $service = $this->service($this->settings($store), []); // empty → fetcher throws

        $this->expectException(CatalogFetchException::class);
        $service->fetchCatalog(self::DEFAULT_SOURCE);
    }

    public function test_fetch_catalog_throws_on_non_json(): void
    {
        $rawUrl = 'https://example.com/c.json';
        $store = [];
        $service = $this->service($this->settings($store), [$rawUrl => 'not json']);

        $this->expectException(CatalogFetchException::class);
        $service->fetchCatalog($rawUrl);
    }

    public function test_fetch_catalog_throws_when_plugins_key_missing(): void
    {
        $rawUrl = 'https://example.com/c.json';
        $store = [];
        $service = $this->service($this->settings($store), [$rawUrl => json_encode(['name' => 'x'], JSON_THROW_ON_ERROR)]);

        $this->expectException(CatalogFetchException::class);
        $service->fetchCatalog($rawUrl);
    }

    public function test_aggregate_collects_catalogs_and_per_source_errors(): void
    {
        $defaultRaw = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
            . \Phlix\Plugins\Catalog\CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';
        $okBody = json_encode([
            'name' => 'Official',
            'plugins' => [['name' => 'phlix-plugin-anidb', 'repo' => 'https://github.com/detain/phlix-plugin-anidb']],
        ], JSON_THROW_ON_ERROR);

        $store = [
            PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE,
            // The extra source has no body in the fetcher map → it errors.
            PluginCatalogService::KEY_SOURCES => ['https://example.com/down.json'],
        ];
        $service = $this->service($this->settings($store), [$defaultRaw => $okBody]);

        $result = $service->aggregate();

        self::assertSame(
            [self::DEFAULT_SOURCE, 'https://example.com/down.json'],
            $result['sources'],
        );
        self::assertCount(1, $result['catalogs']);
        self::assertSame('Official', $result['catalogs'][0]['name']);
        self::assertCount(1, $result['errors']);
        self::assertSame('https://example.com/down.json', $result['errors'][0]['source']);
    }

    /**
     * With the real `plugins.json` (schemaVersion 2, four verified, pinned
     * entries) served by an injected fetcher, aggregate() yields a catalog with
     * all four plugins, no errors, and the default source is always listed.
     */
    public function test_aggregate_parses_the_real_official_catalog_fixture(): void
    {
        $defaultRaw = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
            . \Phlix\Plugins\Catalog\CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';

        // Representative fixture copied from https://github.com/detain/phlix-plugins
        // plugins.json: schemaVersion 2, four verified entries each with a
        // 40-hex `ref` and 64-hex `artifactSha256`.
        $body = json_encode([
            'schemaVersion' => 2,
            'name' => 'Phlix Official Plugins',
            'description' => 'The default plugin catalog for Phlix.',
            'homepage' => self::DEFAULT_SOURCE,
            'plugins' => [
                [
                    'name' => 'phlix-plugin-anidb',
                    'title' => 'AniDB',
                    'type' => 'metadata-provider',
                    'repo' => 'https://github.com/detain/phlix-plugin-anidb',
                    'ref' => '852b472a6aa73b80192af96310fcc789dbcaf8d7',
                    'artifactSha256' => 'e8277e0ed419e4eb02245ad86896025bb6bb8ce90c348d2a7c7ea958724ce08c',
                    'version' => '0.1.0',
                    'verified' => true,
                ],
                [
                    'name' => 'phlix-plugin-lastfm',
                    'title' => 'Last.fm',
                    'type' => 'scrobbler',
                    'repo' => 'https://github.com/detain/phlix-plugin-lastfm',
                    'ref' => '43e1e7d2ce5108250a8c95a10e31ee86ba14e412',
                    'artifactSha256' => '5ef58eb42ec7e77fd454204169886cbafe0a36c60111c525710a0471b0e444c4',
                    'version' => '1.0.0',
                    'verified' => true,
                ],
                [
                    'name' => 'phlix-plugin-myanimelist',
                    'title' => 'MyAnimeList',
                    'type' => 'metadata-provider',
                    'repo' => 'https://github.com/detain/phlix-plugin-myanimelist',
                    'ref' => 'ea51e0e754f79d1bc22091f622d448cd89fb4923',
                    'artifactSha256' => 'c484dca258e68e9698a68b3d93639b6ff0db220cb047ff13ee62b3688f28cf41',
                    'version' => '0.1.0',
                    'verified' => true,
                ],
                [
                    'name' => 'phlix-plugin-trakt',
                    'title' => 'Trakt',
                    'type' => 'scrobbler',
                    'repo' => 'https://github.com/detain/phlix-plugin-trakt',
                    'ref' => 'ecd8b909b59779d12b7b49136bdb248c2ecd2334',
                    'artifactSha256' => 'e5fdb4aa2de67ea019ef194dd098fd05d850cc9d8d1f14c5323c095714d7fb46',
                    'version' => '1.0.0',
                    'verified' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        $service = $this->service($this->settings($store), [$defaultRaw => $body]);

        $result = $service->aggregate();

        // The default catalog is always present in the source list.
        self::assertContains(self::DEFAULT_SOURCE, $result['sources']);
        self::assertContains(self::DEFAULT_SOURCE, $service->sources());

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['catalogs']);

        $plugins = $result['catalogs'][0]['plugins'];
        self::assertCount(4, $plugins);
        self::assertSame(
            ['phlix-plugin-anidb', 'phlix-plugin-lastfm', 'phlix-plugin-myanimelist', 'phlix-plugin-trakt'],
            array_map(static fn ($e): string => $e->name, $plugins),
        );
        foreach ($plugins as $entry) {
            self::assertTrue($entry->verified());
        }
    }

    public function test_default_fetcher_returns_a_callable(): void
    {
        // The default fetcher routes through the async Workerman client only
        // inside a Swoole coroutine (getCid() > 0), and falls back to blocking
        // native cURL otherwise (SWOOLE_HOOK_NATIVE_CURL is excluded from the
        // runtime hook mask, so cURL is used only as a synchronous fallback).
        // Here we assert only that the factory yields the expected callable
        // signature. Its network behaviour is exercised by the @group network
        // test below, which the default suite excludes.
        self::assertInstanceOf(\Closure::class, PluginCatalogService::defaultFetcher());
    }

    /**
     * SV-4.11: outside a Swoole coroutine (getCid() == 0 — the common case for
     * the plugin auto-update worker or a plain HTTP handler) defaultFetcher must
     * take the synchronous curlFetch branch and NEVER construct a
     * Swoole\Coroutine\Channel. Proven by the error shape: a cURL transport
     * error, NOT the async "async fetch timed out" that a false Channel::pop()
     * would raise (the S-F12 / SV-0.4 defect this step fixes).
     *
     * @group network
     */
    public function test_default_fetcher_uses_blocking_curl_branch_outside_coroutine(): void
    {
        self::assertFalse(
            \Phlix\Common\Runtime\WorkerContext::inCoroutine(),
            'the test main stack must not be inside a coroutine',
        );

        $fetcher = PluginCatalogService::defaultFetcher();
        try {
            // Unroutable TEST-NET-1 host; short timeout keeps it bounded.
            // Excluded from the default suite via @group network in phpunit.xml.
            $fetcher('https://192.0.2.1/plugins.json', 1);
            self::fail('expected a RuntimeException from the blocking cURL branch');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString(
                'async fetch',
                $e->getMessage(),
                'outside a coroutine the async Channel path must not be taken',
            );
        }
    }

    /**
     * SV-4.11: inside a Swoole coroutine (getCid() > 0) asyncFetch() waits on a
     * Swoole\Coroutine\Channel that is woken by the client's async success
     * callback, returning the response body. Mirrors the production callback:
     * `$channel->push(true)` on completion, `$channel->pop($timeout)` to wait.
     *
     * @requires extension swoole
     */
    public function test_async_fetch_wakes_on_success_callback_inside_coroutine(): void
    {
        if (! \extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the coroutine async-fetch test.');
        }

        $client = $this->createMock(\Workerman\Http\Client::class);
        $client->method('request')->willReturnCallback(
            static function (string $url, array $options): void {
                // Fire the async success handler synchronously from within the
                // coroutine, exactly as the event loop would on response.
                $options['success'](new \Workerman\Psr7\Response(200, [], 'PLUGINS-OK'));
            },
        );

        $body = null;
        $error = null;
        \Swoole\Coroutine\run(static function () use ($client, &$body, &$error): void {
            $method = new \ReflectionMethod(PluginCatalogService::class, 'asyncFetch');
            $method->setAccessible(true);
            try {
                $body = $method->invoke(null, $client, 'https://example.com/plugins.json', 2);
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        self::assertNull($error, 'async fetch must not error when the success callback fires');
        self::assertSame('PLUGINS-OK', $body, 'async fetch must return the pushed response body');
    }

    /**
     * SV-4.11: inside a coroutine, when no callback ever fires, asyncFetch()
     * yields a CLEAN timeout — it actually waits the timeout window and then
     * throws "async fetch timed out", as opposed to the spurious IMMEDIATE
     * false a Channel::pop() outside a coroutine would return (S-F12 / SV-0.4).
     *
     * @requires extension swoole
     */
    public function test_async_fetch_times_out_cleanly_inside_coroutine(): void
    {
        if (! \extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the coroutine async-fetch test.');
        }

        // Default mock: request() fires no callback, so the Channel is never pushed.
        $client = $this->createMock(\Workerman\Http\Client::class);

        $error = null;
        $elapsedMs = null;
        \Swoole\Coroutine\run(static function () use ($client, &$error, &$elapsedMs): void {
            $method = new \ReflectionMethod(PluginCatalogService::class, 'asyncFetch');
            $method->setAccessible(true);
            $start = hrtime(true);
            try {
                $method->invoke(null, $client, 'https://example.com/plugins.json', 1);
            } catch (\Throwable $e) {
                $error = $e;
            }
            $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
        });

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('async fetch timed out', $error->getMessage());
        self::assertNotNull($elapsedMs);
        self::assertGreaterThanOrEqual(
            900.0,
            $elapsedMs,
            'a clean in-coroutine timeout must wait the window, not return immediately (the false-timeout bug).',
        );
    }

    /**
     * S420 — the stable channel must ACTUALLY serve a verified install target
     * with ZERO env overrides and ZERO catalog settings.
     *
     * ## The defect this closes
     * The pin sat at v2.3.0 — a tag that PREDATES the sample-theme entry commit
     * (282c5b3). Every offline mechanism stayed green (isolated live install →
     * enable → index()=5 themes; catalog digest byte-identical) while a real
     * default-channel install on the real network was REFUSED, because the
     * pinned plugins.json simply did not contain the entry.
     *
     * ## What this asserts, over the network production itself uses
     *  1. With `PHLIX_PLUGINS_CATALOG_REF` unset and no channel setting, the
     *     stable channel resolves to {@see CatalogSourceResolver::OFFICIAL_PINNED_REF}
     *     — an immutable release tag, never the moving `master`;
     *  2. the catalog read from that tag ships the flagship
     *     `phlix-plugin-sample-theme` **release entry v1.0.0, verified:true**,
     *     with a full-SHA ref and a 64-hex artifact digest;
     *  3. the service's own install-pin path (`pinFor()`) agrees with the entry;
     *  4. re-hashing the artifact through the installer's own
     *     {@see SourceUrlResolver} repo→`/archive/<ref>.tar.gz` rewrite — i.e.
     *     codeload bytes, the estate's canonical hash source (api.github.com
     *     tarballs are DIFFERENT bytes) — reproduces the catalog digest exactly.
     *
     * Planted-regression proof: re-pin the constant to v2.3.0 and claim (2)
     * reddens — the entry is absent from that tag's catalog. This mirrors the
     * blocking 'Plugin manifest schema' gate in phlix-plugins'
     * validate-catalog.yml (hard-fail fetch, no vacuous skips).
     *
     * Marked with the repo's convention for runtime live-fetch tests
     * (`@group network`): phpunit.xml excludes the group from the default
     * suite so no uncontrolled remote sits on the load-bearing critical path
     * (the S308/S309 lesson). Run explicitly: --group network.
     *
     * @group network
     */
    public function test_stable_channel_at_pinned_tag_serves_verified_sample_theme_install(): void
    {
        // Zero env overrides: the operator escape hatch must be ABSENT for the
        // whole resolution, and restored exactly as found afterwards.
        $overrideKey = CatalogSourceResolver::PINNED_REF_ENV;
        $savedOverride = getenv($overrideKey);
        putenv($overrideKey);

        try {
            // (1) Default-channel resolution: no env, stable channel → audited pin.
            self::assertFalse(
                getenv($overrideKey),
                'the pinned-ref env override must be absent for this test. ' . self::STEP_MARK
            );
            $channelRef = CatalogSourceResolver::refForChannel(CatalogSourceResolver::CHANNEL_STABLE);
            self::assertNull(
                $channelRef,
                'the stable channel carries no moving ref of its own — the pin IS the answer. ' . self::STEP_MARK
            );
            $pinned = CatalogSourceResolver::officialPinnedRef($channelRef);
            self::assertSame(
                CatalogSourceResolver::OFFICIAL_PINNED_REF,
                $pinned,
                'with zero overrides the stable channel must land on the audited pin. ' . self::STEP_MARK
            );
            self::assertNotSame(
                CatalogSourceResolver::DEV_REF,
                $pinned,
                'the stable pin must never be the moving default branch. ' . self::STEP_MARK
            );
            self::assertSame(
                1,
                preg_match('/^v\d+\.\d+\.\d+$/', $pinned),
                "the stable pin must be an immutable release tag (got {$pinned}). " . self::STEP_MARK
            );
            self::assertSame(
                sprintf(
                    'https://raw.githubusercontent.com/%s/%s/%s/%s',
                    CatalogSourceResolver::OFFICIAL_OWNER,
                    CatalogSourceResolver::OFFICIAL_REPO,
                    $pinned,
                    CatalogSourceResolver::CATALOG_FILE,
                ),
                CatalogSourceResolver::normalize(self::DEFAULT_SOURCE),
                'the bare official repo URL must resolve to the pinned tag, not a branch. ' . self::STEP_MARK
            );

            // (2) The real service on the real network: production defaults
            // (empty settings store) + the production fetcher.
            $store = [];
            $service = new PluginCatalogService($this->settings($store), PluginCatalogService::defaultFetcher());
            $catalog = $service->fetchCatalog($service->defaultSource());

            $release = null;
            foreach ($catalog['plugins'] as $entry) {
                if ($entry->name === self::SAMPLE_THEME && $entry->version === '1.0.0') {
                    $release = $entry;
                    break;
                }
            }

            if (!$release instanceof CatalogEntry) {
                self::fail(sprintf(
                    'the official catalog at pin %s carries no %s v1.0.0 release entry — a default-channel '
                    . 'install of the flagship plugin is REFUSED (this is the S420 defect). %s',
                    $pinned,
                    self::SAMPLE_THEME,
                    self::STEP_MARK,
                ));
            }

            self::assertTrue(
                $release->verified(),
                'the flagship release entry must be verified:true — an unverified entry keeps '
                    . 'default-deny at install time. ' . self::STEP_MARK
            );
            self::assertSame(
                1,
                preg_match('/^[0-9a-f]{40}$/', $release->ref),
                'the release entry must pin a full 40-hex commit: ' . $release->ref . '. ' . self::STEP_MARK
            );
            self::assertSame(
                1,
                preg_match('/^[0-9a-f]{64}$/', $release->artifactSha256),
                'the release entry must carry a 64-hex artifact digest: ' . $release->artifactSha256 . '. '
                    . self::STEP_MARK
            );

            // (3) The service's own install-pin path agrees with the entry.
            [$pinSha, $pinRef] = $service->pinFor(self::SAMPLE_THEME);
            self::assertSame(
                $release->artifactSha256,
                $pinSha,
                'pinFor() and the fetched entry disagree on the artifact digest. ' . self::STEP_MARK
            );
            self::assertSame(
                $release->ref,
                $pinRef,
                'pinFor() and the fetched entry disagree on the pinned ref. ' . self::STEP_MARK
            );

            // (4) Codeload re-hash: the exact URL HttpInstaller would fetch,
            // fetched with the exact fetcher the catalog path uses.
            $artifactUrl = SourceUrlResolver::normalize($release->repo, $release->ref);
            self::assertSame(
                rtrim($release->repo, '/') . '/archive/' . $release->ref . '.tar.gz',
                $artifactUrl,
                'the pinned ref must rewrite to the codeload-shaped archive URL the installer hashes. '
                    . self::STEP_MARK
            );
            $fetcher = PluginCatalogService::defaultFetcher();
            $artifactBytes = $fetcher($artifactUrl, 60);
            self::assertSame(
                $release->artifactSha256,
                hash('sha256', $artifactBytes),
                sprintf(
                    'the codeload artifact at ref %s no longer hashes to the digest the catalog claims at '
                    . 'pin %s — the pin is not byte-audited. ',
                    $release->ref,
                    $pinned,
                ) . self::STEP_MARK
            );
        } finally {
            if ($savedOverride === false) {
                putenv($overrideKey);
            } else {
                putenv($overrideKey . '=' . $savedOverride);
            }
        }
    }
}
