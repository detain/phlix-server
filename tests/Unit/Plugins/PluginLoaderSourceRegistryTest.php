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
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Proves the Step 3.5 headline success condition: a plugin whose entry class
 * implements the shared {@see MetadataSourceInterface} appears in the
 * {@see SourceRegistry} after {@see PluginLoader::enable()} and is gone after
 * {@see PluginLoader::disable()} (leak-free), driven by the typed interface —
 * NOT the old `method_exists()`/FQCN container-sniffing convention.
 *
 */
final class PluginLoaderSourceRegistryTest extends TestCase
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
    private SourceRegistry $sourceRegistry;
    private string $stagedDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagedDir = sys_get_temp_dir() . '/phlix_srcreg_' . uniqid('', true);
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
        $this->sourceRegistry = new SourceRegistry();
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
            $this->sourceRegistry,
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

    public function test_enable_registers_metadata_source_and_disable_deregisters_it(): void
    {
        $manifest = $this->manifest('phlix-plugin-fakesource', FakeMetadataSourcePlugin::class);
        $plugin = new FakeMetadataSourcePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakeMetadataSourcePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fakesource', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fakesource', false)->once();

        $loader = $this->makeLoader();

        $this->assertFalse($this->sourceRegistry->has('fakesource'), 'registry starts empty');

        $loader->enable('phlix-plugin-fakesource');
        $this->assertTrue($this->sourceRegistry->has('fakesource'), 'source present after enable');
        $this->assertSame($plugin, $this->sourceRegistry->get('fakesource'));
        $this->assertCount(1, $this->sourceRegistry->all());

        $loader->disable('phlix-plugin-fakesource');
        $this->assertFalse($this->sourceRegistry->has('fakesource'), 'source gone after disable');
        $this->assertCount(0, $this->sourceRegistry->all(), 'no leak after enable/disable cycle');
    }

    public function test_enable_disable_cycle_repeated_does_not_leak(): void
    {
        $manifest = $this->manifest('phlix-plugin-fakesource', FakeMetadataSourcePlugin::class);

        // Fresh instance each enable (the loader resolves it from the container).
        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')
            ->with(FakeMetadataSourcePlugin::class)
            ->andReturnUsing(static fn (): FakeMetadataSourcePlugin => new FakeMetadataSourcePlugin());
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fakesource', true);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fakesource', false);

        $loader = $this->makeLoader();

        for ($i = 0; $i < 5; $i++) {
            $loader->enable('phlix-plugin-fakesource');
            $this->assertCount(1, $this->sourceRegistry->all());
            $loader->disable('phlix-plugin-fakesource');
            $this->assertCount(0, $this->sourceRegistry->all());
        }
    }

    public function test_non_metadata_source_plugin_is_not_registered(): void
    {
        $manifest = $this->manifest('phlix-plugin-plain', PlainLifecyclePlugin::class);
        $plugin = new PlainLifecyclePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(PlainLifecyclePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-plain', true)->once();

        $this->makeLoader()->enable('phlix-plugin-plain');

        $this->assertSame([], $this->sourceRegistry->all(), 'plain lifecycle plugin is not a metadata source');
    }

    public function test_metadata_source_registration_is_skipped_when_no_registry_injected(): void
    {
        // PluginLoader built WITHOUT a SourceRegistry (legacy/test construction)
        // must still enable a MetadataSourceInterface plugin without fataling.
        $manifest = $this->manifest('phlix-plugin-fakesource', FakeMetadataSourcePlugin::class);
        $plugin = new FakeMetadataSourcePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakeMetadataSourcePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-fakesource', true)->once();

        $loaderNoRegistry = new PluginLoader(
            $this->installer,
            $this->composer,
            $this->verifier,
            $this->repository,
            $this->listenerRegistry,
            $this->container,
            $this->auditLogger,
            $this->logger,
            null,
        );

        $loaderNoRegistry->enable('phlix-plugin-fakesource');

        $this->assertSame([], $this->sourceRegistry->all());
    }
}

/**
 * Fake plugin entry class that is BOTH a lifecycle plugin and a typed
 * metadata source — the shape the anidb/myanimelist entry classes adopt.
 */
final class FakeMetadataSourcePlugin implements LifecycleInterface, MetadataSourceInterface
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

    public function sourceName(): string
    {
        return 'fakesource';
    }

    /** @return list<non-empty-string> */
    public function supportedMediaTypes(): array
    {
        return ['anime', 'series'];
    }

    /** @return list<array{id: non-empty-string, title: string}> */
    public function search(string $query, array $options = []): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function getDetails(string $externalId, array $options = []): array
    {
        return [];
    }

    /** @return array<string, list<array{url: non-empty-string}>> */
    public function getImages(string $externalId): array
    {
        return [];
    }
}

/**
 * Plain lifecycle plugin that is NOT a metadata source — must not land in the
 * SourceRegistry on enable.
 */
final class PlainLifecyclePlugin implements LifecycleInterface
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
