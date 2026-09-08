<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\Writer\MetadataWriteJob;
use Phlix\Media\Metadata\Writer\MetadataWriteJobStore;
use Phlix\Media\Metadata\Writer\MetadataWriteWorker;
use Phlix\Media\Metadata\Writer\MetadataWriterRegistry;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S87 — the queue CONSUMER: what drains a write job, what it hands a writer,
 * and what must stay true while no writers exist yet (the S87-era no-op).
 *
 * The worker is the ONLY sanctioned caller of MetadataWriterInterface::write()
 * — S87 AC 2's "never inline in the scan path" is enforced by there being no
 * other call site, and these tests pin the dispatch contract S88 (sidecar) and
 * S89 (embedded tags) will plug into: exact MediaItem, exact canonical
 * metadata map (fresh from the persisted row at drain time, NOT a queue-file
 * snapshot), exact sidecar directory, per-writer failure isolation, and drop
 * semantics for vanished rows.
 */
final class MetadataWriteWorkerTest extends TestCase
{
    private string $queueDir = '';

    protected function tearDown(): void
    {
        if ($this->queueDir !== '' && is_dir($this->queueDir)) {
            foreach ((array) glob($this->queueDir . '/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->queueDir);
        }
    }

    private function makeStore(): MetadataWriteJobStore
    {
        $this->queueDir = sys_get_temp_dir() . '/phlix_s87_wkq_' . uniqid('', true);
        return new MetadataWriteJobStore($this->queueDir);
    }

    /**
     * @param array<string, array<string, mixed>> $rows item id → row
     */
    private function makeRepo(array $rows): ItemRepository
    {
        return new RowLookupRepo($this->createMock(Connection::class), $rows);
    }

    private function movieRow(): array
    {
        return [
            'id' => 'item-1',
            'name' => 'Inception',
            'type' => 'movie',
            'path' => '/mnt/media/Inception (2010).mkv',
            'metadata_json' => json_encode(['title' => 'Inception', 'year' => 2010]),
        ];
    }

    public function test_runOnce_hands_the_writer_the_fresh_item_metadata_and_sidecar_dir(): void
    {
        $store = $this->makeStore();
        $registry = new MetadataWriterRegistry();
        $writer = new RecordingWriter(types: ['movie']);
        $registry->register($writer);
        $worker = new MetadataWriteWorker($store, $registry, $this->makeRepo(['item-1' => $this->movieRow()]));
        $store->enqueue(new MetadataWriteJob('item-1', 'lib-1'));

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed);
        $this->assertCount(1, $writer->calls, 'The queued job must reach exactly one write().');
        $call = $writer->calls[0];
        $this->assertInstanceOf(MediaItem::class, $call['item']);
        $this->assertSame('item-1', $call['item']->id);
        $this->assertSame(
            ['title' => 'Inception', 'year' => 2010],
            $call['metadata'],
            'Canonical metadata is re-read from the persisted row at drain time (S88/S89 contract).'
        );
        $this->assertSame('/mnt/media', $call['mediaDir'], 'Sidecar dir is the media file directory.');
        $this->assertSame(0, $store->queueSize(), 'Drained jobs leave the queue.');
    }

    public function test_unsupported_type_is_never_handed_to_a_writer(): void
    {
        $store = $this->makeStore();
        $registry = new MetadataWriterRegistry();
        $writer = new RecordingWriter(types: ['track']);
        $registry->register($writer);
        $worker = new MetadataWriteWorker($store, $registry, $this->makeRepo(['item-1' => $this->movieRow()]));
        $store->enqueue(new MetadataWriteJob('item-1', 'lib-1'));

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed, 'The job is still consumed — no writer took the type.');
        $this->assertSame([], $writer->calls, 'supports() gating must pre-filter before write().');
    }

    public function test_empty_registry_drains_without_error_s87_era_steady_state(): void
    {
        $store = $this->makeStore();
        $worker = new MetadataWriteWorker(
            $store,
            new MetadataWriterRegistry(),
            $this->makeRepo(['item-1' => $this->movieRow()])
        );
        $store->enqueue(new MetadataWriteJob('item-1', 'lib-1'));

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed, 'S87 ships NO writers: every drain is a clean no-op.');
        $this->assertSame(0, $store->queueSize(), 'The queue must not grow when nobody handles the type.');
    }

    public function test_vanished_item_drops_the_job_instead_of_throwing(): void
    {
        $store = $this->makeStore();
        $registry = new MetadataWriterRegistry();
        $writer = new RecordingWriter(types: ['movie']);
        $registry->register($writer);
        $worker = new MetadataWriteWorker($store, $registry, $this->makeRepo([]));
        $store->enqueue(new MetadataWriteJob('deleted-1', 'lib-1'));

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed);
        $this->assertSame([], $writer->calls, 'A row deleted between enqueue and drain writes nothing.');
    }

    public function test_throwing_writer_is_isolated_and_the_batch_continues(): void
    {
        $store = $this->makeStore();
        $registry = new MetadataWriterRegistry();
        $exploding = new RecordingWriter(types: ['movie']);
        $exploding->throwOnWrite = true;
        $reliable = new ReliableMovieWriter();
        $registry->register($exploding);
        $registry->register($reliable);
        $worker = new MetadataWriteWorker($store, $registry, $this->makeRepo(['item-1' => $this->movieRow()]));
        $store->enqueue(new MetadataWriteJob('item-1', 'lib-1'));

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed, 'A writer failure (S88 unwritable dir, S89 policy refusal) must not kill the drain.');
        $this->assertCount(1, $reliable->calls, 'Later writers still run after an earlier one throws.');
    }

    public function test_runOnce_on_empty_queue_is_zero(): void
    {
        $worker = new MetadataWriteWorker(
            $this->makeStore(),
            new MetadataWriterRegistry(),
            $this->makeRepo([])
        );

        $this->assertSame(0, $worker->runOnce());
        $this->assertSame(0, $worker->getPendingCount());
    }
}

/**
 * In-memory row lookup for the worker: findById() is the ONLY repository read
 * the drain performs, so the double stays honest about what production does.
 */
final class RowLookupRepo extends ItemRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $rows;

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    public function __construct(Connection $db, array $rows)
    {
        parent::__construct($db);
        $this->rows = $rows;
    }

    public function findById(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }
}

/**
 * Second writer for the isolation test — a throwing sibling must not shadow it.
 */
final class ReliableMovieWriter implements \Phlix\Media\Metadata\Writer\MetadataWriterInterface
{
    /** @var list<MediaItem> */
    public array $calls = [];

    public function supports(string $type): bool
    {
        return $type === 'movie';
    }

    public function write(MediaItem $item, array $canonicalMetadata, string $mediaDir): void
    {
        $this->calls[] = $item;
    }
}
