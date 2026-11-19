<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Extras;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Extras\ExtrasRepository;
use Phlix\Media\Extras\Extra;
use Phlix\Media\Extras\Trailer;
use Phlix\Media\Extras\TrailerFinder;
use Phlix\Media\Extras\TrailerResolver;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbProvider;

class TrailerResolverTest extends TestCase
{
    private TrailerResolver $resolver;
    private ItemRepository&MockObject $itemRepository;
    private TmdbProvider&MockObject $tmdb;
    private ExtrasRepository&MockObject $extras;
    private TrailerFinder&MockObject $trailerFinder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itemRepository = $this->createMock(ItemRepository::class);
        $this->tmdb = $this->createMock(TmdbProvider::class);
        $this->extras = $this->createMock(ExtrasRepository::class);
        $this->trailerFinder = $this->createMock(TrailerFinder::class);

        $this->resolver = new TrailerResolver(
            $this->itemRepository,
            $this->tmdb,
            $this->extras,
            $this->trailerFinder,
            86400
        );
    }

    public function testGetTrailersMergesLocalAndTmdb(): void
    {
        $mediaItemId = 'media-123';

        // Mock cache valid
        $this->extras->method('isCacheValid')->willReturn(true);

        // Mock database returns
        $dbRows = [
            [
                'id' => 'trailer-local',
                'media_item_id' => $mediaItemId,
                'title' => 'Official Trailer',
                'source' => 'local',
                'url' => 'file:///path/trailer.mkv',
                'duration' => 120,
                'quality' => 1080,
                'file_path' => '/path/trailer.mkv',
            ],
            [
                'id' => 'trailer-tmdb',
                'media_item_id' => $mediaItemId,
                'title' => 'Trailer (TMDB)',
                'source' => 'tmdb',
                'url' => 'https://youtube.com/watch?v=abc',
                'duration' => 0,
                'quality' => 0,
                'file_path' => '',
            ],
        ];

        $this->extras->method('findTrailersByMediaItemId')->willReturn($dbRows);

        $trailers = $this->resolver->getTrailers($mediaItemId);

        $this->assertCount(2, $trailers);
        $this->assertInstanceOf(Trailer::class, $trailers[0]);
        $this->assertInstanceOf(Trailer::class, $trailers[1]);
    }

    public function testLocalTrailersTakePriorityOverTmdb(): void
    {
        $mediaItemId = 'media-123';

        // Mock cache valid
        $this->extras->method('isCacheValid')->willReturn(true);

        // Mock database returns trailers with same title from different sources
        $dbRows = [
            [
                'id' => 'trailer-local',
                'media_item_id' => $mediaItemId,
                'title' => 'Official Trailer',
                'source' => 'local',
                'url' => 'file:///path/trailer.mkv',
                'duration' => 150,
                'quality' => 1080,
                'file_path' => '/path/trailer.mkv',
            ],
            [
                'id' => 'trailer-tmdb',
                'media_item_id' => $mediaItemId,
                'title' => 'Official Trailer',
                'source' => 'tmdb',
                'url' => 'https://youtube.com/watch?v=abc',
                'duration' => 0,
                'quality' => 0,
                'file_path' => '',
            ],
        ];

        $this->extras->method('findTrailersByMediaItemId')->willReturn($dbRows);

        $trailers = $this->resolver->getTrailers($mediaItemId);

        // Local trailer should come first (lower priority source, but it's still merged)
        $this->assertCount(2, $trailers);
        $this->assertSame('local', $trailers[0]->source);
        $this->assertSame('tmdb', $trailers[1]->source);
    }

    public function testGetExtrasReturnsOnlyNonTrailerExtras(): void
    {
        $mediaItemId = 'media-123';

        // Mock cache valid
        $this->extras->method('isCacheValid')->willReturn(true);

        // Mock database returns only non-trailer extras
        $dbRows = [
            [
                'id' => 'extra-featurette',
                'media_item_id' => $mediaItemId,
                'title' => 'Making Of',
                'extra_type' => 'featurette',
                'source' => 'tmdb',
                'url' => 'https://youtube.com/watch?v=xyz',
                'duration' => 600,
                'quality' => 720,
                'file_path' => '',
            ],
        ];

        $this->extras->method('findNonTrailerExtrasByMediaItemId')->willReturn($dbRows);

        $extras = $this->resolver->getExtras($mediaItemId);

        $this->assertCount(1, $extras);
        $this->assertInstanceOf(Extra::class, $extras[0]);
        $this->assertSame('featurette', $extras[0]->type);
        $this->assertSame('Making Of', $extras[0]->title);
    }

    public function testGetAllExtrasSortsByTypePriority(): void
    {
        $mediaItemId = 'media-123';

        // Mock cache valid
        $this->extras->method('isCacheValid')->willReturn(true);

        // Mock trailers
        $trailerRows = [
            [
                'id' => 'trailer-1',
                'media_item_id' => $mediaItemId,
                'title' => 'Trailer',
                'source' => 'local',
                'url' => 'file:///path/trailer.mkv',
                'duration' => 120,
                'quality' => 1080,
                'file_path' => '/path/trailer.mkv',
            ],
        ];

        // Mock non-trailer extras
        $extraRows = [
            [
                'id' => 'extra-bts',
                'media_item_id' => $mediaItemId,
                'title' => 'Behind the Scenes',
                'extra_type' => 'behind_the_scenes',
                'source' => 'tmdb',
                'url' => 'https://youtube.com/watch?v=bts',
                'duration' => 300,
                'quality' => 720,
                'file_path' => '',
            ],
        ];

        $this->extras->method('findTrailersByMediaItemId')->willReturn($trailerRows);
        $this->extras->method('findNonTrailerExtrasByMediaItemId')->willReturn($extraRows);

        $allExtras = $this->resolver->getAllExtras($mediaItemId);

        $this->assertCount(2, $allExtras);
        // Trailer should be first (priority 1)
        $this->assertInstanceOf(Trailer::class, $allExtras[0]);
        // Behind the scenes should be second (priority 3)
        $this->assertInstanceOf(Extra::class, $allExtras[1]);
        $this->assertSame('behind_the_scenes', $allExtras[1]->type);
    }

    public function testGetTrailersCachesResultFor24h(): void
    {
        $mediaItemId = 'media-123';

        // First call: cache invalid, should trigger refresh
        $this->extras->expects($this->once())
            ->method('isCacheValid')
            ->with($mediaItemId, 86400)
            ->willReturn(false);

        // Mock item lookup for refresh - use with() to ensure proper matching
        $this->itemRepository->expects($this->once())
            ->method('findById')
            ->with($mediaItemId)
            ->willReturn([
                'id' => $mediaItemId,
                'path' => '/path/to/movie.mkv',
                'metadata' => ['tmdb_id' => '12345'],
            ]);

        // Expect delete to be called before insert
        $this->extras->expects($this->once())
            ->method('deleteByMediaItemId')
            ->with($mediaItemId);

        // Mock trailer finder returns one local trailer
        $this->trailerFinder->expects($this->once())
            ->method('findLocalTrailers')
            ->willReturn([
                [
                    'path' => '/path/to/trailer.mkv',
                    'title' => 'Trailer',
                    'duration' => 120,
                    'quality' => 1080,
                ],
            ]);

        // Mock TMDB returns empty
        $this->tmdb->expects($this->once())
            ->method('getTrailers')
            ->willReturn([]);

        // Expect batch insert to be called
        $this->extras->expects($this->once())->method('batchInsert');

        // The batchInsert() expectation configured above is the assertion for
        // this path; getTrailers() returns the merged trailer list.
        $this->resolver->getTrailers($mediaItemId);
    }

    /**
     * E-09 cohort 5a legacy oracle: the two build*FromDb closures of
     * TrailerResolver reshaped through RowMap must be byte-identical to the
     * pre-fold strict-guard ternaries (transcribed verbatim below from the
     * 6cd39c5d state of src/Media/Extras/TrailerResolver.php) across the whole
     * type domain the SELECT * FROM media_extras feed can deliver.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function legacyTrailerShape(array $row): array
    {
        $source = is_string($row['source'] ?? null) ? $row['source'] : '';
        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
            'mediaItemId' => is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '',
            'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
            'source' => $source,
            'url' => is_string($row['url'] ?? null) ? $row['url'] : '',
            'duration' => is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : 0,
            'quality' => is_numeric($row['quality'] ?? null) ? (int) $row['quality'] : 0,
            'isLocal' => $source === 'local',
            'filePath' => is_string($row['file_path'] ?? null) ? $row['file_path'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function legacyExtraShape(array $row): array
    {
        $source = is_string($row['source'] ?? null) ? $row['source'] : '';
        $type = is_string($row['extra_type'] ?? null) ? $row['extra_type'] : 'clip';
        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
            'mediaItemId' => is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '',
            'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
            'type' => $type,
            'source' => $source,
            'url' => is_string($row['url'] ?? null) ? $row['url'] : '',
            'duration' => is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : 0,
            'quality' => is_numeric($row['quality'] ?? null) ? (int) $row['quality'] : 0,
            'isLocal' => $source === 'local',
            'filePath' => is_string($row['file_path'] ?? null) ? $row['file_path'] : '',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function trailerRowVectors(): array
    {
        $typed = [
            'id' => 'tr-1',
            'media_item_id' => 'm-1',
            'title' => 'Official Trailer',
            'extra_type' => 'trailer',
            'source' => 'local',
            'url' => 'file:///x.mkv',
            'file_path' => '/x.mkv',
            'duration' => 120,
            'quality' => 1080,
            'cached_at' => '2026-01-15 10:30:00',
        ];
        $nulls = array_fill_keys(array_keys($typed), null);
        $numericStrings = $typed + ['duration' => '120', 'quality' => '720p', 'source' => 'tmdb'];
        $decimalStrings = $typed + ['duration' => '12.9', 'quality' => '1080.0'];
        $floatsAndBools = $typed + ['duration' => 120.0, 'quality' => true, 'source' => false];
        $wrongTypes = $typed + [
            'id' => 5,
            'media_item_id' => [],
            'title' => 1.5,
            'url' => true,
            'file_path' => ['a'],
            'duration' => ['120'],
        ];
        $empties = $typed + ['source' => '', 'url' => '', 'file_path' => '', 'duration' => '', 'extra_type' => ''];
        $missing = $typed;
        unset($missing['duration'], $missing['quality'], $missing['file_path'], $missing['source']);
        return [
            'typed' => $typed,
            'null-columns' => $nulls,
            'numeric-strings' => $numericStrings,
            'decimal-strings-truncate-like-legacy-cast' => $decimalStrings,
            'floats-and-bools' => $floatsAndBools,
            'wrong-types' => $wrongTypes,
            'empty-strings' => $empties,
            'missing-keys' => $missing,
        ];
    }

    public function testDatabaseRowShapingIsByteIdenticalToLegacyAcrossVectors(): void
    {
        foreach ($this->trailerRowVectors() as $label => $row) {
            $extras = $this->createMock(ExtrasRepository::class);
            $extras->method('isCacheValid')->willReturn(true);
            $extras->method('findTrailersByMediaItemId')->willReturn([$row]);
            $extras->method('findNonTrailerExtrasByMediaItemId')->willReturn([$row]);
            $resolver = new TrailerResolver(
                $this->createMock(ItemRepository::class),
                $this->createMock(TmdbProvider::class),
                $extras,
                $this->createMock(TrailerFinder::class),
                86400
            );

            $trailers = $resolver->getTrailers('m-1');
            $this->assertCount(1, $trailers, $label);
            $trailer = $trailers[0];
            $this->assertSame($this->legacyTrailerShape($row), [
                'id' => $trailer->id,
                'mediaItemId' => $trailer->mediaItemId,
                'title' => $trailer->title,
                'source' => $trailer->source,
                'url' => $trailer->url,
                'duration' => $trailer->duration,
                'quality' => $trailer->quality,
                'isLocal' => $trailer->isLocal,
                'filePath' => $trailer->filePath,
            ], 'trailer leg: ' . $label);

            $extraList = $resolver->getExtras('m-1');
            $this->assertCount(1, $extraList, $label);
            $extra = $extraList[0];
            $this->assertSame($this->legacyExtraShape($row), [
                'id' => $extra->id,
                'mediaItemId' => $extra->mediaItemId,
                'title' => $extra->title,
                'type' => $extra->type,
                'source' => $extra->source,
                'url' => $extra->url,
                'duration' => $extra->duration,
                'quality' => $extra->quality,
                'isLocal' => $extra->isLocal,
                'filePath' => $extra->filePath,
            ], 'extra leg: ' . $label);
        }
    }
}
