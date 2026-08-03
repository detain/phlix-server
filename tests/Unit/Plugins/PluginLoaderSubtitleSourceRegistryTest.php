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
use Phlix\Media\Subtitles\SubtitleSourceRegistry;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\InstalledPlugin;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use Phlix\Shared\Plugin\LifecycleInterface;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Phlix\Shared\Subtitle\SubtitleSourceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * F3 parallel of {@see PluginLoaderSourceRegistryTest}: a plugin whose entry
 * class implements the shared {@see SubtitleSourceInterface} appears in the
 * {@see SubtitleSourceRegistry} after {@see PluginLoader::enable()} and is gone
 * after {@see PluginLoader::disable()} (leak-free), driven by the typed
 * interface — NOT method_exists()/FQCN sniffing.
 */
final class PluginLoaderSubtitleSourceRegistryTest extends TestCase
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
    private SubtitleSourceRegistry $subtitleRegistry;
    private string $stagedDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagedDir = sys_get_temp_dir() . '/phlix_subreg_' . uniqid('', true);
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
        $this->subtitleRegistry = new SubtitleSourceRegistry();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->stagedDir)) {
            @system('rm -rf ' . escapeshellarg($this->stagedDir));
        }
    }

    private function makeLoader(?SubtitleSourceRegistry $registry): PluginLoader
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
            $registry,
        );
    }

    private function manifest(string $name, string $entry): Manifest
    {
        return Manifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'subtitle-provider',
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

    public function test_enable_registers_subtitle_source_and_disable_deregisters_it(): void
    {
        $manifest = $this->manifest('phlix-plugin-opensubtitles', FakeSubtitlePlugin::class);
        $plugin = new FakeSubtitlePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakeSubtitlePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-opensubtitles', true)->once();
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-opensubtitles', false)->once();

        $loader = $this->makeLoader($this->subtitleRegistry);

        $this->assertFalse($this->subtitleRegistry->has('opensubtitles'), 'registry starts empty');

        $loader->enable('phlix-plugin-opensubtitles');
        $this->assertTrue($this->subtitleRegistry->has('opensubtitles'), 'source present after enable');
        $this->assertSame($plugin, $this->subtitleRegistry->get('opensubtitles'));
        $this->assertCount(1, $this->subtitleRegistry->all());

        $loader->disable('phlix-plugin-opensubtitles');
        $this->assertFalse($this->subtitleRegistry->has('opensubtitles'), 'source gone after disable');
        $this->assertCount(0, $this->subtitleRegistry->all(), 'no leak after enable/disable cycle');
    }

    public function test_enable_disable_cycle_repeated_does_not_leak(): void
    {
        $manifest = $this->manifest('phlix-plugin-opensubtitles', FakeSubtitlePlugin::class);

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')
            ->with(FakeSubtitlePlugin::class)
            ->andReturnUsing(static fn (): FakeSubtitlePlugin => new FakeSubtitlePlugin());
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-opensubtitles', true);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-opensubtitles', false);

        $loader = $this->makeLoader($this->subtitleRegistry);

        for ($i = 0; $i < 5; $i++) {
            $loader->enable('phlix-plugin-opensubtitles');
            $this->assertCount(1, $this->subtitleRegistry->all());
            $loader->disable('phlix-plugin-opensubtitles');
            $this->assertCount(0, $this->subtitleRegistry->all());
        }
    }

    public function test_registration_skipped_when_no_subtitle_registry_injected(): void
    {
        $manifest = $this->manifest('phlix-plugin-opensubtitles', FakeSubtitlePlugin::class);
        $plugin = new FakeSubtitlePlugin();

        $this->expect($this->repository, 'findByName')->andReturn($this->makeInstalled($manifest));
        $this->expect($this->container, 'get')->with(FakeSubtitlePlugin::class)->andReturn($plugin);
        $this->expect($this->repository, 'setEnabled')->with('phlix-plugin-opensubtitles', true)->once();

        // No SubtitleSourceRegistry — enable must not fatal.
        $this->makeLoader(null)->enable('phlix-plugin-opensubtitles');

        $this->assertSame([], $this->subtitleRegistry->all());
    }
}

/**
 * Fake plugin entry class that is BOTH a lifecycle plugin and a typed subtitle
 * source — the shape phlix-plugin-opensubtitles adopts.
 */
final class FakeSubtitlePlugin implements LifecycleInterface, SubtitleSourceInterface
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

    public function getName(): string
    {
        return 'opensubtitles';
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function searchByPath(string $path, array $languages): array
    {
        return [];
    }

    public function searchByHash(string $movieHash, int $byteSize, array $languages): array
    {
        return [];
    }

    public function searchByImdbId(string $imdbId, array $languages): array
    {
        return [];
    }

    public function download(SubtitleCandidate $candidate): SubtitleFile
    {
        return new SubtitleFile(
            language: 'en',
            format: 'vtt',
            content: "WEBVTT\n",
            provider: 'opensubtitles',
            suggestedFilename: 'x.vtt',
        );
    }
}
