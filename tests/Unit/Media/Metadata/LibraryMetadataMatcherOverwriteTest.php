<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MetadataOverwritePolicy;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;

/**
 * Behaviour tests for the `metadata.overwrite_existing` gate wired through
 * {@see LibraryMetadataMatcher::shouldSkipOverwrite()}.
 *
 * These assert the CONSEQUENCE of the setting, not just its plumbing, across
 * every (re)resolve entry point so a half-wired subset is caught:
 *
 *  - the batch MOVIE path ({@see LibraryMetadataMatcher::matchItem()});
 *  - the batch SERIES subtree ({@see LibraryMetadataMatcher::matchSeries()} +
 *    {@see LibraryMetadataMatcher::enrichSeriesChildren()} — the season/episode
 *    overwrite sites);
 *  - the INTERACTIVE apply path
 *    ({@see LibraryMetadataMatcher::applyMatchResolved()}).
 *
 * The load-bearing invariant (plan_settings.md §4 rule 7): at the SHIPPED
 * DEFAULT (overwrite ON) an already-resolved item is still OVERWRITTEN — i.e.
 * behaviour is identical to before the setting existed. Only when an admin
 * turns it OFF is an already-resolved item skipped WHOLESALE.
 */
class LibraryMetadataMatcherOverwriteTest extends TestCase
{
    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * A {@see MetadataOverwritePolicy} whose effective value is fixed for the
     * test, built over a mocked {@see SettingsRepository} exactly as the DI
     * factory builds the real one.
     */
    private function policy(bool $overwrite): MetadataOverwritePolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->with(MetadataOverwritePolicy::SETTING_KEY)
            ->willReturn($overwrite);

        return new MetadataOverwritePolicy($settings);
    }

    /** A resolved-movie payload the resolver/tmdb mocks hand back. */
    private const RESOLVED_MOVIE = [
        'external_ids' => ['tmdb' => '603', 'imdb' => 'tt0133093'],
        'tmdb_id' => '603',
        'overview' => 'A hacker learns the truth.',
        'sources' => ['tmdb'],
    ];

    // ------------------------------------------------------------------
    // Batch MOVIE path (matchItem)
    // ------------------------------------------------------------------

    /**
     * DEFAULT (overwrite ON): a forced rescan of an item that ALREADY has
     * metadata OVERWRITES it — the current behaviour is preserved (rule 7).
     */
    public function testBatchMovieOverwriteOnRefreshesAlreadyResolvedItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('query')->willReturn(['total' => 1]);
        $items->method('getByLibrary')->willReturn([[
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata' => ['custom_flag' => true],
            'metadata_refreshed_at' => '2026-01-01 00:00:00',
        ]]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn(self::RESOLVED_MOVIE);

        // Overwrite happens: the merged metadata is persisted.
        $items->expects($this->once())->method('update');

        // Default policy = overwrite ON (no policy injected).
        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->logger());
        $matcher->setForceRefresh(true); // reach the item despite its stamp

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * OFF: a forced rescan of an item that ALREADY has metadata SKIPS it
     * wholesale — it is neither re-resolved nor re-persisted.
     */
    public function testBatchMovieOverwriteOffSkipsAlreadyResolvedItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('query')->willReturn(['total' => 1]);
        $items->method('getByLibrary')->willReturn([[
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata' => ['custom_flag' => true],
            'metadata_refreshed_at' => '2026-01-01 00:00:00',
        ]]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        // Skipped WHOLESALE: not even re-resolved.
        $resolver->expects($this->never())->method('resolve');

        // And never persisted.
        $items->expects($this->never())->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->logger(),
            overwritePolicy: $this->policy(false),
        );
        $matcher->setForceRefresh(true);

        $this->assertSame(['matched' => 0, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * OFF, but the item has NEVER been resolved (no metadata_refreshed_at): it
     * is still enriched — "don't overwrite" only protects items that already
     * have metadata, it does not freeze new items.
     */
    public function testBatchMovieOverwriteOffStillEnrichesNeverResolvedItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('query')->willReturn(['total' => 1]);
        $items->method('getByLibrary')->willReturn([[
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata' => [],
            // no metadata_refreshed_at → never resolved
        ]]);

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->expects($this->once())->method('resolve')->willReturn(self::RESOLVED_MOVIE);
        $items->expects($this->once())->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->logger(),
            overwritePolicy: $this->policy(false),
        );

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    // ------------------------------------------------------------------
    // Batch SERIES subtree (matchSeries + enrichSeriesChildren)
    // ------------------------------------------------------------------

    /**
     * OFF: a forced rescan of a series that ALREADY has metadata skips the WHOLE
     * subtree — the series root is not re-resolved/re-persisted AND its
     * season/episode children (the enrichSeriesChildren overwrite sites) are
     * never visited. This is what catches a half-wired subset that gated the
     * interactive season apply but not the batch series enrichment.
     */
    public function testBatchSeriesOverwriteOffSkipsWholeSubtree(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('query')->willReturn(['total' => 1]);
        $items->method('getByLibrary')->willReturn([[
            'id' => 's1',
            'type' => 'series',
            'name' => 'Some Show',
            'metadata' => ['series_title' => 'Some Show'],
            'metadata_refreshed_at' => '2026-01-01 00:00:00',
        ]]);

        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->expects($this->never())->method('resolve');

        // The whole subtree is skipped: no child walk, no persist.
        $items->expects($this->never())->method('findByParent');
        $items->expects($this->never())->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->logger(),
            overwritePolicy: $this->policy(false),
        );
        $matcher->setForceRefresh(true);

        $this->assertSame(['matched' => 0, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    /**
     * DEFAULT (overwrite ON): the same forced series rescan DOES re-resolve and
     * persist — proving the OFF-path skip above is caused by the setting, not by
     * the fixture, and that the default preserves today's behaviour.
     */
    public function testBatchSeriesOverwriteOnRefreshesAlreadyResolvedSeries(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('query')->willReturn(['total' => 1]);
        $items->method('getByLibrary')->willReturn([[
            'id' => 's1',
            'type' => 'series',
            'name' => 'Some Show',
            'metadata' => ['series_title' => 'Some Show'],
            'metadata_refreshed_at' => '2026-01-01 00:00:00',
        ]]);
        $items->method('findByParent')->willReturn([]);

        $seriesResolver = $this->createMock(SeriesMetadataResolver::class);
        $seriesResolver->expects($this->once())->method('resolve')->willReturn([
            'tmdb_id' => '1396',
            'external_ids' => ['tmdb' => '1396'],
            'overview' => 'A show.',
        ]);
        // The series root is re-persisted.
        $items->expects($this->atLeastOnce())->method('update');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $seriesResolver,
            $this->logger(),
            // default policy = overwrite ON
        );
        $matcher->setForceRefresh(true);

        $this->assertSame(['matched' => 1, 'processed' => 1], $matcher->matchLibrary('lib-1'));
    }

    // ------------------------------------------------------------------
    // Interactive apply (applyMatchResolved)
    // ------------------------------------------------------------------

    /**
     * OFF: an interactive apply on an item that ALREADY has metadata is skipped
     * wholesale — TMDB is not even queried and nothing is persisted; the result
     * reports matched=false.
     */
    public function testInteractiveApplyOverwriteOffSkipsAlreadyResolvedItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('m1')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata' => ['custom' => 'keep'],
            'metadata_refreshed_at' => '2026-01-01 00:00:00',
        ]);
        $items->expects($this->never())->method('update');

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->never())->method('getDetails');
        $tmdb->expects($this->never())->method('getTvDetails');

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $this->createMock(SeriesMetadataResolver::class),
            $this->logger(),
            $tmdb,
            overwritePolicy: $this->policy(false),
        );

        $result = $matcher->applyMatch('m1', '603', 'movie');

        $this->assertFalse($result['matched']);
        $this->assertSame(0, $result['children_enriched']);
        $this->assertSame('m1', $result['item_id']);
        $this->assertSame('603', $result['tmdb_id']);
    }

    /**
     * DEFAULT (overwrite ON): the same interactive apply on an already-resolved
     * item DOES query TMDB and persist — today's behaviour is preserved.
     */
    public function testInteractiveApplyOverwriteOnAppliesToAlreadyResolvedItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('m1')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata' => ['custom' => 'keep'],
            'metadata_refreshed_at' => '2026-01-01 00:00:00',
        ]);
        $items->expects($this->once())->method('update');

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->once())->method('getDetails')->with('603')->willReturn([
            'name' => 'The Matrix',
            'overview' => 'A hacker.',
            'tmdb_id' => '603',
            'imdb_id' => 'tt0133093',
        ]);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $this->createMock(SeriesMetadataResolver::class),
            $this->logger(),
            $tmdb,
            // default policy = overwrite ON
        );

        $result = $matcher->applyMatch('m1', '603', 'movie');

        $this->assertTrue($result['matched']);
        $this->assertSame('movie', $result['mode']);
    }
}
