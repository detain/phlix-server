<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

/**
 * Process-scoped registry of enabled {@see MetadataWriterInterface} plugin
 * instances (S87).
 *
 * Fourth sibling of {@see \Phlix\Media\Subtitles\SubtitleSourceRegistry} and
 * friends: a single container-scoped instance shared by
 * {@see \Phlix\Plugins\PluginLoader} (which registers/deregisters on
 * enable/disable, including the boot-time `bootstrapEnabled()` re-attach) and
 * {@see MetadataWriteWorker} (which reads it from its own managed process
 * AFTER calling bootstrapEnabled() there — registries are per-process resident
 * state, so every consumer fork wires its own copy of the enabled set).
 *
 * Unlike the source registries, entries are keyed by WRITER CLASS NAME:
 * {@see MetadataWriterInterface} deliberately carries no `getName()` — a
 * writer is identified by what it is (one FQCN per plugin autoload root), not
 * by a plugin-chosen string — and class names cannot collide across plugins.
 * {@see self::register()} replaces an existing entry for the same class, so
 * enable → disable → enable is idempotent and never grows the map.
 *
 * Leak-free enable/disable contract (same as the sibling registries):
 * `count(all()) === 0` after every enabled plugin has been disabled —
 * S88 scope note: for the bare `new MetadataWriterRegistry()` instances the
 * unit suites hand to PluginLoader. The CONTAINER-built registry additionally
 * carries the built-in {@see SidecarWriter} and (since S89)
 * {@see EmbeddedMetadataWriter} from its DI definition, so there the floor
 * after a full plugin-disable cycle is those built-in entries, never zero.
 *
 * @since S87
 */
final class MetadataWriterRegistry
{
    /** @var array<string, MetadataWriterInterface> Registered writers keyed by FQCN */
    private array $writers = [];

    /**
     * Register a writer instance; replaces any previous instance of the same class.
     */
    public function register(MetadataWriterInterface $writer): void
    {
        $this->writers[$writer::class] = $writer;
    }

    /**
     * Truly remove THIS instance's registration (matched by object identity).
     *
     * Disabling plugin A can never evict plugin B's same-named-slot entry:
     * same-class instances are container singletons, and an instance that was
     * replaced by a re-enable is left alone — the NEW registration belongs to
     * whoever holds it now.
     */
    public function deregisterInstance(MetadataWriterInterface $writer): void
    {
        $key = $writer::class;
        if (($this->writers[$key] ?? null) === $writer) {
            unset($this->writers[$key]);
        }
    }

    /**
     * Remove the registration for a writer class.
     *
     * @param class-string<MetadataWriterInterface> $writerClass
     */
    public function deregister(string $writerClass): void
    {
        unset($this->writers[$writerClass]);
    }

    public function has(string $writerClass): bool
    {
        return isset($this->writers[$writerClass]);
    }

    /**
     * @return array<string, MetadataWriterInterface> Registered writers keyed by FQCN
     */
    public function all(): array
    {
        return $this->writers;
    }

    /**
     * Every registered writer that accepts the given media-item type.
     *
     * @return list<MetadataWriterInterface>
     */
    public function supporting(string $type): array
    {
        $matching = [];
        foreach ($this->writers as $writer) {
            if ($writer->supports($type)) {
                $matching[] = $writer;
            }
        }

        return $matching;
    }
}
