<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Enrichment;

use Crell\Tukio\Dispatcher;
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\Enrichment\BackgroundEnrichmentSubscriber;
use Phlix\Media\Metadata\Enrichment\PluginEnrichmentQueue;
use Phlix\Media\Metadata\Enrichment\PluginMetadataEnricher;
use Phlix\Media\Metadata\Enrichment\SourceRateLimiter;
use Phlix\Media\Metadata\RatingService;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Tests\Unit\Media\Metadata\Resolution\FakeMetadataSource;
use PHPUnit\Framework\TestCase;

final class BackgroundEnrichmentSubscriberTest extends TestCase
{
    private function logger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function movieItem(array $metadata = []): array
    {
        return [
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'Film',
            'metadata' => $metadata,
            'metadata_json' => (string) json_encode($metadata),
        ];
    }

    private function enricher(SourceRegistry $registry, ItemRepository $items): PluginMetadataEnricher
    {
        return new PluginMetadataEnricher(
            $registry,
            $items,
            $this->createMock(RatingService::class),
            new PriorityConfig(['movie' => ['tmdb', 'imdb']]),
            new SourceRateLimiter([], null, static fn (): float => 0.0),
            $this->logger(),
        );
    }

    public function testMovieEnqueuesWithoutAnyHttp(): void
    {
        $source = new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']], ['runtime' => 99]);
        $registry = new SourceRegistry();
        $registry->register($source);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($this->movieItem());

        $queue = new PluginEnrichmentQueue(1.0, 100);
        $sub = new BackgroundEnrichmentSubscriber($queue, $this->enricher($registry, $items), $registry, $items, $this->logger());

        $sub->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/movies/x.mkv', 'movie'));

        $this->assertSame(1, $queue->size(), 'a movie must be enqueued');
        // The event handler must do NO HTTP — the source is never searched here.
        $this->assertSame(0, $source->searchCalls);
    }

    public function testNonEnrichableTypeDoesNotEnqueue(): void
    {
        $source = new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']]);
        $registry = new SourceRegistry();
        $registry->register($source);

        $items = $this->createMock(ItemRepository::class);
        // A non-enrichable type must be rejected on the type filter BEFORE any read.
        $items->expects($this->never())->method('findById');

        $queue = new PluginEnrichmentQueue(1.0, 100);
        $sub = new BackgroundEnrichmentSubscriber($queue, $this->enricher($registry, $items), $registry, $items, $this->logger());

        $sub->onMediaItemAdded(new MediaItemAdded('e1', 'lib', '/tv/x.mkv', 'episode'));
        $sub->onMediaItemAdded(new MediaItemAdded('t1', 'lib', '/music/x.flac', 'track'));

        $this->assertTrue($queue->isEmpty());
    }

    public function testAlreadyEnrichedItemIsNotReEnqueued(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']]));

        $items = $this->createMock(ItemRepository::class);
        // Item already carries an omdb marker → fully enriched for this registry.
        $items->method('findById')->willReturn($this->movieItem(['plugin_enriched' => ['omdb' => 12345]]));

        $queue = new PluginEnrichmentQueue(1.0, 100);
        $sub = new BackgroundEnrichmentSubscriber($queue, $this->enricher($registry, $items), $registry, $items, $this->logger());

        $sub->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/movies/x.mkv', 'movie'));

        $this->assertTrue($queue->isEmpty(), 'an already-enriched item must not be re-enqueued (no quota re-spend)');
    }

    public function testEmptyRegistryCausesNoWorkRule7(): void
    {
        $registry = new SourceRegistry(); // no source plugins enabled

        $items = $this->createMock(ItemRepository::class);
        // RULE 7: with no sources, the handler must not even read the item.
        $items->expects($this->never())->method('findById');
        $items->expects($this->never())->method('update');

        $queue = new PluginEnrichmentQueue(1.0, 100);
        $sub = new BackgroundEnrichmentSubscriber($queue, $this->enricher($registry, $items), $registry, $items, $this->logger());

        $sub->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/movies/x.mkv', 'movie'));

        $this->assertTrue($queue->isEmpty());
    }

    public function testRegisterSubscribesAndDispatchReachesHandler(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']]));

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($this->movieItem());

        $queue = new PluginEnrichmentQueue(1.0, 100);
        $sub = new BackgroundEnrichmentSubscriber($queue, $this->enricher($registry, $items), $registry, $items, $this->logger());

        $listeners = new ListenerRegistry();
        $id = $sub->register($listeners);
        $this->assertNotSame('', $id);

        // Dispatch a real MediaItemAdded through the same provider — the
        // subscription must route it to onMediaItemAdded and enqueue.
        $dispatcher = new Dispatcher($listeners->provider());
        $dispatcher->dispatch(new MediaItemAdded('m1', 'lib', '/movies/x.mkv', 'movie'));

        $this->assertSame(1, $queue->size());
    }

    public function testDrainTickEnrichesAQueuedItem(): void
    {
        $source = new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']], ['runtime' => 111]);
        $registry = new SourceRegistry();
        $registry->register($source);

        $updateCalls = 0;
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($this->movieItem(['title' => 'TMDB Title']));
        $items->method('update')->willReturnCallback(function () use (&$updateCalls): void {
            $updateCalls++;
        });

        $queue = new PluginEnrichmentQueue(1.0, 100);
        $sub = new BackgroundEnrichmentSubscriber($queue, $this->enricher($registry, $items), $registry, $items, $this->logger());

        $sub->onMediaItemAdded(new MediaItemAdded('m1', 'lib', '/movies/x.mkv', 'movie'));
        $this->assertSame(1, $queue->size());

        $sub->drainTick();

        $this->assertTrue($queue->isEmpty(), 'the drained item must leave the queue');
        $this->assertSame(1, $updateCalls, 'draining must persist the enrichment');
        $this->assertSame(1, $source->searchCalls, 'draining must consult the plugin source');
    }

    public function testDrainTickIsNoOpWhenQueueEmpty(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource('omdb', ['movie'], [['id' => 'tt1', 'title' => 'X']]));

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('update');

        $queue = new PluginEnrichmentQueue(1.0, 100);
        $sub = new BackgroundEnrichmentSubscriber($queue, $this->enricher($registry, $items), $registry, $items, $this->logger());

        $sub->drainTick(); // nothing queued → no-op, no throw
        $this->assertTrue($queue->isEmpty());
    }
}
