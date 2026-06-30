<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Metadata\Resolution\SourceRegistry
 */
final class SourceRegistryTest extends TestCase
{
    public function testRegisterMakesSourceLookupable(): void
    {
        $registry = new SourceRegistry();
        $source = $this->fakeSource('anidb', ['anime', 'series']);

        $registry->register($source);

        $this->assertTrue($registry->has('anidb'));
        $this->assertSame($source, $registry->get('anidb'));
        $this->assertSame(['anidb'], $registry->names());
        $this->assertSame(['anidb' => $source], $registry->all());
    }

    public function testGetReturnsNullForUnknownSource(): void
    {
        $registry = new SourceRegistry();

        $this->assertFalse($registry->has('nope'));
        $this->assertNull($registry->get('nope'));
        $this->assertSame([], $registry->names());
        $this->assertSame([], $registry->all());
    }

    public function testDeregisterRemovesSource(): void
    {
        $registry = new SourceRegistry();
        $registry->register($this->fakeSource('anidb', ['anime']));

        $registry->deregister('anidb');

        $this->assertFalse($registry->has('anidb'));
        $this->assertNull($registry->get('anidb'));
        $this->assertSame([], $registry->all());
    }

    public function testDeregisterUnknownNameIsNoOp(): void
    {
        $registry = new SourceRegistry();
        $registry->register($this->fakeSource('anidb', ['anime']));

        $registry->deregister('myanimelist'); // not registered

        $this->assertTrue($registry->has('anidb'));
        $this->assertCount(1, $registry->all());
    }

    public function testDeregisterInstanceRemovesByItsSourceName(): void
    {
        $registry = new SourceRegistry();
        $source = $this->fakeSource('myanimelist', ['anime', 'series']);
        $registry->register($source);

        $registry->deregisterInstance($source);

        $this->assertFalse($registry->has('myanimelist'));
        $this->assertSame([], $registry->all());
    }

    public function testReRegisterIsIdempotentAndReplacesInstance(): void
    {
        $registry = new SourceRegistry();
        $first = $this->fakeSource('anidb', ['anime']);
        $second = $this->fakeSource('anidb', ['anime', 'series']);

        $registry->register($first);
        $registry->register($second);

        // Same key -> map never grows; latest instance wins.
        $this->assertCount(1, $registry->all());
        $this->assertSame($second, $registry->get('anidb'));
    }

    public function testEnableDisableCycleLeavesRegistryEmptyNoLeak(): void
    {
        $registry = new SourceRegistry();
        $source = $this->fakeSource('anidb', ['anime', 'series']);

        // Simulate many enable/disable cycles — the map must never grow.
        for ($i = 0; $i < 50; $i++) {
            $registry->register($source);
            $this->assertCount(1, $registry->all());
            $registry->deregisterInstance($source);
            $this->assertCount(0, $registry->all());
        }

        $this->assertSame([], $registry->all());
    }

    public function testForMediaTypeFiltersBySupportedTypes(): void
    {
        $registry = new SourceRegistry();
        $anidb = $this->fakeSource('anidb', ['anime', 'series']);
        $mal = $this->fakeSource('myanimelist', ['anime']);
        $registry->register($anidb);
        $registry->register($mal);

        $anime = $registry->forMediaType('anime');
        $this->assertSame(['anidb' => $anidb, 'myanimelist' => $mal], $anime);

        $series = $registry->forMediaType('series');
        $this->assertSame(['anidb' => $anidb], $series);

        $this->assertSame([], $registry->forMediaType('movie'));
    }

    /**
     * Build a minimal {@see MetadataSourceInterface} fake with the given
     * identity and supported types. The lookup triad returns empty values —
     * the registry never calls them, it only keys/filters on the identity.
     *
     * @param non-empty-string       $name
     * @param list<non-empty-string> $types
     */
    private function fakeSource(string $name, array $types): MetadataSourceInterface
    {
        return new class ($name, $types) implements MetadataSourceInterface {
            /**
             * @param non-empty-string       $name
             * @param list<non-empty-string> $types
             */
            public function __construct(
                private readonly string $name,
                private readonly array $types,
            ) {
            }

            public function sourceName(): string
            {
                return $this->name;
            }

            /** @return list<non-empty-string> */
            public function supportedMediaTypes(): array
            {
                return $this->types;
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
        };
    }
}
