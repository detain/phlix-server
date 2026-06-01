<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use RuntimeException;

/**
 * Unit tests for {@see LibraryMetadataMatcher}.
 *
 * Mocks {@see ItemRepository} + {@see MovieMetadataResolver} and asserts:
 *  - movie-typed items get resolved and their metadata_json merged + persisted
 *    (with metadata_refreshed_at stamped, existing keys preserved);
 *  - non-movie items are skipped (not counted, not resolved, not persisted);
 *  - a resolver returning null leaves the item unchanged (no update);
 *  - a per-item exception is swallowed and the run continues (one bad item does
 *    not abort the whole library).
 *
 * @covers \Phlix\Media\Metadata\LibraryMetadataMatcher
 */
class LibraryMetadataMatcherTest extends TestCase
{
    /**
     * A throwaway mock logger so the matcher's log calls do not hit disk.
     */
    private function makeLogger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * Movie items are resolved and their metadata merged + persisted; non-movie
     * items are skipped entirely.
     */
    public function testMatchesMovieItemsAndSkipsNonMovies(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('getByLibrary')
            ->with('lib-1', 100, 0)
            ->willReturn([
                [
                    'id' => 'item-movie',
                    'type' => 'movie',
                    'name' => 'The Matrix',
                    'metadata_json' => '{}',
                    'metadata' => ['custom_flag' => true, 'year' => 1999],
                ],
                [
                    'id' => 'item-track',
                    'type' => 'track',
                    'name' => 'Some Song',
                    'metadata_json' => '{}',
                    'metadata' => [],
                ],
            ]);

        $resolved = [
            'external_ids' => ['tmdb' => '603', 'imdb' => 'tt0133093'],
            'overview' => 'A hacker learns the truth.',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'genres' => ['Action'],
            'year' => 1999,
            'imdb_rating' => 8.7,
            'imdb_votes' => 1900000,
            'sources' => ['tmdb', 'imdb'],
        ];

        $resolver = $this->createMock(MovieMetadataResolver::class);
        // Only the movie item is resolved.
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('The Matrix', 1999, [])
            ->willReturn($resolved);

        // Persisted with the merge of existing metadata + resolved details and a
        // metadata_refreshed_at stamp.
        $items->expects($this->once())
            ->method('update')
            ->with(
                'item-movie',
                $this->callback(static function (mixed $data): bool {
                    if (!is_array($data)) {
                        return false;
                    }
                    $meta = $data['metadata_json'] ?? null;
                    if (!is_array($meta)) {
                        return false;
                    }
                    // Existing custom key preserved, resolver keys merged in.
                    return ($meta['custom_flag'] ?? null) === true
                        && ($meta['overview'] ?? null) === 'A hacker learns the truth.'
                        && ($meta['external_ids'] ?? null) === ['tmdb' => '603', 'imdb' => 'tt0133093']
                        && isset($data['metadata_refreshed_at'])
                        && is_string($data['metadata_refreshed_at']);
                })
            );

        $matcher = new LibraryMetadataMatcher($items, $resolver, $this->makeLogger());

        $result = $matcher->matchLibrary('lib-1');

        $this->assertSame(['matched' => 1, 'processed' => 1], $result);
    }

    /**
     * A `video`-typed item is also treated as a movie.
     */
    public function testMatchesVideoTypedItems(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-1', 'type' => 'video', 'name' => 'Inception', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('Inception', null, [])
            ->willReturn(['external_ids' => ['tmdb' => '27205'], 'sources' => ['tmdb']]);

        $items->expects($this->once())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, $this->makeLogger());

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * Existing external ids are passed through to the resolver.
     */
    public function testPassesExistingExternalIdsToResolver(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            [
                'id' => 'item-1',
                'type' => 'movie',
                'name' => 'The Matrix',
                'metadata' => ['external_ids' => ['imdb' => 'tt0133093']],
            ],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('The Matrix', null, ['imdb' => 'tt0133093'])
            ->willReturn(['external_ids' => ['imdb' => 'tt0133093', 'tmdb' => '603'], 'sources' => ['tmdb']]);

        $items->expects($this->once())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, $this->makeLogger());

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * When the resolver returns null (no match), the item is counted as
     * processed but NOT updated.
     */
    public function testResolverNullLeavesItemUnchanged(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-1', 'type' => 'movie', 'name' => 'Unknown Film', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->willReturn(null);

        // No persistence on a miss.
        $items->expects($this->never())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, $this->makeLogger());

        $this->assertSame(['matched' => 0, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * A per-item exception (resolver throws) is swallowed and the run continues
     * with the next item — one bad item does not abort the library.
     */
    public function testPerItemExceptionDoesNotAbortRun(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-bad', 'type' => 'movie', 'name' => 'Boom', 'metadata' => []],
            ['id' => 'item-good', 'type' => 'movie', 'name' => 'The Matrix', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static function (string $title): ?array {
                if ($title === 'Boom') {
                    throw new RuntimeException('resolver exploded');
                }
                return ['external_ids' => ['tmdb' => '603'], 'sources' => ['tmdb']];
            }
        );

        // Only the good item is persisted; the bad one is logged + skipped.
        $items->expects($this->once())->method('update')->with('item-good', $this->anything());

        $matcher = new LibraryMetadataMatcher($items, $resolver, $this->makeLogger());

        $result = $matcher->matchLibrary('lib-1');

        // Both movie items were processed; only one matched.
        $this->assertSame(['matched' => 1, 'processed' => 2], $result);
    }

    /**
     * An empty library yields zero counts and never calls the resolver.
     */
    public function testEmptyLibraryReturnsZeroCounts(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->once())
            ->method('getByLibrary')
            ->with('lib-empty', 100, 0)
            ->willReturn([]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $matcher = new LibraryMetadataMatcher($items, $resolver, $this->makeLogger());

        $this->assertSame(['matched' => 0, 'processed' => 0], $matcher->matchLibrary('lib-empty'));
    }

    /**
     * A movie item with no usable title is processed but not resolved/persisted.
     */
    public function testItemWithoutTitleIsSkipped(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturn([
            ['id' => 'item-1', 'type' => 'movie', 'name' => '', 'metadata' => []],
        ]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $items->expects($this->never())->method('update');

        $matcher = new LibraryMetadataMatcher($items, $resolver, $this->makeLogger());

        $this->assertSame(['matched' => 0, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * The run emits progress log entries as it goes — a start marker, a per-item
     * line (so the log shows items being processed instead of nothing until the
     * end), a per-batch progress summary, and a completion line — rather than a
     * single line written only when the whole run finishes.
     */
    public function testEmitsProgressLogEntriesAsItRuns(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [
                ['id' => 'm1', 'type' => 'movie', 'name' => 'The Matrix', 'metadata' => []],
                ['id' => 'm2', 'type' => 'movie', 'name' => 'Unknown Film', 'metadata' => []],
            ],
            [],
        );

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (string $title): ?array =>
                $title === 'The Matrix' ? ['external_ids' => ['tmdb' => '603']] : null,
        );

        $infoMessages = [];
        $debugMessages = [];
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('info')->willReturnCallback(
            static function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            }
        );
        $logger->method('debug')->willReturnCallback(
            static function (string $message) use (&$debugMessages): void {
                $debugMessages[] = $message;
            }
        );

        $matcher = new LibraryMetadataMatcher($items, $resolver, $logger);
        $matcher->matchLibrary('lib-1');

        // Start + per-batch progress + completion are all logged at INFO.
        $this->assertContains('LibraryMetadataMatcher: library match started', $infoMessages);
        $this->assertContains('LibraryMetadataMatcher: library match progress', $infoMessages);
        $this->assertContains('LibraryMetadataMatcher: library match complete', $infoMessages);

        // Each processed item produces a per-item DEBUG line as it happens.
        $this->assertContains('LibraryMetadataMatcher: item matched', $debugMessages);
        $this->assertContains('LibraryMetadataMatcher: item not matched', $debugMessages);
    }
}
