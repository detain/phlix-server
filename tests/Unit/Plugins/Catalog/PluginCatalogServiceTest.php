<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Catalog\CatalogFetchException;
use Phlix\Plugins\Catalog\PluginCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Catalog\PluginCatalogService
 */
final class PluginCatalogServiceTest extends TestCase
{
    private const DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';

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

    public function test_add_source_ignores_duplicate_of_default(): void
    {
        $store = [PluginCatalogService::KEY_DEFAULT_SOURCE => self::DEFAULT_SOURCE];
        $service = $this->service($this->settings($store));

        $result = $service->addSource(self::DEFAULT_SOURCE);

        self::assertSame([self::DEFAULT_SOURCE], $result);
        self::assertSame([], $store[PluginCatalogService::KEY_SOURCES] ?? []);
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
}
