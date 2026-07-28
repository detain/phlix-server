<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use PHPUnit\Framework\TestCase;

/**
 * The wiring for the resolver's season-coverage guard (bucket B): the matcher
 * must measure the local tree's highest NON-SPECIAL season and hand it to
 * {@see SeriesMetadataResolver::resolve()}.
 *
 * @covers \Phlix\Media\Metadata\LibraryMetadataMatcher
 */
final class LibraryMetadataMatcherSeasonSpanTest extends TestCase
{
    /**
     * Run one series through `matchLibrary()` and return the 5th argument the
     * series resolver was called with (the local season span).
     *
     * @param list<array<string, mixed>> $children Rows `findByParent('series-1')` returns.
     */
    private function capturedSeasonSpan(array $children): ?int
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'series-1', 'type' => 'series', 'name' => 'Battlestar Galactica', 'metadata' => []]],
            [],
        );
        $items->method('findByParent')->willReturnCallback(
            /** @return list<array<string, mixed>> */
            static fn(string $parentId): array => $parentId === 'series-1' ? $children : []
        );
        $items->method('update')->willReturnCallback(static function (): void {
        });

        $captured = null;
        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->method('resolve')->willReturnCallback(
            /**
             * @return array<string, mixed>|null
             */
            static function (
                string $title,
                ?int $year,
                mixed $priorityOverride = null,
                bool $includePluginSources = false,
                ?int $localHighestSeason = null
            ) use (&$captured): ?array {
                $captured = $localHighestSeason;
                return null; // stop before the enrichment walk; the arg is the assertion
            }
        );

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->createMock(StructuredLogger::class),
        );
        $matcher->matchLibrary('lib-1');

        return $captured;
    }

    public function testForwardsTheHighestLocalSeasonNumber(): void
    {
        $this->assertSame(4, $this->capturedSeasonSpan([
            ['id' => 's1', 'type' => 'season', 'name' => 'Season 1', 'metadata' => ['season' => 1]],
            ['id' => 's4', 'type' => 'season', 'name' => 'Season 4', 'metadata' => ['season' => 4]],
            ['id' => 's2', 'type' => 'season', 'name' => 'Season 2', 'metadata' => ['season' => 2]],
        ]));
    }

    /**
     * Season 0 is a `Specials/` folder, not evidence of a season count — and
     * TMDB's `number_of_seasons`, the value this is compared against, excludes
     * it too. A tree of nothing but specials therefore reports null.
     */
    public function testSpecialsDoNotCountTowardsTheSeasonSpan(): void
    {
        $this->assertSame(1, $this->capturedSeasonSpan([
            ['id' => 's0', 'type' => 'season', 'name' => 'Specials', 'metadata' => ['season' => 0]],
            ['id' => 's1', 'type' => 'season', 'name' => 'Season 1', 'metadata' => ['season' => 1]],
        ]));

        $this->assertNull($this->capturedSeasonSpan([
            ['id' => 's0', 'type' => 'season', 'name' => 'Specials', 'metadata' => ['season' => 0]],
        ]));
    }

    /** Episodes parented straight to the series (no season container) count too. */
    public function testEpisodesParentedDirectlyToTheSeriesCount(): void
    {
        $this->assertSame(3, $this->capturedSeasonSpan([
            ['id' => 'e1', 'type' => 'episode', 'name' => 'a', 'metadata' => ['season' => 3, 'episode' => 1]],
            ['id' => 'e2', 'type' => 'episode', 'name' => 'b', 'metadata' => ['season' => 2, 'episode' => 1]],
        ]));
    }

    /** A brand-new series with no children yet leaves the guard off. */
    public function testAChildlessSeriesReportsNoSpan(): void
    {
        $this->assertNull($this->capturedSeasonSpan([]));
    }

    /** Rows that are neither season nor episode, or carry no season, are ignored. */
    public function testUnnumberedAndUnrelatedChildrenAreIgnored(): void
    {
        $this->assertNull($this->capturedSeasonSpan([
            ['id' => 'x1', 'type' => 'movie', 'name' => 'stray', 'metadata' => ['season' => 9]],
            ['id' => 's1', 'type' => 'season', 'name' => 'Season ?', 'metadata' => []],
        ]));
    }
}
