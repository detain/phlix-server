<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\SimilarityJob;
use Phlix\Media\SimilarityJobStore;
use Phlix\Media\SimilarityService;
use Phlix\Media\SimilarityWorker;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * S196 — the `SimilarityWorker` coroutine fork on both arms.
 *
 * `runOnce()` picks `processConcurrently()` (Channel-as-semaphore fan-out)
 * when `isCoroutineContext()` is true and `processSequentially()` otherwise.
 * The existing `SimilarityWorkerTest` never enters a coroutine, so the
 * concurrent arm — the one a production worker's similarity pass executes —
 * was unexecuted by the suite (the S170 defect class).
 *
 * Branch identity is OBSERVED via the arm's own log line: the concurrent arm
 * logs `SimilarityWorker::processConcurrently Starting batch`, the sequential
 * arm logs `SimilarityWorker::processSequentially Starting batch`. Both arms
 * are driven through the REAL fork decision (the body runs inside a real
 * Swoole coroutine) against a REAL file-backed `SimilarityJobStore` over a
 * temp directory and the REAL `SimilarityService` over a mocked DB (the same
 * construction as `SimilarityWorkerTest` — the store and service are both
 * `final`); only the logger is faked.
 */
final class SimilarityWorkerCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    private string $queueDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueDir = sys_get_temp_dir() . '/phlix_similarity_fork_' . bin2hex(random_bytes(4));
        mkdir($this->queueDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->queueDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->queueDir);
        parent::tearDown();
    }

    /**
     * Builds a worker over a REAL store seeded with $jobCount jobs and a REAL
     * SimilarityService over mocked DB/repo (the `SimilarityWorkerTest`
     * construction), returning the worker with the recording bucket (an
     * object, so closures share it unambiguously — array-by-reference returns
     * do NOT survive list() destructuring; measured S196 lesson).
     *
     * @return array{0: SimilarityWorker, 1: object{messages: list<string>}}
     */
    private function buildWorker(int $jobCount): array
    {
        $store = new SimilarityJobStore($this->queueDir);
        for ($i = 0; $i < $jobCount; $i++) {
            $store->enqueue(new SimilarityJob('item-' . $i, 'library-1'));
        }

        $record = new class {
            /** @var list<string> */
            public array $messages = [];
        };

        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn([
            'id' => 'item-x',
            'metadata' => [
                'genres' => ['Action', 'Sci-Fi'],
                'actors' => ['Alice', 'Bob'],
                'directors' => ['Carol'],
                'rating' => 8.0,
                'year' => 2020,
            ],
        ]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'FROM media_items')) {
                    return [[
                        'id' => 'item-cand',
                        'metadata_json' => json_encode([
                            'genres' => ['Action'],
                            'actors' => ['Alice'],
                            'directors' => ['Carol'],
                            'rating' => 7.5,
                            'year' => 2019,
                        ]),
                    ]];
                }

                return [];
            }
        );

        $service = new SimilarityService($db, $itemRepo);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(
            static function (string $message) use ($record): void {
                $record->messages[] = $message;
            }
        );

        $worker = new SimilarityWorker($store, $service, $logger, 4);

        return [$worker, $record];
    }

    /**
     * INSIDE a real coroutine, runOnce() must take the concurrent arm: the
     * batch is processed, the queue drains, and the processConcurrently log
     * line is observed.
     */
    public function testCoroutineArmProcessesBatchConcurrently(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        [$worker, $record] = $this->buildWorker(3);

        $processed = $this->runInCoroutine(fn (): int => $worker->runOnce());

        $this->assertSame(3, $processed);
        $this->assertSame(0, $worker->getPendingCount(), 'every job must be drained from the real queue');
        $this->assertContains(
            'SimilarityWorker::processConcurrently Starting batch [count=3]',
            $record->messages,
            'the coroutine arm must take the concurrent path'
        );
        $this->assertNotContains(
            'SimilarityWorker::processSequentially Starting batch [count=3]',
            $record->messages
        );
    }

    /**
     * OUTSIDE a coroutine the same call must take the sequential arm: the
     * batch is processed, the queue drains, and the processSequentially log
     * line is observed.
     */
    public function testBlockingArmProcessesBatchSequentially(): void
    {
        [$worker, $record] = $this->buildWorker(2);

        $processed = $worker->runOnce();

        $this->assertSame(2, $processed);
        $this->assertSame(0, $worker->getPendingCount(), 'every job must be drained from the real queue');
        $this->assertContains(
            'SimilarityWorker::processSequentially Starting batch [count=2]',
            $record->messages,
            'the main-stack arm must take the sequential path'
        );
        $this->assertNotContains(
            'SimilarityWorker::processConcurrently Starting batch [count=2]',
            $record->messages
        );
    }
}
