<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use Phlix\Media\Metadata\Writer\MetadataWriterRegistry;
use PHPUnit\Framework\TestCase;

/**
 * S87 — semantics of the process-scoped {@see MetadataWriterRegistry}.
 *
 * The registry is where the PluginLoader capability arm parks enabled writers
 * and where the MetadataWriteWorker reads them; its contract is the sibling
 * SubtitleSourceRegistry's: register-replaces (enable cycles never grow the
 * map), deregisterInstance truly removes (leak-free enable → disable), and
 * supporting() pre-filters by media type so a writer never sees a type it
 * declined (S88/S89 depend on exactly this dispatch).
 */
final class MetadataWriterRegistryTest extends TestCase
{
    public function test_register_is_idempotent_by_class_and_never_grows(): void
    {
        $registry = new MetadataWriterRegistry();
        $first = new RecordingWriter(types: ['movie']);
        $second = new RecordingWriter(types: ['movie']);

        $registry->register($first);
        $registry->register($second);

        $all = $registry->all();
        $this->assertCount(1, $all, 'Same writer class registered twice must REPLACE, never duplicate.');
        $this->assertSame($second, $all[RecordingWriter::class], 'The newest instance owns the slot.');
        $this->assertTrue($registry->has(RecordingWriter::class));
    }

    public function test_supporting_filters_registered_writers_by_type(): void
    {
        $registry = new MetadataWriterRegistry();
        $movieOnly = new RecordingWriter(types: ['movie']);
        $audioOnly = new SecondRecordingWriter(types: ['track', 'book']);
        $registry->register($movieOnly);
        $registry->register($audioOnly);

        $matching = $registry->supporting('track');

        $this->assertSame([$audioOnly], $matching, 'Only writers supporting the item type dispatch.');
        $this->assertSame([$movieOnly], $registry->supporting('movie'));
        $this->assertSame([], $registry->supporting('episode'), 'Unclaimed type matches nobody.');
    }

    public function test_deregister_instance_leaves_nothing_behind(): void
    {
        $registry = new MetadataWriterRegistry();
        $writer = new RecordingWriter(types: ['movie']);
        $registry->register($writer);

        $registry->deregisterInstance($writer);

        $this->assertSame([], $registry->all(), 'enable → disable cycle must end with an EMPTY registry.');
        $this->assertFalse($registry->has(RecordingWriter::class));
    }

    public function test_deregister_instance_does_not_evict_a_replacement(): void
    {
        $registry = new MetadataWriterRegistry();
        $old = new RecordingWriter(types: ['movie']);
        $fresh = new RecordingWriter(types: ['movie']);
        $registry->register($old);
        $registry->register($fresh);

        // Disabling a stale instance (re-enabled since) must not evict the slot owner.
        $registry->deregisterInstance($old);

        $this->assertSame([$fresh], array_values($registry->all()));
    }

    public function test_deregister_by_class_name(): void
    {
        $registry = new MetadataWriterRegistry();
        $registry->register(new RecordingWriter(types: ['movie']));

        $registry->deregister(RecordingWriter::class);

        $this->assertCount(0, $registry->all());
    }
}

/**
 * Distinct FQCN twin of the shared {@see RecordingWriter} double: the registry
 * keys by class, so a two-writer dispatch test needs two CLASSES (the
 * same-class case is the replace-semantics test above, not a second concurrent
 * writer). Co-located with this test because nothing else needs it.
 */
final class SecondRecordingWriter extends RecordingWriter
{
}
