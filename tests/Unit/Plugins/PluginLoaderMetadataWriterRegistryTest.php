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
use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\Writer\MetadataWriterInterface;
use Phlix\Media\Metadata\Writer\MetadataWriterRegistry;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use Phlix\Shared\Plugin\LifecycleInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * S87 — the fourth capability arm: a plugin whose entry class implements
 * {@see MetadataWriterInterface} appears in the {@see MetadataWriterRegistry}
 * after {@see PluginLoader::enable()} and is gone after
 * {@see PluginLoader::disable()} (leak-free), driven by the typed interface —
 * NOT method_exists()/FQCN sniffing. Exact parallel of
 * {@see PluginLoaderSubtitleSourceRegistryTest}; this registry is what the
 * MetadataWriteWorker reads in its own fork (writers arrive in S88/S89).
 */
final class PluginLoaderMetadataWriterRegistryTest extends TestCase
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
    private MetadataWriterRegistry $writerRegistry;
    private string $stagedDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagedDir = sys_get_temp_dir() . '/phlix_s87mwreg_' . uniqid('', true);
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
        $this->writerRegistry = new MetadataWriterRegistry();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->stagedDir)) {
            @system('rm -rf ' . escapeshellarg($this->stagedDir));
        }
    }

    private function makeLoader(?MetadataWriterRegistry $registry): PluginLoader
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
            'type' => 'metadata-provider',
            'entry' => $entry,
            'events' => [],
        ]);
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

    public function test_enable_registers_metadata_writer_and_disable_deregisters_it(): void
    {
        $manifest = $this->manifest('phlix-plugin-sidecar-writer', FakeMetadataWriterPlugin::class);
        $plugin = new FakeMetadataWriterPlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakeMetadataWriterPlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-sidecar-writer', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-sidecar-writer', false)->once();

        $loader = $this->makeLoader($this->writerRegistry);

        $this->assertSame([], $this->writerRegistry->all(), 'registry starts empty');

        $loader->enable('phlix-plugin-sidecar-writer');
        $this->assertTrue(
            $this->writerRegistry->has(FakeMetadataWriterPlugin::class),
            'S87 arm: writer present after enable'
        );
        $this->assertSame($plugin, array_values($this->writerRegistry->all())[0]);
        $this->assertCount(1, $this->writerRegistry->all());

        $loader->disable('phlix-plugin-sidecar-writer');
        $this->assertFalse(
            $this->writerRegistry->has(FakeMetadataWriterPlugin::class),
            'S87 arm: writer gone after disable'
        );
        $this->assertCount(0, $this->writerRegistry->all(), 'no leak after enable/disable cycle');
    }

    public function test_enable_disable_cycle_repeated_does_not_leak(): void
    {
        $manifest = $this->manifest('phlix-plugin-sidecar-writer', FakeMetadataWriterPlugin::class);

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')
            ->with(FakeMetadataWriterPlugin::class)
            ->andReturnUsing(static fn (): FakeMetadataWriterPlugin => new FakeMetadataWriterPlugin());
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-sidecar-writer', true);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-sidecar-writer', false);

        $loader = $this->makeLoader($this->writerRegistry);

        for ($i = 0; $i < 5; $i++) {
            $loader->enable('phlix-plugin-sidecar-writer');
            $this->assertCount(1, $this->writerRegistry->all(), 'replace-on-enable never grows the map');
            $loader->disable('phlix-plugin-sidecar-writer');
            $this->assertCount(0, $this->writerRegistry->all());
        }
    }

    public function test_registration_skipped_when_no_writer_registry_injected(): void
    {
        $manifest = $this->manifest('phlix-plugin-sidecar-writer', FakeMetadataWriterPlugin::class);
        $plugin = new FakeMetadataWriterPlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakeMetadataWriterPlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-sidecar-writer', true)->once();

        // No MetadataWriterRegistry — enable must not fatal (legacy construction).
        $this->makeLoader(null)->enable('phlix-plugin-sidecar-writer');

        $this->assertSame([], $this->writerRegistry->all());
    }

    public function test_non_writer_plugin_leaves_registry_untouched(): void
    {
        $manifest = $this->manifest('phlix-plugin-plain', FakePlainPlugin::class);
        $plugin = new FakePlainPlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakePlainPlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-plain', true)->once();

        $this->makeLoader($this->writerRegistry)->enable('phlix-plugin-plain');

        $this->assertSame(
            [],
            $this->writerRegistry->all(),
            'The instanceof arm fires ONLY for typed writers — plain plugins register nothing.'
        );
    }
}

/**
 * Fake plugin entry class that is BOTH a lifecycle plugin and a typed metadata
 * writer — the shape the S88 sidecar writer plugin will adopt. The write body
 * is inert by design: S87 tests the WIRING; S88/S89 own the writing.
 */
final class FakeMetadataWriterPlugin implements LifecycleInterface, MetadataWriterInterface
{
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

    public function supports(string $type): bool
    {
        return $type === 'movie';
    }

    public function write(MediaItem $item, array $canonicalMetadata, string $mediaDir): void
    {
    }
}

/**
 * Control plugin: lifecycle only, no capability interfaces — proves the arm is
 * a typed instanceof check, not a blanket register-everything.
 */
final class FakePlainPlugin implements LifecycleInterface
{
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
}
