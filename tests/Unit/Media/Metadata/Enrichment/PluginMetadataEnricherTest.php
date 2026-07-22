<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Enrichment;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\Enrichment\EnrichmentOutcome;
use Phlix\Media\Metadata\Enrichment\PluginMetadataEnricher;
use Phlix\Media\Metadata\Enrichment\SourceRateLimiter;
use Phlix\Media\Metadata\Rating;
use Phlix\Media\Metadata\RatingService;
use Phlix\Media\Metadata\RatingSource;
use Phlix\Media\Metadata\RatingType;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Tests\Unit\Media\Metadata\Resolution\FakeMetadataSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Metadata\Enrichment\PluginMetadataEnricher
 */
final class PluginMetadataEnricherTest extends TestCase
{
    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    private function priority(): PriorityConfig
    {
        return new PriorityConfig(['movie' => ['tmdb', 'imdb'], 'series' => ['tmdb', 'imdb']]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function movieItem(array $metadata): array
    {
        return [
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'Some.File.2020',
            'metadata' => $metadata,
            'metadata_json' => (string) json_encode($metadata),
        ];
    }

    public function testDrainGapFillsUnderTmdbAndPersistsRatings(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource(
            'omdb',
            ['movie'],
            [['id' => 'tt1', 'title' => 'X']],
            [
                'title' => 'OMDB Title',   // must NOT clobber the existing TMDB title
                'runtime' => 120,          // gap-fills the missing runtime
                'ratings' => [['source' => 'imdb', 'score' => 8.0, 'votes' => 100]],
            ],
        ));

        $item = $this->movieItem([
            'title' => 'TMDB Title',
            'external_ids' => ['tmdb' => '603'],
        ]);

        /** @var array<string, mixed>|null $captured */
        $captured = null;
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('m1')->willReturn($item);
        $items->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $id, array $data) use (&$captured): void {
                $this->assertSame('m1', $id);
                $captured = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : null;
            });

        $ratings = $this->createMock(RatingService::class);
        $ratings->expects($this->once())
            ->method('upsert')
            ->with('m1', RatingSource::Imdb, RatingType::User, 8.0, 100)
            ->willReturn(new Rating(
                1,
                'm1',
                RatingSource::Imdb,
                RatingType::User,
                8.0,
                100,
                new \DateTimeImmutable('now'),
                new \DateTimeImmutable('now'),
            ));
        $ratings->expects($this->once())->method('aggregate')->with('m1');

        $enricher = new PluginMetadataEnricher(
            $registry,
            $items,
            $ratings,
            $this->priority(),
            new SourceRateLimiter([], null, static fn (): float => 0.0),
            $this->logger(),
        );

        $outcome = $enricher->enrichOne('m1');

        $this->assertSame(EnrichmentOutcome::Enriched, $outcome);
        $this->assertIsArray($captured);
        // Existing TMDB title preserved (plugin data lives UNDER it).
        $this->assertSame('TMDB Title', $captured['title']);
        // Missing field gap-filled from the plugin source.
        $this->assertSame(120, $captured['runtime']);
        // Existing external id preserved (plugin never overwrites tmdb id).
        $this->assertSame('603', $captured['external_ids']['tmdb']);
        // Durable idempotency marker stamped for the attempted source.
        $this->assertArrayHasKey('omdb', $captured['plugin_enriched']);
        $this->assertIsInt($captured['plugin_enriched']['omdb']);
    }

    public function testAlreadyEnrichedSourceIsNotReconsultedNorRepersisted(): void
    {
        $source = new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']], ['runtime' => 99]);
        $registry = new SourceRegistry();
        $registry->register($source);

        $item = $this->movieItem([
            'title' => 'TMDB Title',
            'plugin_enriched' => ['omdb' => 12345], // omdb already attempted
        ]);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($item);
        $items->expects($this->never())->method('update');

        $ratings = $this->createMock(RatingService::class);
        $ratings->expects($this->never())->method('upsert');

        $enricher = new PluginMetadataEnricher(
            $registry,
            $items,
            $ratings,
            $this->priority(),
            new SourceRateLimiter([], null, static fn (): float => 0.0),
            $this->logger(),
        );

        $this->assertSame(EnrichmentOutcome::Nothing, $enricher->enrichOne('m1'));
        // Proves quota is not re-spent: the source was never even searched.
        $this->assertSame(0, $source->searchCalls);
    }

    public function testEmptyRegistryIsNoOpRule7(): void
    {
        $registry = new SourceRegistry(); // no source plugins enabled

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($this->movieItem(['title' => 'TMDB Title']));
        $items->expects($this->never())->method('update');

        $ratings = $this->createMock(RatingService::class);
        $ratings->expects($this->never())->method('upsert');

        $enricher = new PluginMetadataEnricher(
            $registry,
            $items,
            $ratings,
            $this->priority(),
            new SourceRateLimiter([], null, static fn (): float => 0.0),
            $this->logger(),
        );

        $this->assertSame(EnrichmentOutcome::Nothing, $enricher->enrichOne('m1'));
    }

    public function testThrottledSourceDefersWithoutConsulting(): void
    {
        $source = new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']], ['runtime' => 99]);
        $registry = new SourceRegistry();
        $registry->register($source);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($this->movieItem(['title' => 'TMDB Title']));
        $items->expects($this->never())->method('update');

        // Rate limiter reports omdb as NOT due (already marked at t=0, tiny window).
        $limiter = new SourceRateLimiter(['omdb' => 90], null, static fn (): float => 0.0);
        $limiter->mark('omdb');

        $enricher = new PluginMetadataEnricher(
            $registry,
            $items,
            $this->createMock(RatingService::class),
            $this->priority(),
            $limiter,
            $this->logger(),
        );

        $this->assertSame(EnrichmentOutcome::Deferred, $enricher->enrichOne('m1'));
        $this->assertSame(0, $source->searchCalls, 'a throttled source must not be consulted');
    }

    public function testThrowingSourceIsSkippedWhileOthersStillPersist(): void
    {
        $bad = new FakeMetadataSource('bad', ['movie'], [], [], throwOnSearch: true);
        $good = new FakeMetadataSource('good', ['movie'], [['id' => 'g1', 'title' => 'G']], ['runtime' => 90]);
        $registry = new SourceRegistry();
        $registry->register($bad);
        $registry->register($good);

        /** @var array<string, mixed>|null $captured */
        $captured = null;
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($this->movieItem(['title' => 'TMDB Title']));
        $items->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $id, array $data) use (&$captured): void {
                $captured = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : null;
            });

        $enricher = new PluginMetadataEnricher(
            $registry,
            $items,
            $this->createMock(RatingService::class),
            $this->priority(),
            new SourceRateLimiter([], null, static fn (): float => 0.0),
            $this->logger(),
        );

        $this->assertSame(EnrichmentOutcome::Enriched, $enricher->enrichOne('m1'));
        $this->assertIsArray($captured);
        // The good source still contributed its gap-fill despite the bad one throwing.
        $this->assertSame(90, $captured['runtime']);
        // BOTH sources are marked attempted (bad included) so neither re-spends.
        $this->assertArrayHasKey('bad', $captured['plugin_enriched']);
        $this->assertArrayHasKey('good', $captured['plugin_enriched']);
        $this->assertSame(1, $bad->searchCalls);
        $this->assertSame(1, $good->searchCalls);
    }

    public function testNonEnrichableTypeIsNoOp(): void
    {
        $source = new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']], ['runtime' => 99]);
        $registry = new SourceRegistry();
        $registry->register($source);

        $episode = ['id' => 'e1', 'type' => 'episode', 'name' => 'ep', 'metadata' => [], 'metadata_json' => '{}'];
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($episode);
        $items->expects($this->never())->method('update');

        $enricher = new PluginMetadataEnricher(
            $registry,
            $items,
            $this->createMock(RatingService::class),
            $this->priority(),
            new SourceRateLimiter([], null, static fn (): float => 0.0),
            $this->logger(),
        );

        $this->assertSame(EnrichmentOutcome::Nothing, $enricher->enrichOne('e1'));
        $this->assertSame(0, $source->searchCalls);
    }
}
