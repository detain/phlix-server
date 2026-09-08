<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\Writer\MetadataWriterInterface;
use RuntimeException;

/**
 * Recording writer double for the S87 writer-suite
 * ({@see MetadataWriterRegistryTest}, {@see MetadataWriteWorkerTest}): declares
 * the types it supports and logs every write() call, so dispatch tests can
 * assert the EXACT arguments an S88 (sidecar) / S89 (embedded-tag) writer will
 * receive — item, canonical metadata map, sidecar dir.
 *
 * In its OWN PSR-4 file (rather than inline in one test) so both S87 suites
 * autoload it independently and each test run stays single-file executable —
 * the CollectionGateScannerRepo precedent. Not final so the registry test can
 * derive a distinct-FQCN twin (the registry keys by class).
 */
class RecordingWriter implements MetadataWriterInterface
{
    /** @var list<array{item: MediaItem, metadata: array<string, mixed>, mediaDir: string}> */
    public array $calls = [];

    /** @var list<string> */
    private array $types;

    public bool $throwOnWrite = false;

    /**
     * @param list<string> $types
     */
    public function __construct(array $types = ['movie'])
    {
        $this->types = $types;
    }

    public function supports(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function write(MediaItem $item, array $canonicalMetadata, string $mediaDir): void
    {
        if ($this->throwOnWrite) {
            throw new RuntimeException('writer failed deliberately');
        }

        $this->calls[] = [
            'item' => $item,
            'metadata' => $canonicalMetadata,
            'mediaDir' => $mediaDir,
        ];
    }
}
