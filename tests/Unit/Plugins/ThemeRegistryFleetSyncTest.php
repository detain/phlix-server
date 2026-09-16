<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\ThemeRegistryFleetSync;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * S498 — the guard for the cross-worker flush mechanism itself.
 *
 * AC2: "invalidation mechanism pinned by a guard test". The behaviour pins
 * below prove the stamp contract (what moves it, what must NOT move it) and
 * that the flush lands exactly on the log line carrying the step marker; the
 * wiring pins prove the two production call sites (controller + provider)
 * still hold it. The live pool proof — two resident workers converging over
 * a shared durable table — is
 * {@see \Phlix\Tests\Integration\Theming\CrossWorkerThemeRegistryFleetFlushTest};
 * this file is the unit-grade tripwire for regressions in the mechanism.
 */
final class ThemeRegistryFleetSyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use MockeryExpectationTrait;

    private PluginLoader&MockInterface $loader;

    private StructuredLogger&MockInterface $logger;

    private ThemeSourceRegistry $registry;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        /** @var PluginLoader&MockInterface $loader */
        $loader = Mockery::mock(PluginLoader::class);
        $this->loader = $loader;

        /** @var StructuredLogger&MockInterface $logger */
        $logger = Mockery::mock(StructuredLogger::class);
        foreach (['info', 'warning', 'error'] as $level) {
            $this->expect($logger, $level)->andReturnUsing(
                /** @param array<string, mixed> $context */
                function ($message, array $context = []) use ($level): void {
                    $this->logCalls[] = [
                        'level' => $level,
                        'message' => (string) $message,
                        'context' => $context,
                    ];
                }
            );
        }
        $this->logger = $logger;

        $this->registry = new ThemeSourceRegistry();
    }

    private function sync(): ThemeRegistryFleetSync
    {
        return new ThemeRegistryFleetSync($this->loader, $this->registry, $this->logger);
    }

    private function row(string $name, bool $enabled, string $version = '1.0.0'): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id-' . $name,
            manifest: Manifest::fromArray([
                'name' => $name,
                'version' => $version,
                'phlix_min_server_version' => '0.10.0',
                'type' => 'ui-theme',
                'entry' => 'Phlix\\TestEntry\\' . preg_replace('/[^A-Za-z]/', '', $name),
                'events' => [],
            ]),
            enabled: $enabled,
            installedAt: new DateTimeImmutable(),
            settings: [],
            directory: sys_get_temp_dir() . '/phlix_s498_unit_' . $name,
        );
    }

    private function themeSource(string $sourceName, string $themeId): ThemeSourceInterface
    {
        return new class ($sourceName, $themeId) implements ThemeSourceInterface {
            public function __construct(
                private readonly string $source,
                private readonly string $theme,
            ) {
            }

            public function themeSourceName(): string
            {
                return $this->source;
            }

            /** @return list<array<array-key, mixed>> */
            public function providedThemes(): array
            {
                return [[
                    'id' => $this->theme,
                    'name' => ucfirst($this->theme),
                    'dark' => true,
                    'extends' => null,
                    'tokens' => ['--bg' => '#08070a'],
                ]];
            }
        };
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function logCallsAt(string $level): array
    {
        return array_values(array_filter(
            $this->logCalls,
            static fn (array $c): bool => $c['level'] === $level,
        ));
    }

    // -----------------------------------------------------------------
    // Behaviour pins
    // -----------------------------------------------------------------

    public function test_first_check_adopts_the_baseline_without_rebuilding(): void
    {
        // Boot already wired this worker (bootstrapEnabled); the first theme
        // request must NOT churn the registry just to "confirm" it.
        $this->expect($this->loader, 'listInstalled')
            ->twice()
            ->andReturn([$this->row('phlix-plugin-acme', true)]);
        $this->loader->shouldNotReceive('getEnabled');
        $this->loader->shouldNotReceive('getEntryInstance');

        $this->registry->register($this->themeSource('acme-themes', 'acme-noir'));

        $sync = $this->sync();
        $this->assertFalse($sync->flushIfStale(), 'baseline capture must not report a flush');
        $this->assertFalse($sync->flushIfStale(), '…nor the next identical check');
        $this->assertTrue($this->registry->has('acme-noir'), 'boot-wired state survives untouched');
        $this->assertSame([], $this->logCalls, 'no rebuild, no log line');
    }

    public function test_a_peer_workers_enable_becomes_visible_on_the_next_check(): void
    {
        $enabled = [$this->row('phlix-plugin-acme', true)];
        $this->expect($this->loader, 'listInstalled')
            ->andReturn([], $enabled, $enabled);
        $this->expect($this->loader, 'getEnabled')->andReturn($enabled);
        $this->expect($this->loader, 'getEntryInstance')
            ->with('phlix-plugin-acme')
            ->andReturn($this->themeSource('acme-themes', 'acme-noir'));

        $sync = $this->sync();
        $this->assertFalse($sync->flushIfStale(), 'baseline: nothing installed at boot');
        $this->assertTrue($sync->flushIfStale(), 'peer enable bumped the stamp → rebuild');

        $this->assertSame(['acme-noir'], $this->registry->ids());

        $flushLogs = array_values(array_filter(
            $this->logCalls,
            static fn (array $c): bool => str_contains($c['message'], 'fleet flush'),
        ));
        $this->assertCount(1, $flushLogs, 'exactly one flush log line per rebuild');
        $this->assertSame('info', $flushLogs[0]['level']);
        $this->assertSame(
            ThemeRegistryFleetSync::FLUSH_MARKER,
            $flushLogs[0]['context']['marker'],
            'the flush marker constant must be carried on the rebuild log line',
        );
    }

    public function test_a_stable_stamp_never_rebuilds_twice(): void
    {
        $enabled = [$this->row('phlix-plugin-acme', true)];
        $this->expect($this->loader, 'listInstalled')->andReturn([], $enabled, $enabled, $enabled);
        $this->expect($this->loader, 'getEnabled')->once()->andReturn($enabled);
        $this->expect($this->loader, 'getEntryInstance')->once()->andReturn(
            $this->themeSource('acme-themes', 'acme-noir'),
        );

        $sync = $this->sync();
        $this->assertFalse($sync->flushIfStale());
        $this->assertTrue($sync->flushIfStale());
        $this->assertFalse($sync->flushIfStale(), 'same stamp → no work on the next request');
        $this->assertFalse($sync->flushIfStale(), '…nor the one after');
    }

    public function test_a_peer_workers_disable_or_uninstall_empties_the_source(): void
    {
        $enabled = [$this->row('phlix-plugin-acme', true)];
        // Four checks: empty baseline → enabled (bump) → enabled (no bump) →
        // row gone (bump). A disabled row reaches the same end state: the
        // rebuild reads getEnabled(), so it contributes nothing either way.
        $this->expect($this->loader, 'listInstalled')->andReturn([], $enabled, $enabled, []);
        $this->expect($this->loader, 'getEnabled')->andReturn($enabled, []);
        $this->expect($this->loader, 'getEntryInstance')->once()->andReturn(
            $this->themeSource('acme-themes', 'acme-noir'),
        );

        $sync = $this->sync();
        $this->assertFalse($sync->flushIfStale(), 'baseline');
        $this->assertTrue($sync->flushIfStale(), 'enable bumps');
        $this->assertSame(['acme-noir'], $this->registry->ids());
        $this->assertFalse($sync->flushIfStale(), 'unchanged');
        $this->assertTrue($sync->flushIfStale(), 'disable/uninstall bumps');
        $this->assertSame([], $this->registry->ids(), 'the stale source is gone after the flush');
        $this->assertSame([], $this->registry->sourceNames(), 'and its provenance with it');
    }

    public function test_only_the_columns_lifecycles_write_move_the_stamp(): void
    {
        $this->expect($this->loader, 'listInstalled')
            ->andReturn(
                [$this->row('phlix-plugin-acme', true, '1.0.0')],
                // enabled flipped by a peer disable → must bump.
                [$this->row('phlix-plugin-acme', false, '1.0.0')],
                // version moved (plugin update) → must bump.
                [$this->row('phlix-plugin-acme', false, '1.0.1')],
                // identical row again → must NOT bump.
                [$this->row('phlix-plugin-acme', false, '1.0.1')],
            );
        $this->expect($this->loader, 'getEnabled')->andReturn([]);

        $sync = $this->sync();
        $this->assertFalse($sync->flushIfStale(), 'baseline');
        $this->assertTrue($sync->flushIfStale(), 'enable→disable bumps');
        $this->assertTrue($sync->flushIfStale(), 'version update bumps');
        $this->assertFalse($sync->flushIfStale(), 'unchanged state does not bump');
    }

    public function test_a_refused_payload_poisons_only_its_own_source(): void
    {
        $good = $this->row('phlix-plugin-good', true);
        $evil = $this->row('phlix-plugin-evil', true);
        $this->expect($this->loader, 'listInstalled')->andReturn([], [$good, $evil]);
        $this->expect($this->loader, 'getEnabled')->andReturn([$good, $evil]);
        $this->expect($this->loader, 'getEntryInstance')
            ->with('phlix-plugin-good')
            ->andReturn($this->themeSource('good-themes', 'good-theme'));
        $this->expect($this->loader, 'getEntryInstance')
            ->with('phlix-plugin-evil')
            ->andReturn(new class implements ThemeSourceInterface {
                public function themeSourceName(): string
                {
                    return 'evil-themes';
                }

                /** @return list<array<array-key, mixed>> */
                public function providedThemes(): array
                {
                    return [[
                        'id' => 'evil-theme',
                        'name' => 'Evil',
                        'dark' => true,
                        'extends' => null,
                        'tokens' => ['--bg' => 'url(https://evil.example/beacon.png)'],
                    ]];
                }
            });

        $sync = $this->sync();
        $this->assertFalse($sync->flushIfStale(), 'baseline');
        $this->assertTrue($sync->flushIfStale(), 'rebuild ran');

        $this->assertSame(['good-theme'], $this->registry->ids(), 'the valid source still ships');
        $this->assertCount(1, $this->logCallsAt('error'), 'the refused source is logged, not swallowed');
    }

    public function test_a_broken_entry_instance_is_skipped_without_stalling_the_pool(): void
    {
        $broken = $this->row('phlix-plugin-broken', true);
        $this->expect($this->loader, 'listInstalled')->andReturn([], [$broken]);
        $this->expect($this->loader, 'getEnabled')->andReturn([$broken]);
        $this->expect($this->loader, 'getEntryInstance')->andThrow(
            new PluginNotFoundException('gone mid-loop'),
        );

        $sync = $this->sync();
        $this->assertFalse($sync->flushIfStale(), 'baseline');
        $this->assertTrue($sync->flushIfStale(), 'rebuild ran');

        $this->assertSame([], $this->registry->ids());
        $this->assertCount(1, $this->logCallsAt('warning'), 'the unloadable entry is logged, not fatal');
    }

    // -----------------------------------------------------------------
    // Wiring pins (production call sites)
    // -----------------------------------------------------------------

    public function test_the_marker_is_code_resident_and_carries_the_flush(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Plugins/ThemeRegistryFleetSync.php',
        );

        $this->assertMatchesRegularExpression(
            "/public const FLUSH_MARKER = '[A-Z0-9]+';/",
            $source,
            'the flush marker must live on a code line (constant declaration), not in a comment',
        );
        $this->assertMatchesRegularExpression(
            "/'marker' => self::FLUSH_MARKER,/",
            $source,
            'the rebuild log line must carry the marker constant — dropping that reference is the regression',
        );
    }

    public function test_the_controller_flushes_on_both_theme_routes(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Server/Http/Controllers/ThemesController.php',
        );

        $this->assertSame(
            2,
            preg_match_all('/\$this->fleetSync\?->flushIfStale\(\);/', $source),
            'index() and show() must each flush before serving — a route left unflushed reproduces the '
            . 'W99 flicker (the 200/404 split between /themes and /themes/{id})',
        );
    }

    public function test_the_provider_wires_the_container_scoped_loader_and_registry(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Common/Container/Providers/WebPortalServicesProvider.php',
        );

        $this->assertMatchesRegularExpression(
            '/\$themesPluginLoader = \$c->get\(\\\\Phlix\\\\Plugins\\\\PluginLoader::class\);/',
            $source,
            'the fleet sync must bind to this container\'s own PluginLoader — the instance '
            . 'bootstrapEnabled() wired at onWorkerStart',
        );
        $this->assertMatchesRegularExpression(
            '/new ThemeRegistryFleetSync\(\s*\$themesPluginLoader,\s*\$themeSourceRegistry,/',
            $source,
            'the production ThemesController must be built with a fleet sync bound to that loader and the '
            . 'SAME container-scoped registry it serves from',
        );
    }
}
