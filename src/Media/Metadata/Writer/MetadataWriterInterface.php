<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

use Phlix\Media\Library\MediaItem;

/**
 * Capability contract for plugins that write canonical metadata BACK to disk (S87).
 *
 * The fourth arm of the typed plugin-capability pattern (after the metadata
 * source in Step 3.5, the subtitle source in F3, and the theme source in S84):
 * an enabled plugin whose entry instance implements THIS interface is wired
 * into the process-scoped {@see MetadataWriterRegistry} by
 * {@see \Phlix\Plugins\PluginLoader::enable()} — sniff-free, no method_exists()
 * or FQCN checks.
 *
 * S87 is plumbing ONLY: no writer ships with the server. The implementations
 * are the following steps of this arc —
 *   - S88: the sidecar writer (NFO + poster + fanart next to the media file),
 *     round-tripping against {@see \Phlix\Media\Metadata\LocalNfoProvider} and
 *     pre-flighting with is_writable() so an unwritable library reports a
 *     clear status instead of throwing;
 *   - S89: the embedded-tag writer (getid3_writetags / ffmpeg remux), default
 *     OFF, writing via atomic rename and consuming the EXISTING
 *     {@see \Phlix\Media\Metadata\MetadataOverwritePolicy} — it must not
 *     re-invent conflict handling.
 *
 * The host guarantees exactly one thing a writer implementation may rely on:
 * {@see self::write()} is NEVER called on the scan path, an HTTP worker, or
 * any other latency-sensitive loop. Jobs are enqueued by
 * {@see \Phlix\Media\Library\MediaScanner::processFile()} and drained by
 * {@see MetadataWriteWorker} in its own managed process, so blocking
 * filesystem I/O here cannot stall a scan or a request (S87 AC 2).
 *
 * Implementations MUST:
 *  - be safe to call repeatedly for the same item (a re-scan re-enqueues);
 *  - leave the previous on-disk state intact when they bail or fail —
 *    partial writes are how libraries get corrupted;
 *  - throw (not silently swallow) on failure — the worker logs and continues,
 *    and the failure must be loud enough to reach a future admin surface.
 */
interface MetadataWriterInterface
{
    /**
     * Whether this writer handles the given media-item type.
     *
     * Type values are the persisted `media_items.type` discriminators
     * ('movie', 'episode', 'track', 'book', 'audiobook', 'image', ...). The
     * worker consults this BEFORE invoking {@see self::write()}, so a writer
     * never sees a type it declined.
     */
    public function supports(string $type): bool;

    /**
     * Write canonical metadata for one item back to disk.
     *
     * Called ONLY from {@see MetadataWriteWorker} (background drain), never
     * inline in a scan or request (S87 AC 2).
     *
     * @param MediaItem              $item              The indexed item (path/name/type as persisted)
     * @param array<string, mixed>   $canonicalMetadata The canonical metadata map (decoded `metadata_json`)
     * @param string                 $mediaDir          Directory containing the media file (sidecar home)
     *
     * @throws \Throwable On write failure; the worker logs and proceeds to the next job.
     */
    public function write(MediaItem $item, array $canonicalMetadata, string $mediaDir): void;
}
