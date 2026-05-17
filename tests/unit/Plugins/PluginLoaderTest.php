<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins;

use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Phlex\Common\Events\ListenerRegistry;
use Phlex\Common\Events\Playback\PlaybackStarted;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Plugins\Contract\LifecycleInterface;
use Phlex\Plugins\Exception\PluginEnableException;
use Phlex\Plugins\Exception\PluginInstallException;
use Phlex\Plugins\Exception\PluginNotFoundException;
use Phlex\Plugins\Installer\ComposerRunner;
use Phlex\Plugins\Installer\HttpInstaller;
use Phlex\Plugins\InstalledPlugin;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\PluginLoader;
use Phlex\Plugins\Repository\PluginRepository;
use Phlex\Plugins\Signature\SignatureVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Phlex\Plugins\PluginLoader
 * @covers \Phlex\Plugins\InstalledPlugin
 * @covers \Phlex\Plugins\Exception\PluginInstallException
 * @covers \Phlex\Plugins\Exception\PluginEnableException
 * @covers \Phlex\Plugins\Exception\PluginNotFoundException
 */
final class PluginLoaderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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

        $this->stagedDir = sys_get_temp_dir() . '/phlex_loadertest_' . uniqid('', true);
        mkdir($this->stagedDir, 0775, true);

        $this->installer = Mockery::mock(HttpInstaller::class);
        $this->composer = Mockery::mock(ComposerRunner::class);
        $this->verifier = Mockery::mock(SignatureVerifier::class);
        $this->repository = Mockery::mock(PluginRepository::class);
        $this->listenerRegistry = new ListenerRegistry(
            null,
            Mockery::mock(StructuredLogger::class)->shouldIgnoreMissing(),
        );
        $this->container = Mockery::mock(ContainerInterface::class);
        $this->auditLogger = Mockery::mock(AuditLogger::class)->shouldIgnoreMissing();
        $this->logger = Mockery::mock(StructuredLogger::class)->shouldIgnoreMissing();
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

    private function manifest(string $name = 'phlex-plugin-fixture'): Manifest
    {
        return Manifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => FakeLifecyclePlugin::class,
            'events' => ['phlex.playback.started'],
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

        $this->installer->shouldReceive('installFromDirectory')
            ->once()
            ->with('/path/to/source')
            ->andReturn([$manifest, $this->stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_UNSIGNED);
        $this->composer->shouldReceive('install')->once()->with($this->stagedDir);
        $this->repository->shouldReceive('existsByName')->andReturn(false);
        $this->repository->shouldReceive('insert')
            ->once()
            ->with(Mockery::on(fn ($m) => $m === $manifest), false, []);

        $loader = $this->makeLoader();
        $returned = $loader->installFromDirectory('/path/to/source');

        $this->assertSame($manifest, $returned);
    }

    public function test_install_rejects_invalid_manifest_with_install_exception(): void
    {
        $this->installer->shouldReceive('installFromDirectory')
            ->andThrow(new PluginInstallException('bad manifest', []));
        $this->composer->shouldNotReceive('install');
        $this->repository->shouldNotReceive('insert');

        $this->expectException(PluginInstallException::class);
        $this->makeLoader()->installFromDirectory('/bad');
    }

    public function test_install_rejects_unsupported_phlex_min_server_version(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-future',
            'version' => '1.0.0',
            'phlex_min_server_version' => '99.99.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePlugin::class,
        ]);

        $this->installer->shouldReceive('installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->composer->shouldNotReceive('install');
        $this->repository->shouldNotReceive('insert');

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('requires Phlex >= 99.99.0');

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_writes_to_var_plugins_subdir_named_after_plugin(): void
    {
        $manifest = $this->manifest('phlex-plugin-bar');

        $this->installer->shouldReceive('installFromDirectory')
            ->once()
            ->andReturn([$manifest, $this->stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->repository->shouldReceive('existsByName')->andReturn(false);
        $this->repository->shouldReceive('insert')->once();
        $this->composer->shouldReceive('install')->once()->with($this->stagedDir);

        $this->makeLoader()->installFromDirectory('/anywhere');
    }

    public function test_install_logs_warning_for_unsigned_plugins(): void
    {
        $manifest = $this->manifest();

        $this->installer->shouldReceive('installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_UNSIGNED);
        $this->composer->shouldReceive('install')->once();
        $this->repository->shouldReceive('existsByName')->andReturn(false);
        $this->repository->shouldReceive('insert')->once();

        $this->logger->shouldReceive('warning')
            ->atLeast()->once()
            ->with(Mockery::on(fn ($m) => str_contains($m, 'unsigned plugin')), Mockery::any());
        $this->logger->shouldReceive('info')->withAnyArgs();

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_rejects_when_signature_invalid(): void
    {
        $manifest = $this->manifest();

        $this->installer->shouldReceive('installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_INVALID);
        $this->composer->shouldNotReceive('install');

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('signature did not verify');

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_via_url_delegates_to_http_installer(): void
    {
        $manifest = $this->manifest();
        $this->installer->shouldReceive('install')
            ->once()
            ->with('https://example.test/plugin.tar.gz')
            ->andReturn([$manifest, $this->stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->composer->shouldReceive('install')->once();
        $this->repository->shouldReceive('existsByName')->andReturn(false);
        $this->repository->shouldReceive('insert')->once();

        $returned = $this->makeLoader()->install('https://example.test/plugin.tar.gz');
        $this->assertSame($manifest, $returned);
    }

    public function test_enable_requires_lifecycle_interface_or_throws(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-bad',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => \stdClass::class,
        ]);

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->with(\stdClass::class)->andReturn(new \stdClass());

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('must implement');

        $this->makeLoader()->enable('phlex-plugin-bad');
    }

    public function test_enable_subscribes_each_declared_event_to_listener_registry(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->with(FakeLifecyclePlugin::class)->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->once()->with('phlex-plugin-fixture', true);

        $this->makeLoader()->enable('phlex-plugin-fixture');

        $this->assertTrue($plugin->onEnableCalled);
    }

    public function test_enable_translates_manifest_alias_to_event_fqcn(): void
    {
        // The translation is implicit: we assert the listener actually
        // fires when the FQCN form of the event is dispatched.
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->once();

        $loader = $this->makeLoader();
        $loader->enable('phlex-plugin-fixture');

        $event = new PlaybackStarted('sess', 'user', 'item', 'dev', 0);
        foreach ($this->listenerRegistry->provider()->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        $this->assertSame(1, $plugin->fired);
    }

    public function test_enable_throws_when_subscribed_event_class_missing(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-bad-event',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePluginMissingEvent::class,
        ]);

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn(new FakeLifecyclePluginMissingEvent());

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('non-existent event class');

        $this->makeLoader()->enable('phlex-plugin-bad-event');
    }

    public function test_enable_persists_enabled_true(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->once()->with('phlex-plugin-fixture', true);

        $this->makeLoader()->enable('phlex-plugin-fixture');
    }

    public function test_enable_is_no_op_when_already_enabled_in_process(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->once()->with('phlex-plugin-fixture', true);

        $loader = $this->makeLoader();
        $loader->enable('phlex-plugin-fixture');
        $loader->enable('phlex-plugin-fixture'); // 2nd call should bail before re-subscribing.
        $this->assertSame(1, $plugin->onEnableCount);
    }

    public function test_disable_unsubscribes_all_previously_subscribed_listeners(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', true)->once();
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', false)->once();

        $loader = $this->makeLoader();
        $loader->enable('phlex-plugin-fixture');
        $loader->disable('phlex-plugin-fixture');

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

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', true)->once();
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', false)->once();

        $loader = $this->makeLoader();
        $loader->enable('phlex-plugin-fixture');
        $loader->disable('phlex-plugin-fixture');

        $this->assertTrue($plugin->onDisableCalled);
    }

    public function test_uninstall_calls_disable_first_when_currently_enabled(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();

        $tempDir = sys_get_temp_dir() . '/phlex_uninst_' . uniqid('', true);
        mkdir($tempDir, 0775, true);

        $installed = $this->makeInstalled($manifest, enabled: true, directory: $tempDir);
        $this->repository->shouldReceive('findByName')->andReturn($installed);
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', false)->once();
        $this->repository->shouldReceive('delete')->once()->with('phlex-plugin-fixture');

        $this->makeLoader()->uninstall('phlex-plugin-fixture');

        $this->assertDirectoryDoesNotExist($tempDir);
    }

    public function test_uninstall_removes_var_plugins_subdir_and_db_row(): void
    {
        $manifest = $this->manifest();
        $tempDir = sys_get_temp_dir() . '/phlex_uninst2_' . uniqid('', true);
        mkdir($tempDir . '/sub', 0775, true);
        file_put_contents($tempDir . '/sub/file.txt', 'x');

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest, directory: $tempDir));
        $this->repository->shouldReceive('delete')->once()->with('phlex-plugin-fixture');

        $this->makeLoader()->uninstall('phlex-plugin-fixture');

        $this->assertDirectoryDoesNotExist($tempDir);
    }

    public function test_uninstall_throws_when_plugin_not_found(): void
    {
        $this->repository->shouldReceive('findByName')
            ->andThrow(new PluginNotFoundException('missing'));

        $this->expectException(PluginNotFoundException::class);
        $this->makeLoader()->uninstall('phlex-plugin-missing');
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
        $this->repository->shouldReceive('listAll')->andReturn([$dto]);

        $result = $this->makeLoader()->listInstalled();
        $this->assertSame([$dto], $result);
    }

    public function test_getEnabled_delegates_to_repository(): void
    {
        $this->repository->shouldReceive('listEnabled')->andReturn([]);
        $this->assertSame([], $this->makeLoader()->getEnabled());
    }

    public function test_bootstrapEnabled_enables_each_persisted_plugin(): void
    {
        $manifest = $this->manifest();
        $plugin = new FakeLifecyclePlugin();
        $installed = $this->makeInstalled($manifest, enabled: true);

        $this->repository->shouldReceive('listEnabled')->andReturn([$installed]);
        $this->repository->shouldReceive('findByName')->andReturn($installed);
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', true);
        $this->container->shouldReceive('get')->andReturn($plugin);

        $this->makeLoader()->bootstrapEnabled();
        $this->assertTrue($plugin->onEnableCalled);
    }

    public function test_install_removes_directory_when_composer_fails(): void
    {
        $manifest = $this->manifest();
        $stagedDir = sys_get_temp_dir() . '/phlex_loader_failure_' . uniqid('', true);
        mkdir($stagedDir, 0775, true);

        $this->installer->shouldReceive('installFromDirectory')->andReturn([$manifest, $stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->composer->shouldReceive('install')->andThrow(new PluginInstallException('boom'));
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

        $this->installer->shouldReceive('installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->composer->shouldReceive('install')->once();
        $this->repository->shouldReceive('existsByName')->andReturn(true);
        $this->repository->shouldReceive('delete')->once()->with($manifest->name);
        $this->repository->shouldReceive('insert')->once();

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_install_validationErrors_attached_to_exception(): void
    {
        $this->installer->shouldReceive('installFromDirectory')
            ->andThrow(new PluginInstallException('bad', [new \Phlex\Plugins\ManifestValidationError('x', 'y', 'z')]));

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
            'name' => 'phlex-plugin-noexist',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Definitely\\Not\\Real\\Plugin',
        ]);

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('does not exist');

        $this->makeLoader()->enable('phlex-plugin-noexist');
    }

    public function test_enable_throws_when_container_throws(): void
    {
        $manifest = $this->manifest();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andThrow(new \RuntimeException('cannot resolve'));

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('could not be resolved');

        $this->makeLoader()->enable('phlex-plugin-fixture');
    }

    public function test_enable_throws_when_onEnable_throws(): void
    {
        $manifest = $this->manifest();
        $plugin = new ThrowingOnEnablePlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('onEnable() threw');

        $this->makeLoader()->enable('phlex-plugin-fixture');
    }

    public function test_enable_throws_when_manifest_alias_unknown(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-badalias',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePlugin::class,
            'events' => ['phlex.not.a.real.event'],
        ]);

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn(new FakeLifecyclePlugin());

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('unknown event alias');

        $this->makeLoader()->enable('phlex-plugin-badalias');
    }

    public function test_enable_throws_when_subscribed_method_missing(): void
    {
        $manifest = $this->manifest();
        $plugin = new MissingMethodPlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('the entry class does not implement it');

        $this->makeLoader()->enable('phlex-plugin-fixture');
    }

    public function test_enable_accepts_closure_handler(): void
    {
        $manifest = $this->manifest();
        $plugin = new ClosureHandlerPlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->once();

        $this->makeLoader()->enable('phlex-plugin-fixture');
        $this->assertTrue(true); // No throw means success.
    }

    public function test_enable_throws_when_subscribed_handler_is_garbage(): void
    {
        $manifest = $this->manifest();
        $plugin = new GarbageHandlerPlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);

        $this->expectException(PluginEnableException::class);
        $this->expectExceptionMessage('must be a method name or callable');

        $this->makeLoader()->enable('phlex-plugin-fixture');
    }

    public function test_disable_continues_when_onDisable_throws(): void
    {
        $manifest = $this->manifest();
        $plugin = new ThrowingOnDisablePlugin();

        $this->repository->shouldReceive('findByName')->andReturn($this->makeInstalled($manifest));
        $this->container->shouldReceive('get')->andReturn($plugin);
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', true)->once();
        $this->repository->shouldReceive('setEnabled')->with('phlex-plugin-fixture', false)->once();

        $this->logger->shouldReceive('warning')->atLeast()->once();

        $loader = $this->makeLoader();
        $loader->enable('phlex-plugin-fixture');
        $loader->disable('phlex-plugin-fixture');
    }

    public function test_install_hydrates_default_settings_from_manifest(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-defaults',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => FakeLifecyclePlugin::class,
            'settings' => [
                'retries' => ['type' => 'int', 'required' => false, 'default' => 5],
                'host' => ['type' => 'string', 'required' => true],
            ],
        ]);

        $this->installer->shouldReceive('installFromDirectory')->andReturn([$manifest, $this->stagedDir]);
        $this->verifier->shouldReceive('verify')->andReturn(SignatureVerifier::RESULT_VALID);
        $this->composer->shouldReceive('install')->once();
        $this->repository->shouldReceive('existsByName')->andReturn(false);
        $this->repository->shouldReceive('insert')
            ->once()
            ->with(Mockery::any(), false, ['retries' => 5]);

        $this->makeLoader()->installFromDirectory('/x');
    }

    public function test_bootstrapEnabled_logs_errors_for_broken_plugins_and_continues(): void
    {
        $manifest = $this->manifest();
        $installed = $this->makeInstalled($manifest, enabled: true);
        $this->repository->shouldReceive('listEnabled')->andReturn([$installed]);
        $this->repository->shouldReceive('findByName')->andReturn($installed);
        $this->container->shouldReceive('get')->andThrow(new \RuntimeException('boom'));

        $this->logger->shouldReceive('error')
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
        return [
            'Phlex\\Definitely\\Not\\AnEvent' => 'handle',
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
        return [PlaybackStarted::class => 12345];
    }
}
