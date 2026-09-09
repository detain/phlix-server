<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Media\Metadata\Writer\EmbeddedMetadataWriter;
use Phlix\Media\Metadata\Writer\MetadataWriterRegistry;
use Phlix\Media\Metadata\Writer\SidecarWriter;
use PHPUnit\Framework\TestCase;

/**
 * S88 ruling R1 — the built-in sidecar writer is registered via the DI
 * definition of MetadataWriterRegistry in MediaServicesProvider, NOT through
 * the PluginLoader capability arm. This is exactly the seam that makes the
 * writer visible to EVERY process that builds the container — the
 * `metadata-write` worker fork (start.php: ContainerFactory::create →
 * MediaServicesProvider is live-verified in the arc) and any future
 * admin/status consumer — WITHOUT spawning a fork or enabling a plugin, so
 * this container-build assertion is the proof.
 *
 * Also pins the PHP-DI caveat the S87 handoff recorded: `artworkStorage` must
 * be EXPLICITLY bound on SidecarWriter's autowire definition, because PHP-DI
 * skips defaulted optional ctor params — without the binding the writer would
 * silently get artworkStorage=null and never emit poster sidecars.
 *
 * S89 (ruling R4) — the embedded writer rides the SAME seam and this test
 * carries its mirror pins: registration, sidecar-first ordering (the R2
 * curation predicate's combined-drain semantics depend on it), and the
 * non-vacuous reflection check on the named optional params (`ffmpegPath`,
 * `logger`) the S88 round-1 review established as the only assertion that can
 * actually SEE a PHP-DI skip.
 */
final class SidecarWriterRegistrationTest extends TestCase
{
    private function buildContainer(array $appConfig = []): \DI\Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        // 'app.config' normally arrives from CoreServicesProvider; the artwork
        // storage-path factory only reads it defensively, so a bare array is
        // the minimal faithful stand-in for this provider-scoped build.
        // 'logger.media' mirrors CoreServicesProvider's alias so the embedded
        // writer's named logger binding resolves to the real channel class —
        // the NullLogger-vs-StructuredLogger distinction below is only
        // observable when the alias itself exists in the container.
        $definitions = [
            'app.config' => $appConfig,
            'logger.media' => static fn (): \Phlix\Common\Logger\StructuredLogger
                => \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::MEDIA),
        ];
        $builder->addDefinitions($definitions);
        (new MediaServicesProvider())->register($builder, $appConfig);

        return $builder->build();
    }

    public function test_the_container_built_registry_carries_the_builtin_sidecar_writer(): void
    {
        $registry = $this->buildContainer()->get(MetadataWriterRegistry::class);

        $this->assertInstanceOf(MetadataWriterRegistry::class, $registry);
        $this->assertTrue(
            $registry->has(SidecarWriter::class),
            'MediaServicesProvider must hand every container-scoped registry a registered SidecarWriter (R1)',
        );
        $this->assertSame([SidecarWriter::class, EmbeddedMetadataWriter::class], array_keys($registry->all()));
        $this->assertEqualsCanonicalizing(
            [SidecarWriter::class, EmbeddedMetadataWriter::class],
            array_map(static fn (object $w): string => $w::class, $registry->supporting('movie')),
        );
    }

    public function test_the_sidecar_writer_is_registered_before_the_embedded_writer(): void
    {
        // supporting()'s order is the registry's insertion order. Sidecar FIRST
        // is what makes the combined drain's R2 semantics documented in
        // EmbeddedMetadataWriter true: the machine re-stamps the stem NFO with
        // its generator marker before the embedded writer judges it.
        $registry = $this->buildContainer()->get(MetadataWriterRegistry::class);

        $order = array_map(static fn (object $w): string => $w::class, $registry->supporting('movie'));
        $this->assertSame([SidecarWriter::class, EmbeddedMetadataWriter::class], $order);
    }

    public function test_the_container_built_registry_carries_the_builtin_embedded_writer(): void
    {
        $registry = $this->buildContainer()->get(MetadataWriterRegistry::class);

        $this->assertTrue(
            $registry->has(EmbeddedMetadataWriter::class),
            'MediaServicesProvider must hand every container-scoped registry the built-in '
            . 'EmbeddedMetadataWriter (ruling R4: same seam, zero PluginLoader)',
        );
        $this->assertEqualsCanonicalizing(
            [SidecarWriter::class, EmbeddedMetadataWriter::class],
            array_map(static fn (object $w): string => $w::class, $registry->supporting('track')),
        );
        // Types NEITHER writer accepts must still resolve to zero writers —
        // supports() coverage, not registry presence, is the type gate.
        $this->assertSame([], $registry->supporting('book'));
    }

    public function test_the_registry_and_the_resolved_writer_are_the_same_container_singleton(): void
    {
        $container = $this->buildContainer();

        $registry = $container->get(MetadataWriterRegistry::class);
        $writer = $container->get(SidecarWriter::class);

        $this->assertSame(
            $writer,
            $registry->all()[SidecarWriter::class] ?? null,
            'the registry entry must BE the container-resolved writer',
        );

        $embedded = $container->get(EmbeddedMetadataWriter::class);
        $this->assertSame(
            $embedded,
            $registry->all()[EmbeddedMetadataWriter::class] ?? null,
            'the registry entry must BE the container-resolved embedded writer',
        );
    }

    public function test_the_container_built_writer_actually_received_its_artwork_storage_binding(): void
    {
        // The load-bearing half of the R1 seam: PHP-DI SKIPS defaulted optional
        // ctor params, so without the explicit constructorParameter binding on
        // SidecarWriter's definition the container-built writer would carry
        // artworkStorage=null and poster sidecars from the artwork cache would
        // silently never be written. Presence assertions cannot see that —
        // reflection on the RESOLVED instance can, and this test is removal-red
        // the moment the binding is deleted from MediaServicesProvider.
        $writer = $this->buildContainer()->get(SidecarWriter::class);

        $property = new \ReflectionProperty(SidecarWriter::class, 'artworkStorage');
        $property->setAccessible(true);
        $storage = $property->getValue($writer);

        $this->assertInstanceOf(
            \Phlix\Media\Storage\ArtworkStorage::class,
            $storage,
            'artworkStorage must be the explicitly-bound ArtworkStorage, not the null default',
        );
    }

    public function test_the_container_built_embedded_writer_received_its_named_optional_bindings(): void
    {
        // S88 round-1 discipline, mirrored (ruling R4): `ffmpegPath` and `logger`
        // are DEFAULTED ctor params, so PHP-DI silently skips them unless named.
        // Left implicit, the writer would run with NullLogger — every skip and
        // failure below the gates unlogged. Reflection on the RESOLVED instance
        // is the only non-vacuous assertion; this test is removal-red the moment
        // either constructorParameter is deleted from MediaServicesProvider.
        $container = $this->buildContainer();
        $writer = $container->get(EmbeddedMetadataWriter::class);

        $logger = new \ReflectionProperty(EmbeddedMetadataWriter::class, 'logger');
        $logger->setAccessible(true);
        $this->assertInstanceOf(
            \Psr\Log\LoggerInterface::class,
            $logger->getValue($writer),
            'logger must be the explicitly-bound media channel, not the NullLogger default',
        );
        $this->assertNotInstanceOf(
            \Psr\Log\NullLogger::class,
            $logger->getValue($writer),
            'an unwired NullLogger is exactly the silent-skip failure this pin exists to catch',
        );

        // The required (non-defaulted) policy params must be the SETTINGS-BOUND
        // container singletons — an EmbeddedWritePolicy autowired without the
        // factory definition would be store-less, and the R1 opt-in gate inert
        // by construction (a destructive default-off setting silently always-on
        // is the precise defect the MediaOverwritePolicy DI caveat warns about).
        $optIn = new \ReflectionProperty(EmbeddedMetadataWriter::class, 'optIn');
        $optIn->setAccessible(true);
        $this->assertSame(
            $container->get(\Phlix\Media\Metadata\EmbeddedWritePolicy::class),
            $optIn->getValue($writer),
            'the embedded writer must hold the container-singleton opt-in policy',
        );
        $overwrite = new \ReflectionProperty(EmbeddedMetadataWriter::class, 'overwritePolicy');
        $overwrite->setAccessible(true);
        $this->assertSame(
            $container->get(\Phlix\Media\Metadata\MetadataOverwritePolicy::class),
            $overwrite->getValue($writer),
            'the embedded writer must hold the container-singleton overwrite policy',
        );

        $runner = new \ReflectionProperty(EmbeddedMetadataWriter::class, 'runner');
        $runner->setAccessible(true);
        $this->assertInstanceOf(
            \Phlix\Media\Metadata\Writer\ExecExternalCommandRunner::class,
            $runner->getValue($writer),
            'the interface binding must resolve to the production exec runner',
        );
    }

    public function test_the_embedded_writer_ffmpeg_path_follows_config_and_not_the_ctor_default(): void
    {
        // Proves the `ffmpegPath` constructorParameter actually READS
        // config/ffmpeg.php's ffmpeg_path through register()'s $appConfig: with
        // a non-default config value, the resolved writer must carry THAT path.
        // Removal-red two ways — deleting the constructorParameter() call falls
        // back to the '/usr/bin/ffmpeg' literal default (red here), and so does
        // breaking the provider's config parse. (An assertion against the
        // shipped default value alone would be vacuous: PHP-DI's ctor default
        // and the config default coincide.)
        $writer = $this->buildContainer(['ffmpeg' => ['ffmpeg_path' => '/opt/custom/ffmpeg']])
            ->get(EmbeddedMetadataWriter::class);

        $ffmpegPath = new \ReflectionProperty(EmbeddedMetadataWriter::class, 'ffmpegPath');
        $ffmpegPath->setAccessible(true);
        $this->assertSame('/opt/custom/ffmpeg', $ffmpegPath->getValue($writer));

        // And a malformed/absent config entry must resolve to the shipped
        // default — never to null/'' interpolated into a command line.
        $fallback = $this->buildContainer(['ffmpeg' => ['ffmpeg_path' => 42]])
            ->get(EmbeddedMetadataWriter::class);
        $this->assertSame('/usr/bin/ffmpeg', $ffmpegPath->getValue($fallback));
    }
}
