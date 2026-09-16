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

    public function test_a_cold_worker_reconciles_durable_truth_on_its_first_request(): void
    {
        // S499 AC1 — pins the exact path batch48 F1 found unpinned: a worker
        // whose boot wiring (`bootstrapEnabled()`) reflects an OLDER generation
        // than the durable table, because a peer lifecycle mutation landed
        // before this worker's first theme request. The pre-populated FOREIGN
        // registry below is that stale boot generation. The first served call
        // MUST reconcile from durable truth, then adopt the stamp — it must NOT
        // blindly adopt the foreign-current stamp (S498's original baseline
        // capture) and then serve the stale generation stamp-equal forever.
        $durable = [$this->row('phlix-plugin-acme', true)];
        $this->expect($this->loader, 'listInstalled')->zeroOrMoreTimes()->andReturn($durable);
        $this->expect($this->loader, 'getEnabled')->zeroOrMoreTimes()->andReturn($durable);
        $this->expect($this->loader, 'getEntryInstance')
            ->zeroOrMoreTimes()
            ->with('phlix-plugin-acme')
            ->andReturn($this->themeSource('acme-themes', 'acme-noir'));

        // Stale boot state: a theme from a generation the durable set no longer
        // has. Under the fix the rebuild clears it; register()-idempotency (this
        // path clears first, then adds each enabled source once) makes the extra
        // boot-time rebuild free of duplicates.
        $this->registry->register($this->themeSource('ghost-themes', 'ghost-noir'));
        $this->assertSame(['ghost-noir'], $this->registry->ids(), 'foreign generation pre-seeded');

        $sync = $this->sync();
        $this->assertTrue(
            $sync->flushIfStale(),
            'a cold worker whose boot registry predates the durable stamp must rebuild on its FIRST request '
            . '(S499 F1: never baseline-capture a foreign-current stamp)',
        );
        $this->assertSame(['acme-noir'], $this->registry->ids(), 'durable truth is now served');
        $this->assertFalse($this->registry->has('ghost-noir'), 'the stale generation is flushed out');

        // And the adopted stamp makes the next identical request do no work.
        $this->assertFalse($sync->flushIfStale(), '…then a stable stamp never rebuilds again');
    }

    public function test_a_peer_workers_enable_becomes_visible_on_the_next_check(): void
    {
        $enabled = [$this->row('phlix-plugin-acme', true)];
        // Boot/durable was empty, then a peer enabled. Three checks: cold
        // reconcile on the empty durable, the enable bump, then a stable no-op.
        $this->expect($this->loader, 'listInstalled')
            ->andReturn([], $enabled, $enabled);
        // getEnabled() is read on BOTH rebuilds (cold + enable); only the enable
        // one has a source to instantiate.
        $this->expect($this->loader, 'getEnabled')->andReturn([], $enabled);
        $this->expect($this->loader, 'getEntryInstance')
            ->with('phlix-plugin-acme')
            ->andReturn($this->themeSource('acme-themes', 'acme-noir'));

        $sync = $this->sync();
        $this->assertTrue($sync->flushIfStale(), 'cold reconcile: rebuilds from the (empty) durable set');
        $this->assertTrue($sync->flushIfStale(), 'peer enable bumped the stamp → rebuild');

        $this->assertSame(['acme-noir'], $this->registry->ids());

        $flushLogs = array_values(array_filter(
            $this->logCalls,
            static fn (array $c): bool => str_contains($c['message'], 'fleet flush'),
        ));
        $this->assertCount(2, $flushLogs, 'one rebuild per reconcile call');
        $this->assertSame('info', $flushLogs[1]['level']);
        $this->assertSame(
            ThemeRegistryFleetSync::FLUSH_MARKER,
            $flushLogs[1]['context']['marker'],
            'the flush marker constant must be carried on the rebuild log line',
        );
        $this->assertSame(
            ThemeRegistryFleetSync::BOOT_PRIME_MARKER,
            $flushLogs[0]['context']['boot_prime'] ?? null,
            'the cold reconcile (first) flush is tagged with the boot-prime marker',
        );
        $this->assertArrayNotHasKey(
            'boot_prime',
            $flushLogs[1]['context'],
            'a later stamp-move flush is NOT a boot prime',
        );
    }

    public function test_a_stable_stamp_never_rebuilds_twice(): void
    {
        $enabled = [$this->row('phlix-plugin-acme', true)];
        // Boot/durable already [acme]; the cold reconcile is the one rebuild,
        // every later check on the unchanged stamp does nothing.
        $this->expect($this->loader, 'listInstalled')->andReturn($enabled, $enabled, $enabled, $enabled);
        $this->expect($this->loader, 'getEnabled')->once()->andReturn($enabled);
        $this->expect($this->loader, 'getEntryInstance')->once()->andReturn(
            $this->themeSource('acme-themes', 'acme-noir'),
        );

        $sync = $this->sync();
        $this->assertTrue($sync->flushIfStale(), 'cold reconcile rebuilds once');
        $this->assertFalse($sync->flushIfStale(), 'same stamp → no work on the next request');
        $this->assertFalse($sync->flushIfStale(), '…nor the one after');
        $this->assertFalse($sync->flushIfStale(), '…nor the one after that');
        $this->assertSame(['acme-noir'], $this->registry->ids());
    }

    public function test_a_peer_workers_disable_or_uninstall_empties_the_source(): void
    {
        $enabled = [$this->row('phlix-plugin-acme', true)];
        // Four checks: cold reconcile on empty → enable (bump) → enabled (no
        // bump) → row gone (bump). A disabled row reaches the same end state:
        // the rebuild reads getEnabled(), so it contributes nothing either way.
        $this->expect($this->loader, 'listInstalled')->andReturn([], $enabled, $enabled, []);
        $this->expect($this->loader, 'getEnabled')->andReturn([], $enabled, []);
        $this->expect($this->loader, 'getEntryInstance')->once()->andReturn(
            $this->themeSource('acme-themes', 'acme-noir'),
        );

        $sync = $this->sync();
        $this->assertTrue($sync->flushIfStale(), 'cold reconcile on empty');
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
                // cold reconcile on this first row; then the discriminating moves.
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
        $this->assertTrue($sync->flushIfStale(), 'cold reconcile (first check)');
        $this->assertTrue($sync->flushIfStale(), 'enable→disable bumps');
        $this->assertTrue($sync->flushIfStale(), 'version update bumps');
        $this->assertFalse($sync->flushIfStale(), 'unchanged state does not bump');
    }

    public function test_a_refused_payload_poisons_only_its_own_source(): void
    {
        $good = $this->row('phlix-plugin-good', true);
        $evil = $this->row('phlix-plugin-evil', true);
        $this->expect($this->loader, 'listInstalled')->andReturn([$good, $evil]);
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
        $this->assertTrue($sync->flushIfStale(), 'cold reconcile runs the rebuild');

        $this->assertSame(['good-theme'], $this->registry->ids(), 'the valid source still ships');
        $this->assertCount(1, $this->logCallsAt('error'), 'the refused source is logged, not swallowed');

        $this->assertFalse($sync->flushIfStale(), '…and a stable stamp does not rebuild again');
    }

    public function test_a_broken_entry_instance_is_skipped_without_stalling_the_pool(): void
    {
        $broken = $this->row('phlix-plugin-broken', true);
        $this->expect($this->loader, 'listInstalled')->andReturn([$broken]);
        $this->expect($this->loader, 'getEnabled')->andReturn([$broken]);
        $this->expect($this->loader, 'getEntryInstance')->andThrow(
            new PluginNotFoundException('gone mid-loop'),
        );

        $sync = $this->sync();
        $this->assertTrue($sync->flushIfStale(), 'cold reconcile runs the rebuild');

        $this->assertSame([], $this->registry->ids());
        $this->assertCount(1, $this->logCallsAt('warning'), 'the unloadable entry is logged, not fatal');

        $this->assertFalse($sync->flushIfStale(), '…then a stable stamp does not rebuild again');
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

        // S499: the boot-prime marker is likewise code-resident and referenced.
        $this->assertMatchesRegularExpression(
            "/public const BOOT_PRIME_MARKER = '[A-Z0-9]+';/",
            $source,
            'the boot-prime marker must live on a code line (constant declaration)',
        );
        $this->assertMatchesRegularExpression(
            "/self::BOOT_PRIME_MARKER/",
            $source,
            'flushIfStale() must tag the cold reconcile with the boot-prime marker — '
            . 'dropping that reference is the S499 regression',
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
