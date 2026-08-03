<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F2 caller-side rating persistence: the matcher (which owns the media_item_id)
 * upserts a resolve() result's `plugin_ratings` via {@see \Phlix\Media\Metadata\RatingService}.
 *
 */
final class LibraryMetadataMatcherPluginRatingsTest extends TestCase
{
    private function makeMatcher(SpyRatingService $ratingService): LibraryMetadataMatcher
    {
        return new LibraryMetadataMatcher(
            items: $this->createMock(ItemRepository::class),
            resolver: $this->createMock(MovieMetadataResolver::class),
            logger: $this->createMock(StructuredLogger::class),
            ratingService: $ratingService,
        );
    }

    /**
     * @param array<string, mixed> $resolved
     */
    private function invokePersist(LibraryMetadataMatcher $matcher, string $id, array $resolved): void
    {
        $method = new ReflectionMethod($matcher, 'persistPluginRatings');
        $method->setAccessible(true);
        $method->invoke($matcher, $id, $resolved);
    }

    public function testUpsertsEnumValidRatingsAndDropsInvalidSources(): void
    {
        $spy = new SpyRatingService();
        $matcher = $this->makeMatcher($spy);

        $this->invokePersist($matcher, 'item-1', [
            'plugin_ratings' => [
                ['source' => 'imdb', 'score' => 8.7, 'votes' => 1900000],
                ['source' => 'rt', 'score' => 8.8],
                ['source' => 'metacritic', 'score' => 60.0], // NOT in the DB ENUM → dropped
            ],
        ]);

        // Only the two enum-valid sources were persisted.
        $this->assertCount(2, $spy->upserts);
        $this->assertSame('imdb', $spy->upserts[0]['source']);
        $this->assertSame(8.7, $spy->upserts[0]['score']);
        $this->assertSame(1900000, $spy->upserts[0]['votes']);
        $this->assertSame('rt', $spy->upserts[1]['source']);
        $this->assertNull($spy->upserts[1]['votes']);
        // Aggregate recomputed once after persisting.
        $this->assertSame(1, $spy->aggregateCalls);
    }

    public function testNoPluginRatingsIsANoOp(): void
    {
        $spy = new SpyRatingService();
        $matcher = $this->makeMatcher($spy);

        // A resolve() result from the default (scan) path carries no plugin_ratings.
        $this->invokePersist($matcher, 'item-1', ['sources' => ['tmdb', 'imdb']]);

        $this->assertSame([], $spy->upserts);
        $this->assertSame(0, $spy->aggregateCalls);
    }
}
