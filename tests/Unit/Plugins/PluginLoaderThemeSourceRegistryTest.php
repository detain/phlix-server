<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginEnableException;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * S84's capability arm, the third of the pattern established by
 * {@see PluginLoaderSourceRegistryTest} (metadata) and
 * {@see PluginLoaderSubtitleSourceRegistryTest} (subtitles).
 *
 * A SAMPLE PLUGIN whose entry class implements {@see ThemeSourceInterface}
 * appears in the {@see ThemeSourceRegistry} after {@see PluginLoader::enable()}
 * and is gone after {@see PluginLoader::disable()}, driven by the typed
 * interface — NOT by a manifest `theme` key or `method_exists()` sniffing.
 *
 * It also pins the arm's one behavioural difference from its two siblings: a
 * theme carrying a CSS-injection payload FAILS THE ENABLE rather than being
 * quietly dropped, and leaves nothing wired behind it.
 */
final class PluginLoaderThemeSourceRegistryTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use MockeryExpectationTrait;

    private HttpInstaller&MockInterface $installer;
    private ComposerRunner&MockInterface $composer;
    private SignatureVerifier&MockInterface $verifier;
    private PluginRepository&MockInterface $repository;
    private ListenerRegistry $listenerRegistry;
    private ContainerInterface&MockInterface $container;
    private AuditLogger&MockInterface $auditLogger;
    private StructuredLogger&MockInterface $logger;
    private ThemeSourceRegistry $themeRegistry;
    private string $stagedDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagedDir = sys_get_temp_dir() . '/phlix_themereg_' . uniqid('', true);
        mkdir($this->stagedDir, 0o775, true);

        /** @var HttpInstaller&MockInterface $installer */
        $installer = Mockery::mock(HttpInstaller::class);
        $this->installer = $installer;
        /** @var ComposerRunner&MockInterface $composer */
        $composer = Mockery::mock(ComposerRunner::class);
        $this->composer = $composer;
        /** @var SignatureVerifier&MockInterface $verifier */
        $verifier = Mockery::mock(SignatureVerifier::class);
        $this->verifier = $verifier;
        /** @var PluginRepository&MockInterface $repository */
        $repository = Mockery::mock(PluginRepository::class);
        $this->repository = $repository;
        /** @var StructuredLogger&MockInterface $registryLogger */
        $registryLogger = Mockery::mock(StructuredLogger::class)->shouldIgnoreMissing();
        $this->listenerRegistry = new ListenerRegistry(null, $registryLogger);
        /** @var ContainerInterface&MockInterface $container */
        $container = Mockery::mock(ContainerInterface::class);
        $this->container = $container;
        /** @var AuditLogger&MockInterface $auditLogger */
        $auditLogger = Mockery::mock(AuditLogger::class)->shouldIgnoreMissing();
        $this->auditLogger = $auditLogger;
        /** @var StructuredLogger&MockInterface $logger */
        $logger = Mockery::mock(StructuredLogger::class)->shouldIgnoreMissing();
        $this->logger = $logger;
        $this->themeRegistry = new ThemeSourceRegistry();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->stagedDir)) {
            @system('rm -rf ' . escapeshellarg($this->stagedDir));
        }
    }

    private function makeLoader(?ThemeSourceRegistry $registry): PluginLoader
    {
        return new PluginLoader(
            $this->installer,
            $this->composer,
            $this->verifier,
            $this->repository,
            $this->listenerRegistry,
            $this->container,
            $this->auditLogger,
            $this->logger,
            null,
            null,
            $registry,
        );
    }

    private function manifest(string $name, string $entry): Manifest
    {
        return Manifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'ui-theme',
            'entry' => $entry,
            'events' => [],
        ]);
    }

    /**
     * Drive the registry's PSR-14 provider by hand so the deactivation shim
     * `ListenerRegistry::unsubscribe()` installs is actually exercised — the
     * provider keeps the listener, so only a dispatch can tell an ACTIVE
     * subscription from an unsubscribed one.
     */
    private function dispatch(object $event): void
    {
        foreach ($this->listenerRegistry->provider()->getListenersForEvent($event) as $listener) {
            $listener($event);
        }
    }

    private function makeInstalled(Manifest $manifest): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id',
            manifest: $manifest,
            enabled: false,
            installedAt: new DateTimeImmutable(),
            settings: [],
            directory: $this->stagedDir,
        );
    }

    public function test_enable_registers_the_sample_plugins_token_map_theme(): void
    {
        $manifest = $this->manifest('phlix-plugin-acme-themes', SampleThemePlugin::class);
        $plugin = new SampleThemePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(SampleThemePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', false)->once();

        $loader = $this->makeLoader($this->themeRegistry);

        $this->assertFalse($this->themeRegistry->has('acme-noir'), 'registry starts empty');

        $loader->enable('phlix-plugin-acme-themes');

        $this->assertTrue($this->themeRegistry->has('acme-noir'), 'theme present after enable');
        $theme = $this->themeRegistry->get('acme-noir');
        $this->assertNotNull($theme);
        $this->assertSame('Acme Noir', $theme->name);
        $this->assertTrue($theme->dark);
        $this->assertSame('acme-themes', $theme->sourceName);
        $this->assertSame(
            ['--bg' => '#08070a', '--surface' => '#12111a', '--accent' => 'rgba(120, 190, 255, 0.95)'],
            $theme->tokens,
        );
        $this->assertCount(1, $this->themeRegistry->all());

        $loader->disable('phlix-plugin-acme-themes');

        $this->assertFalse($this->themeRegistry->has('acme-noir'), 'theme gone after disable');
        $this->assertCount(0, $this->themeRegistry->all(), 'no leak after enable/disable cycle');
        $this->assertSame([], $this->themeRegistry->sourceNames());
    }

    public function test_enable_disable_cycle_repeated_does_not_leak(): void
    {
        $manifest = $this->manifest('phlix-plugin-acme-themes', SampleThemePlugin::class);

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')
            ->with(SampleThemePlugin::class)
            ->andReturnUsing(static fn (): SampleThemePlugin => new SampleThemePlugin());
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', true);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', false);

        $loader = $this->makeLoader($this->themeRegistry);

        for ($i = 0; $i < 5; $i++) {
            $loader->enable('phlix-plugin-acme-themes');
            $this->assertCount(1, $this->themeRegistry->all());
            $loader->disable('phlix-plugin-acme-themes');
            $this->assertCount(0, $this->themeRegistry->all());
        }
    }

    public function test_registration_skipped_when_no_theme_registry_injected(): void
    {
        $manifest = $this->manifest('phlix-plugin-acme-themes', SampleThemePlugin::class);
        $plugin = new SampleThemePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(SampleThemePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', true)->once();

        // No ThemeSourceRegistry — enable must not fatal.
        $this->makeLoader(null)->enable('phlix-plugin-acme-themes');

        $this->assertSame([], $this->themeRegistry->all());
    }

    /**
     * The security path end to end: a plugin whose theme smuggles a `url()`
     * into a token value does not enable, does not register, and — because the
     * refusal happens after the subscribe step — does not stay subscribed.
     */
    public function test_a_css_injection_payload_fails_the_enable_and_wires_nothing(): void
    {
        $manifest = $this->manifest('phlix-plugin-evil-themes', HostileThemePlugin::class);
        $plugin = new HostileThemePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(HostileThemePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->never();

        $loader = $this->makeLoader($this->themeRegistry);

        $caught = null;
        try {
            $loader->enable('phlix-plugin-evil-themes');
        } catch (PluginEnableException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(PluginEnableException::class, $caught, 'the enable must fail');
        $this->assertStringContainsString('provided an invalid theme', $caught->getMessage());
        $this->assertStringContainsString('--bg', $caught->getMessage());

        $this->assertSame([], $this->themeRegistry->all(), 'nothing registered');

        $this->dispatch(new SampleThemeEvent());
        $this->assertSame(
            0,
            $plugin->eventsSeen,
            'a plugin whose enable failed must not be left handling events',
        );
        $this->assertTrue($plugin->onDisableNotCalled, 'sanity: disable() was never reached');
    }

    /**
     * The control for the assertion above: with a VALID theme the very same
     * subscription IS live, so "0 events seen" there is the unwind working and
     * not the dispatch helper being inert.
     */
    public function test_a_successfully_enabled_theme_plugin_is_subscribed(): void
    {
        $manifest = $this->manifest('phlix-plugin-acme-themes', SampleThemePlugin::class);
        $plugin = new SampleThemePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(SampleThemePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', false)->once();

        $loader = $this->makeLoader($this->themeRegistry);
        $loader->enable('phlix-plugin-acme-themes');

        $this->dispatch(new SampleThemeEvent());
        $this->assertSame(1, $plugin->eventsSeen, 'an enabled plugin handles its events');

        $loader->disable('phlix-plugin-acme-themes');
        $this->dispatch(new SampleThemeEvent());
        $this->assertSame(1, $plugin->eventsSeen, 'a disabled plugin stops handling them');
    }

    /**
     * A second enable after the failed one must still work — the failed attempt
     * left no "already enabled in this process" ghost behind.
     */
    public function test_a_plugin_can_be_enabled_after_a_refused_enable(): void
    {
        $manifest = $this->manifest('phlix-plugin-acme-themes', ToggleableThemePlugin::class);
        $plugin = new ToggleableThemePlugin();
        $plugin->hostile = true;

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(ToggleableThemePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-acme-themes', true)->once();

        $loader = $this->makeLoader($this->themeRegistry);

        $refused = null;
        try {
            $loader->enable('phlix-plugin-acme-themes');
        } catch (PluginEnableException $e) {
            $refused = $e;
        }
        $this->assertInstanceOf(PluginEnableException::class, $refused);

        $plugin->hostile = false;
        $loader->enable('phlix-plugin-acme-themes');

        $this->assertTrue($this->themeRegistry->has('acme-fixed'));
    }
}

/**
 * The S84 sample plugin: a lifecycle plugin that is ALSO a typed theme source.
 * This is the exact shape a real `ui-theme` plugin adopts.
 */
final class SampleThemePlugin implements LifecycleInterface, ThemeSourceInterface
{
    public bool $onDisableNotCalled = true;

    public int $eventsSeen = 0;

    public function onEnable(ContainerInterface $container): void
    {
    }

    public function onDisable(): void
    {
        $this->onDisableNotCalled = false;
    }

    /** @return array<class-string, string|callable> */
    public function subscribedEvents(): array
    {
        return [SampleThemeEvent::class => 'onSampleEvent'];
    }

    public function onSampleEvent(object $event): void
    {
        $this->eventsSeen++;
    }

    public function themeSourceName(): string
    {
        return 'acme-themes';
    }

    public function providedThemes(): array
    {
        return [
            [
                'id' => 'acme-noir',
                'name' => 'Acme Noir',
                'dark' => true,
                'extends' => null,
                'tokens' => [
                    '--bg' => '#08070a',
                    '--surface' => '#12111a',
                    '--accent' => 'rgba(120, 190, 255, 0.95)',
                ],
            ],
        ];
    }
}

/**
 * The same shape, but its token value tries to escape the value position.
 */
final class HostileThemePlugin implements LifecycleInterface, ThemeSourceInterface
{
    public bool $onDisableNotCalled = true;

    public int $eventsSeen = 0;

    public function onEnable(ContainerInterface $container): void
    {
    }

    public function onDisable(): void
    {
        $this->onDisableNotCalled = false;
    }

    /** @return array<class-string, string|callable> */
    public function subscribedEvents(): array
    {
        return [SampleThemeEvent::class => 'onSampleEvent'];
    }

    public function onSampleEvent(object $event): void
    {
        $this->eventsSeen++;
    }

    public function themeSourceName(): string
    {
        return 'evil-themes';
    }

    public function providedThemes(): array
    {
        return [
            [
                'id' => 'evil-noir',
                'name' => 'Evil Noir',
                'dark' => true,
                'extends' => null,
                'tokens' => ['--bg' => 'url(https://evil.example/beacon.png)'],
            ],
        ];
    }
}

/**
 * Flips between a hostile and a valid payload so one loader can be driven
 * through a refused enable and then a good one.
 */
final class ToggleableThemePlugin implements LifecycleInterface, ThemeSourceInterface
{
    public bool $hostile = false;

    public function onEnable(ContainerInterface $container): void
    {
    }

    public function onDisable(): void
    {
    }

    /** @return array<class-string, string|callable> */
    public function subscribedEvents(): array
    {
        return [];
    }

    public function themeSourceName(): string
    {
        return 'acme-themes';
    }

    public function providedThemes(): array
    {
        return [
            [
                'id' => 'acme-fixed',
                'name' => 'Acme Fixed',
                'dark' => true,
                'extends' => null,
                'tokens' => ['--bg' => $this->hostile ? '#fff;color:red' : '#08070a'],
            ],
        ];
    }
}

/**
 * A bare event class for the subscribe/unsubscribe assertions.
 */
final class SampleThemeEvent
{
}
