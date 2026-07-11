<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use DI\FactoryInterface;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Plugins\Exception\PluginEnableException;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Phlix\Plugins\PluginLoader
 * @covers \Phlix\Plugins\InstalledPlugin
 * @covers \Phlix\Plugins\Exception\PluginInstallException
 * @covers \Phlix\Plugins\Exception\PluginEnableException
 * @covers \Phlix\Plugins\Exception\PluginNotFoundException
 */
final class PluginLoaderTest extends TestCase
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

    private string $stagedDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagedDir = sys_get_temp_dir() . '/phlix_loadertest_' . uniqid('', true);
        mkdir($this->stagedDir, 0775, true);

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
        $this->listenerRegistry = new ListenerRegistry(
            null,
            $registryLogger,
        );
        /** @var ContainerInterface&MockInterface $container */
        $container = Mockery::mock(ContainerInterface::class);
        $this->container = $container;
        /** @var AuditLogger&MockInterface $auditLogger */
        $auditLogger = Mockery::mock(AuditLogger::class)->shouldIgnoreMissing();
        $this->auditLogger = $auditLogger;
        /** @var StructuredLogger&MockInterface $logger */
        $logger = Mockery::mock(StructuredLogger::class)->shouldIgnoreMissing();
        $this->logger = $logger;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->stagedDir)) {
            @system('rm -rf ' . escapeshellarg($this->stagedDir));
        }
    }

    private function makeLoader(): PluginLoader
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
        );
    }

    private function manifest(string $name = 'phlix-plugin-fixture'): Manifest
    {
        return Manifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => FakeLifecyclePlugin::class,
            'events' => ['phlix.playback.started'],
        ]);
    }

    private function makeInstalled(
        Manifest $manifest,
        bool $enabled = false,
        ?string $directory = null,
    ): InstalledPlugin {
        return new InstalledPlugin(
            id: 'id',
            manifest: $manifest,
            enabled: $enabled,
            installedAt: new DateTimeImmutable(),
            settings: [],
            directory: $directory ?? $this->stagedDir,
        );
    }

    public function test_install_from_directory_persists_manifest_and_returns_it(): void
    {
        $manifest = $this->manifest();

        $this->expect($this->installer, 'installFromDirectory')
            ->once()
            ->with('/path/to/source')
            ->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_UNSIGNED);
        $this->expect($this->composer, 'install')->once()->with($this->stagedDir);
        $this->expect($this->repository, 'existsByName')->andReturn(false);
        $this->expect($this->repository, 'insert')
            ->once()
            ->with(Mockery::on(fn ($m) => $m === $manifest), false, []);

        $loader = $this->makeLoader();
        $returned = $loader->installFromDirectory('/path/to/source');

        $this->assertSame($manifest, $returned);
    }

    public function test_install_rejects_invalid_manifest_with_install_exception(): void
    {
        $this->expect($this->installer, 'installFromDirectory')
            ->andThrow(new PluginInstallException('bad manifest', []));
        $this->composer->shouldNotReceive('install');
        $this->repository->shouldNotReceive('insert');

        $this->expectException(PluginInstallException::class);
        $this->makeLoader()->installFromDirectory('/bad');
    }

    public function test_install_rejects_unsupported_phlix_min_server_version(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-future',
            'version' => '1.0.0',
            'phlix_min_server_version' => '99.99.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePlugin::class,
        ]);

        $this->expect($this->installer, 'installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->composer->shouldNotReceive('install');
        $this->repository->shouldNotReceive('insert');

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('requires Phlix >= 99.99.0');

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_writes_to_var_plugins_subdir_named_after_plugin(): void
    {
        $manifest = $this->manifest('phlix-plugin-bar');

        $this->expect($this->installer, 'installFromDirectory')
            ->once()
            ->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->expect($this->repository, 'existsByName')->andReturn(false);
        $this->expect($this->repository, 'insert')->once();
        $this->expect($this->composer, 'install')->once()->with($this->stagedDir);

        $this->makeLoader()->installFromDirectory('/anywhere');
    }

    public function test_install_logs_warning_for_unsigned_plugins(): void
    {
        $manifest = $this->manifest();

        $this->expect($this->installer, 'installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_UNSIGNED);
        $this->expect($this->composer, 'install')->once();
        $this->expect($this->repository, 'existsByName')->andReturn(false);
        $this->expect($this->repository, 'insert')->once();

        $this->expect($this->logger, 'warning')
            ->atLeast()->once()
            ->with(Mockery::on(fn ($m) => str_contains($m, 'unsigned plugin')), Mockery::any());
        $this->expect($this->logger, 'info')->withAnyArgs();

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_rejects_when_signature_invalid(): void
    {
        $manifest = $this->manifest();

        $this->expect($this->installer, 'installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_INVALID);
        $this->composer->shouldNotReceive('install');

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('signature did not verify');

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_via_url_delegates_to_http_installer_with_pin(): void
    {
        // SV-S1b/SV-S2b: a remote install carries the catalog entry's pinned
        // sha256 + commit ref, which the loader threads down to the installer.
        $manifest = $this->manifest();
        $sha = str_repeat('a', 64);
        $ref = str_repeat('b', 40);
        $this->expect($this->installer, 'install')
            ->once()
            ->with('https://example.test/plugin.tar.gz', $sha, $ref)
            ->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->expect($this->composer, 'install')->once();
        $this->expect($this->repository, 'existsByName')->andReturn(false);
        $this->expect($this->repository, 'insert')->once();

        $returned = $this->makeLoader()->install('https://example.test/plugin.tar.gz', $sha, $ref);
        $this->assertSame($manifest, $returned);
    }

    public function test_install_via_url_default_denies_unpinned_remote_source(): void
    {
        // SV-S2b default-deny: an un-pinned (schemaVersion 1 / third-party)
        // remote source is refused before any bytes are fetched. The installer
        // must never be reached.
        $this->installer->shouldNotReceive('install');
        $this->composer->shouldNotReceive('install');

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('Refusing to install unverified plugin source');

        $this->makeLoader()->install('https://example.test/plugin.tar.gz');
    }

    public function test_install_via_url_unpinned_succeeds_with_override_env(): void
    {
        // With PHLIX_PLUGINS_ALLOW_UNVERIFIED=1 the operator opts back into
        // un-pinned installs (transition window for v1 catalogs).
        $manifest = $this->manifest();
        putenv('PHLIX_PLUGINS_ALLOW_UNVERIFIED=1');
        try {
            $this->expect($this->installer, 'install')
                ->once()
                ->with('https://example.test/plugin.tar.gz', null, null)
                ->andReturn([$manifest, $this->stagedDir]);
            $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
            $this->expect($this->composer, 'install')->once();
            $this->expect($this->repository, 'existsByName')->andReturn(false);
            $this->expect($this->repository, 'insert')->once();

            $returned = $this->makeLoader()->install('https://example.test/plugin.tar.gz');
            $this->assertSame($manifest, $returned);
        } finally {
            putenv('PHLIX_PLUGINS_ALLOW_UNVERIFIED');
        }
    }

    public function test_install_via_file_url_is_exempt_from_default_deny(): void
    {
        // Local file:// sources (dev checkouts / fixtures) are operator-local
        // bytes, not a remote catalog artifact — the supply-chain pin does not
        // apply, so they install un-pinned without the override.
        $manifest = $this->manifest();
        $this->expect($this->installer, 'install')
            ->once()
            ->with('file:///tmp/plugin.tar.gz', null, null)
            ->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->expect($this->composer, 'install')->once();
        $this->expect($this->repository, 'existsByName')->andReturn(false);
        $this->expect($this->repository, 'insert')->once();

        $returned = $this->makeLoader()->install('file:///tmp/plugin.tar.gz');
        $this->assertSame($manifest, $returned);
    }

    public function test_enable_requires_lifecycle_interface_or_throws(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-bad',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => \stdClass::class,
        ]);

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(\stdClass::class)->andReturn(new \stdClass());

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('must implement');

        $this->makeLoader()->enable('phlix-plugin-bad');
    }

    public function test_enable_subscribes_each_declared_event_to_listener_registry(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakeLifecyclePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once()->with('phlix-plugin-fixture', true);

        $this->makeLoader()->enable('phlix-plugin-fixture');

        $this->assertTrue($plugin->onEnableCalled);
    }

    public function test_enable_translates_manifest_alias_to_event_fqcn(): void
    {
        // The translation is implicit: we assert the listener actually
        // fires when the FQCN form of the event is dispatched.
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once();

        $loader = $this->makeLoader();
        $loader->enable('phlix-plugin-fixture');

        $event = new PlaybackStarted('sess', 'user', 'item', 'dev', 0);
        foreach ($this->listenerRegistry->provider()->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        $this->assertSame(1, $plugin->fired);
    }

    public function test_enable_throws_when_subscribed_event_class_missing(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-bad-event',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePluginMissingEvent::class,
        ]);

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn(new FakeLifecyclePluginMissingEvent());

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('non-existent event class');

        $this->makeLoader()->enable('phlix-plugin-bad-event');
    }

    public function test_enable_persists_enabled_true(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once()->with('phlix-plugin-fixture', true);

        $this->makeLoader()->enable('phlix-plugin-fixture');
    }

    public function test_enable_is_no_op_when_already_enabled_in_process(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once()->with('phlix-plugin-fixture', true);

        $loader = $this->makeLoader();
        $loader->enable('phlix-plugin-fixture');
        $loader->enable('phlix-plugin-fixture'); // 2nd call should bail before re-subscribing.
        $this->assertSame(1, $plugin->onEnableCount);
    }

    public function test_disable_unsubscribes_all_previously_subscribed_listeners(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', false)->once();

        $loader = $this->makeLoader();
        $loader->enable('phlix-plugin-fixture');
        $loader->disable('phlix-plugin-fixture');

        $event = new PlaybackStarted('sess', 'user', 'item', 'dev', 0);
        foreach ($this->listenerRegistry->provider()->getListenersForEvent($event) as $listener) {
            $listener($event);
        }
        $this->assertSame(0, $plugin->fired, 'Listener should not fire after disable().');
    }

    public function test_disable_calls_on_disable_on_plugin_entry_class(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', false)->once();

        $loader = $this->makeLoader();
        $loader->enable('phlix-plugin-fixture');
        $loader->disable('phlix-plugin-fixture');

        $this->assertTrue($plugin->onDisableCalled);
    }

    public function test_uninstall_calls_disable_first_when_currently_enabled(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $tempDir = sys_get_temp_dir() . '/phlix_uninst_' . uniqid('', true);
        mkdir($tempDir, 0775, true);

        $installed = $this->makeInstalled($manifest, enabled: true, directory: $tempDir);
        $this->expect($this->repository, 'findByName')->andReturn($installed);
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', false)->once();
        $this->expect($this->repository, 'delete')->once()->with('phlix-plugin-fixture');

        $this->makeLoader()->uninstall('phlix-plugin-fixture');

        $this->assertDirectoryDoesNotExist($tempDir);
    }

    public function test_uninstall_removes_var_plugins_subdir_and_db_row(): void
    {
        $manifest = $this->manifest();
        $tempDir = sys_get_temp_dir() . '/phlix_uninst2_' . uniqid('', true);
        mkdir($tempDir . '/sub', 0775, true);
        file_put_contents($tempDir . '/sub/file.txt', 'x');

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest, directory: $tempDir));
        $this->expect($this->repository, 'delete')->once()->with('phlix-plugin-fixture');

        $this->makeLoader()->uninstall('phlix-plugin-fixture');

        $this->assertDirectoryDoesNotExist($tempDir);
    }

    public function test_uninstall_throws_when_plugin_not_found(): void
    {
        $this->expect($this->repository, 'findByName')
            ->andThrow(new PluginNotFoundException('missing'));

        $this->expectException(PluginNotFoundException::class);
        $this->makeLoader()->uninstall('phlix-plugin-missing');
    }

    public function test_listInstalled_returns_dtos_with_settings_hydrated(): void
    {
        $manifest = $this->manifest();
        $dto = new InstalledPlugin(
            id: 'id',
            manifest: $manifest,
            enabled: false,
            installedAt: new DateTimeImmutable(),
            settings: ['k' => 'v'],
            directory: $this->stagedDir,
        );
        $this->expect($this->repository, 'listAll')->andReturn([$dto]);

        $result = $this->makeLoader()->listInstalled();
        $this->assertSame([$dto], $result);
    }

    public function test_getEnabled_delegates_to_repository(): void
    {
        $this->expect($this->repository, 'listEnabled')->andReturn([]);
        $this->assertSame([], $this->makeLoader()->getEnabled());
    }

    public function test_bootstrapEnabled_enables_each_persisted_plugin(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();
        $installed = $this->makeInstalled($manifest, enabled: true);

        $this->expect($this->repository, 'listEnabled')->andReturn([$installed]);
        $this->expect($this->repository, 'findByName')->andReturn($installed);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', true);
        $this->expect($this->container, 'get')->andReturn($plugin);

        $this->makeLoader()->bootstrapEnabled();
        $this->assertTrue($plugin->onEnableCalled);
    }

    public function test_install_removes_directory_when_composer_fails(): void
    {
        $manifest = $this->manifest();
        $stagedDir = sys_get_temp_dir() . '/phlix_loader_failure_' . uniqid('', true);
        mkdir($stagedDir, 0775, true);

        $this->expect($this->installer, 'installFromDirectory')->andReturn([$manifest, $stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->expect($this->composer, 'install')->andThrow(new PluginInstallException('boom'));
        $this->repository->shouldNotReceive('insert');

        try {
            $this->makeLoader()->installFromDirectory('/x');
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($stagedDir);
    }

    public function test_install_replaces_existing_db_row(): void
    {
        $manifest = $this->manifest();

        $this->expect($this->installer, 'installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->expect($this->composer, 'install')->once();
        $this->expect($this->repository, 'existsByName')->andReturn(true);
        $this->expect($this->repository, 'delete')->once()->with($manifest->name);
        $this->expect($this->repository, 'insert')->once();

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_validationErrors_attached_to_exception(): void
    {
        $this->expect($this->installer, 'installFromDirectory')
            ->andThrow(new PluginInstallException('bad', [new \Phlix\Shared\Plugin\ManifestValidationError('x', 'y', 'z')]));

        try {
            $this->makeLoader()->installFromDirectory('/x');
            $this->fail('Expected exception');
        } catch (PluginInstallException $e) {
            $this->assertCount(1, $e->validationErrors());
        }
    }

    public function test_enable_throws_when_entry_class_does_not_exist(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-noexist',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlix\\Definitely\\Not\\Real\\Plugin',
        ]);

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('does not exist');

        $this->makeLoader()->enable('phlix-plugin-noexist');
    }

    public function test_enable_throws_when_container_throws(): void
    {
        $manifest = $this->manifest();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andThrow(new \RuntimeException('cannot resolve'));

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('could not be resolved');

        $this->makeLoader()->enable('phlix-plugin-fixture');
    }

    public function test_enable_throws_when_onEnable_throws(): void
    {
        $manifest = $this->manifest();
        $plugin = new ThrowingOnEnablePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('onEnable() threw');

        $this->makeLoader()->enable('phlix-plugin-fixture');
    }

    /**
     * A test installed plugin carrying a specific entry class and settings map.
     */
    private function installedWith(string $name, string $entry, array $settings): InstalledPlugin
    {
        return new InstalledPlugin(
            id: 'id',
            manifest: Manifest::fromArray([
                'name' => $name,
                'version' => '1.0.0',
                'phlix_min_server_version' => '0.10.0',
                'type' => 'metadata-provider',
                'entry' => $entry,
                'events' => [],
            ]),
            enabled: false,
            installedAt: new DateTimeImmutable(),
            settings: $settings,
            directory: $this->stagedDir,
        );
    }

    public function test_enable_delivers_settings_via_configurable_interface_before_onEnable(): void
    {
        $plugin = new ConfigurableFakePlugin();
        $settings = ['api_key' => 'secret123', 'enabled' => true];

        $this->expect($this->repository, 'findByName')
            ->andReturn($this->installedWith('phlix-plugin-cfg', ConfigurableFakePlugin::class, $settings));
        $this->expect($this->container, 'get')->with(ConfigurableFakePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once()->with('phlix-plugin-cfg', true);

        $this->makeLoader()->enable('phlix-plugin-cfg');

        $this->assertSame($settings, $plugin->configuredWith, 'configure() must receive the persisted settings');
        $this->assertTrue($plugin->configuredBeforeOnEnable, 'configure() must run BEFORE onEnable()');
        $this->assertTrue($plugin->onEnableCalled);
    }

    public function test_enable_delivers_settings_via_duck_typed_configure_method(): void
    {
        $plugin = new DuckConfigureFakePlugin();
        $settings = ['token' => 'abc'];

        $this->expect($this->repository, 'findByName')
            ->andReturn($this->installedWith('phlix-plugin-duck', DuckConfigureFakePlugin::class, $settings));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once();

        $this->makeLoader()->enable('phlix-plugin-duck');

        $this->assertSame($settings, $plugin->configuredWith);
        $this->assertTrue($plugin->onEnableCalled);
    }

    public function test_enable_does_not_call_no_arg_configure_method(): void
    {
        $plugin = new NoArgConfigureFakePlugin();

        $this->expect($this->repository, 'findByName')
            ->andReturn($this->installedWith('phlix-plugin-noarg', NoArgConfigureFakePlugin::class, ['x' => 1]));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once();

        $this->makeLoader()->enable('phlix-plugin-noarg');

        $this->assertFalse($plugin->configureCalled, 'a no-arg configure() is not the settings hook and must not be called');
        $this->assertTrue($plugin->onEnableCalled);
    }

    public function test_enable_throws_when_configure_throws(): void
    {
        $plugin = new ThrowingConfigureFakePlugin();

        $this->expect($this->repository, 'findByName')
            ->andReturn($this->installedWith('phlix-plugin-badcfg', ThrowingConfigureFakePlugin::class, ['k' => 'v']));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->repository->shouldNotReceive('setEnabled');

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('configure() threw');

        $this->makeLoader()->enable('phlix-plugin-badcfg');
    }

    public function test_enable_builds_legacy_settings_constructor_plugin_via_factory_fallback(): void
    {
        $settings = ['api_key' => 'k', 'username' => 'u'];
        $built = null;

        /** @var ContainerInterface&FactoryInterface&MockInterface $container */
        $container = Mockery::mock(ContainerInterface::class, FactoryInterface::class);
        // Autowiring the array-settings constructor fails, exactly as PHP-DI does.
        $container->shouldReceive('get')
            ->with(SettingsCtorFakePlugin::class)
            ->andThrow(new \RuntimeException('Parameter $settings has no value defined or guessable'));
        // The fallback binds the persisted settings to the `settings` parameter.
        $container->shouldReceive('make')
            ->with(SettingsCtorFakePlugin::class, ['settings' => $settings])
            ->andReturnUsing(function (string $cls, array $params) use (&$built): object {
                $built = new SettingsCtorFakePlugin($params['settings']);
                return $built;
            });

        $this->expect($this->repository, 'findByName')
            ->andReturn($this->installedWith('phlix-plugin-legacy', SettingsCtorFakePlugin::class, $settings));
        $this->expect($this->repository, 'setEnabled')->once()->with('phlix-plugin-legacy', true);

        $loader = new PluginLoader(
            $this->installer,
            $this->composer,
            $this->verifier,
            $this->repository,
            $this->listenerRegistry,
            $container,
            $this->auditLogger,
            $this->logger,
        );
        $loader->enable('phlix-plugin-legacy');

        $this->assertInstanceOf(SettingsCtorFakePlugin::class, $built);
        $this->assertSame($settings, $built->ctorSettings, 'settings must be injected via the constructor fallback');
        $this->assertTrue($built->onEnableCalled);
    }

    public function test_enable_surfaces_resolution_error_when_fallback_does_not_apply(): void
    {
        // A scalar-first constructor (like opensubtitles' `string $apiKey`) cannot
        // be filled by the array fallback → the clean resolution error is surfaced.
        /** @var ContainerInterface&FactoryInterface&MockInterface $container */
        $container = Mockery::mock(ContainerInterface::class, FactoryInterface::class);
        $container->shouldReceive('get')
            ->andThrow(new \RuntimeException('Parameter $apiKey has no value defined or guessable'));
        $container->shouldNotReceive('make');

        $this->expect($this->repository, 'findByName')
            ->andReturn($this->installedWith('phlix-plugin-scalar', ScalarCtorFakePlugin::class, ['api_key' => 'k']));

        $loader = new PluginLoader(
            $this->installer,
            $this->composer,
            $this->verifier,
            $this->repository,
            $this->listenerRegistry,
            $container,
            $this->auditLogger,
            $this->logger,
        );

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('could not be resolved');

        $loader->enable('phlix-plugin-scalar');
    }

    public function test_enable_throws_when_manifest_alias_unknown(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-badalias',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePlugin::class,
            'events' => ['phlix.not.a.real.event'],
        ]);

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn(new FakeLifecyclePlugin());

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('unknown event alias');

        $this->makeLoader()->enable('phlix-plugin-badalias');
    }

    public function test_enable_throws_when_subscribed_method_missing(): void
    {
        $manifest = $this->manifest();
        $plugin = new MissingMethodPlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('the entry class does not implement it');

        $this->makeLoader()->enable('phlix-plugin-fixture');
    }

    public function test_enable_accepts_closure_handler(): void
    {
        $manifest = $this->manifest();
        $plugin = new ClosureHandlerPlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->once();

        $this->makeLoader()->enable('phlix-plugin-fixture');
        // A closure handler is a valid subscription target; the plugin exposes it.
        $this->assertNotEmpty($plugin->subscribedEvents());
    }

    public function test_enable_throws_when_subscribed_handler_is_garbage(): void
    {
        $manifest = $this->manifest();
        $plugin = new GarbageHandlerPlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('must be a method name or callable');

        $this->makeLoader()->enable('phlix-plugin-fixture');
    }

    public function test_disable_continues_when_onDisable_throws(): void
    {
        $manifest = $this->manifest();
        $plugin = new ThrowingOnDisablePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fixture', false)->once();

        $this->expect($this->logger, 'warning')->atLeast()->once();

        $loader = $this->makeLoader();
        $loader->enable('phlix-plugin-fixture');
        $loader->disable('phlix-plugin-fixture');
    }

    public function test_install_hydrates_default_settings_from_manifest(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-defaults',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePlugin::class,
            'settings' => [
                'retries' => ['type' => 'int', 'required' => false, 'default' => 5],
                'host' => ['type' => 'string', 'required' => true],
            ],
        ]);

        $this->expect($this->installer, 'installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->expect($this->composer, 'install')->once();
        $this->expect($this->repository, 'existsByName')->andReturn(false);
        // `host` is required: true with NO default — it is materialised as a
        // `null` slot (Option (b) null-fill), NOT dropped. `required` is
        // advisory metadata for the settings UI, not a load-time rejection.
        $this->expect($this->repository, 'insert')
            ->once()
            ->with(Mockery::any(), false, ['retries' => 5, 'host' => null]);

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_null_fills_every_declared_setting_without_a_default(): void
    {
        // Covers all three slot shapes:
        //  - `with_default`    : keeps its declared default.
        //  - `required_no_def` : required:true but no default → null slot (a slot still exists).
        //  - `optional_no_def` : non-required, no default → null slot.
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-nullfill',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePlugin::class,
            'settings' => [
                'with_default'    => ['type' => 'string', 'required' => false, 'default' => 'keep-me'],
                'required_no_def' => ['type' => 'string', 'required' => true],
                'optional_no_def' => ['type' => 'int', 'required' => false],
            ],
        ]);

        $this->expect($this->installer, 'installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->expect($this->verifier, 'verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->expect($this->composer, 'install')->once();
        $this->expect($this->repository, 'existsByName')->andReturn(false);

        $captured = null;
        $this->expect($this->repository, 'insert')
            ->once()
            ->with(
                Mockery::any(),
                false,
                Mockery::on(function ($defaults) use (&$captured): bool {
                    $captured = $defaults;
                    return is_array($defaults);
                }),
            );

        $this->makeLoader()->installFromDirectory('/x');

        // Every declared key has a slot (key-set === manifest key-set).
        self::assertSame(
            ['with_default', 'required_no_def', 'optional_no_def'],
            array_keys((array) $captured),
        );
        self::assertSame('keep-me', $captured['with_default']);
        // Required-but-defaultless and optional-but-defaultless both null-fill.
        self::assertArrayHasKey('required_no_def', (array) $captured);
        self::assertNull($captured['required_no_def']);
        self::assertArrayHasKey('optional_no_def', (array) $captured);
        self::assertNull($captured['optional_no_def']);
    }

    public function test_bootstrapEnabled_logs_errors_for_broken_plugins_and_continues(): void
    {
        $manifest = $this->manifest();
        $installed = $this->makeInstalled($manifest, enabled: true);
        $this->expect($this->repository, 'listEnabled')->andReturn([$installed]);
        $this->expect($this->repository, 'findByName')->andReturn($installed);
        $this->expect($this->container, 'get')->andThrow(new \RuntimeException('boom'));

        $this->expect($this->logger, 'error')
            ->atLeast()->once()
            ->with(Mockery::on(fn ($m) => str_contains($m, 'failed to bootstrap')), Mockery::any());

        $this->makeLoader()->bootstrapEnabled();
    }
}

/**
 * @internal Test-only entry class used by PluginLoaderTest.
 */
final class FakeLifecyclePlugin implements LifecycleInterface
{
    public bool $onEnableCalled = false;
    public bool $onDisableCalled = false;
    public int $onEnableCount = 0;
    public int $fired = 0;

    public function onEnable(ContainerInterface $container): void
    {
        $this->onEnableCalled = true;
        $this->onEnableCount++;
    }

    public function onDisable(): void
    {
        $this->onDisableCalled = true;
    }

    public function subscribedEvents(): array
    {
        return [
            PlaybackStarted::class => 'handle',
        ];
    }

    public function handle(PlaybackStarted $event): void
    {
        $this->fired++;
    }
}

/**
 * @internal Entry class implementing ConfigurableInterface; records the
 * settings it was configured with and whether configure() ran before onEnable().
 */
final class ConfigurableFakePlugin implements LifecycleInterface, ConfigurableInterface
{
    /** @var array<string, mixed> */
    public array $configuredWith = [];
    public bool $configured = false;
    public bool $configuredBeforeOnEnable = false;
    public bool $onEnableCalled = false;

    public function configure(array $settings): void
    {
        $this->configured = true;
        $this->configuredWith = $settings;
    }

    public function onEnable(ContainerInterface $container): void
    {
        $this->onEnableCalled = true;
        $this->configuredBeforeOnEnable = $this->configured;
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * @internal Entry class that predates ConfigurableInterface but exposes a
 * public `configure(array)` settings hook (duck-typed).
 */
final class DuckConfigureFakePlugin implements LifecycleInterface
{
    /** @var array<string, mixed> */
    public array $configuredWith = [];
    public bool $onEnableCalled = false;

    public function configure(array $settings): void
    {
        $this->configuredWith = $settings;
    }

    public function onEnable(ContainerInterface $container): void
    {
        $this->onEnableCalled = true;
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * @internal Entry class with an unrelated no-arg `configure()` that must NOT
 * be treated as the settings hook.
 */
final class NoArgConfigureFakePlugin implements LifecycleInterface
{
    public bool $configureCalled = false;
    public bool $onEnableCalled = false;

    public function configure(): void
    {
        $this->configureCalled = true;
    }

    public function onEnable(ContainerInterface $container): void
    {
        $this->onEnableCalled = true;
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * @internal Entry class whose configure() throws — the loader must surface it
 * as a PluginEnableException and not enable the plugin.
 */
final class ThrowingConfigureFakePlugin implements LifecycleInterface, ConfigurableInterface
{
    public function configure(array $settings): void
    {
        throw new \RuntimeException('bad config');
    }

    public function onEnable(ContainerInterface $container): void
    {
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * @internal Legacy entry class whose constructor REQUIRES the settings array
 * (the anidb/myanimelist shape) — exercises the settings-constructor fallback.
 */
final class SettingsCtorFakePlugin implements LifecycleInterface
{
    /** @var array<string, mixed> */
    public array $ctorSettings;
    public bool $onEnableCalled = false;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings)
    {
        $this->ctorSettings = $settings;
    }

    public function onEnable(ContainerInterface $container): void
    {
        $this->onEnableCalled = true;
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * @internal Legacy entry class whose first required constructor parameter is a
 * scalar (the opensubtitles shape) — the array fallback cannot fill it.
 */
final class ScalarCtorFakePlugin implements LifecycleInterface
{
    public function __construct(string $apiKey)
    {
    }

    public function onEnable(ContainerInterface $container): void
    {
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * @internal Test-only plugin that subscribes to a non-existent event class.
 */
final class FakeLifecyclePluginMissingEvent implements LifecycleInterface
{
    public function onEnable(ContainerInterface $container): void
    {
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        /** @var array<class-string, string|callable> $events */
        $events = $this->malformedSubscriptions();

        return $events;
    }

    /**
     * Intentionally malformed subscriptions: the key is not a real event
     * class, which exercises the loader's rejection path. Typed `mixed` so the
     * deliberately-invalid shape stays out of the interface's return contract.
     */
    private function malformedSubscriptions(): mixed
    {
        return [
            'Phlix\\Definitely\\Not\\AnEvent' => 'handle',
        ];
    }

    public function handle(object $event): void
    {
    }
}

/**
 * @internal
 */
final class ThrowingOnEnablePlugin implements LifecycleInterface
{
    public function onEnable(ContainerInterface $container): void
    {
        throw new \RuntimeException('boom');
    }
    public function onDisable(): void
    {
    }
    public function subscribedEvents(): array
    {
        return [];
    }
}

/**
 * @internal
 */
final class ThrowingOnDisablePlugin implements LifecycleInterface
{
    public function onEnable(ContainerInterface $container): void
    {
    }
    public function onDisable(): void
    {
        throw new \RuntimeException('disable kaboom');
    }
    public function subscribedEvents(): array
    {
        return [PlaybackStarted::class => 'handle'];
    }
    public function handle(PlaybackStarted $event): void
    {
    }
}

/**
 * @internal
 */
final class MissingMethodPlugin implements LifecycleInterface
{
    public function onEnable(ContainerInterface $container): void
    {
    }
    public function onDisable(): void
    {
    }
    public function subscribedEvents(): array
    {
        return [PlaybackStarted::class => 'doesNotExistOnMe'];
    }
}

/**
 * @internal
 */
final class ClosureHandlerPlugin implements LifecycleInterface
{
    public function onEnable(ContainerInterface $container): void
    {
    }
    public function onDisable(): void
    {
    }
    public function subscribedEvents(): array
    {
        return [PlaybackStarted::class => static fn (PlaybackStarted $event): null => null];
    }
}

/**
 * @internal
 */
final class GarbageHandlerPlugin implements LifecycleInterface
{
    public function onEnable(ContainerInterface $container): void
    {
    }
    public function onDisable(): void
    {
    }
    public function subscribedEvents(): array
    {
        /** @var array<class-string, string|callable> $events */
        $events = $this->malformedSubscriptions();

        return $events;
    }

    /**
     * Intentionally malformed subscriptions: the handler value is neither a
     * method name nor a callable, which exercises the loader's rejection path.
     * Typed `mixed` so the deliberately-invalid shape stays out of the
     * interface's return contract.
     */
    private function malformedSubscriptions(): mixed
    {
        return [PlaybackStarted::class => 12345];
    }
}
