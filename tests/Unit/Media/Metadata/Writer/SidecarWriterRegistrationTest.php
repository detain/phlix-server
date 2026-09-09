<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\MediaServicesProvider;
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
 */
final class SidecarWriterRegistrationTest extends TestCase
{
    private function buildContainer(): \DI\Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        // 'app.config' normally arrives from CoreServicesProvider; the artwork
        // storage-path factory only reads it defensively, so a bare array is
        // the minimal faithful stand-in for this provider-scoped build.
        $builder->addDefinitions(['app.config' => []]);
        (new MediaServicesProvider())->register($builder, []);

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
        $this->assertSame([SidecarWriter::class], array_keys($registry->all()));
        $this->assertEqualsCanonicalizing(
            [SidecarWriter::class],
            array_map(static fn (object $w): string => $w::class, $registry->supporting('movie')),
        );
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
}
