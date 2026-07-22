<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use Phlix\Shared\Metadata\MetadataSourceInterface;
use RuntimeException;

/**
 * Configurable in-memory {@see MetadataSourceInterface} test double for the F2
 * plugin-source dispatch tests. Records whether search()/getDetails() were
 * called (to prove the default path never touches plugin sources) and can be
 * told to throw (to prove per-source isolation).
 *
 * Not a PHPUnit test (no `Test` suffix) — a shared fixture.
 */
final class FakeMetadataSource implements MetadataSourceInterface
{
    public int $searchCalls = 0;

    public int $getDetailsCalls = 0;

    /**
     * @param non-empty-string          $name
     * @param list<non-empty-string>    $mediaTypes
     * @param list<array{id: non-empty-string, title: string}> $searchResults
     * @param array<string, mixed>      $details
     */
    public function __construct(
        private readonly string $name,
        private readonly array $mediaTypes = ['movie', 'series'],
        private readonly array $searchResults = [],
        private readonly array $details = [],
        private readonly bool $throwOnSearch = false,
    ) {
    }

    public function sourceName(): string
    {
        return $this->name;
    }

    public function supportedMediaTypes(): array
    {
        return $this->mediaTypes;
    }

    public function search(string $query, array $options = []): array
    {
        $this->searchCalls++;
        if ($this->throwOnSearch) {
            throw new RuntimeException('boom from ' . $this->name);
        }
        return $this->searchResults;
    }

    public function getDetails(string $externalId, array $options = []): array
    {
        $this->getDetailsCalls++;
        return $this->details;
    }

    public function getImages(string $externalId): array
    {
        return [];
    }
}
