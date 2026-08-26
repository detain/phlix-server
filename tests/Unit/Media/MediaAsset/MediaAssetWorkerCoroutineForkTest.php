<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\MediaAssetWorker;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * S196 — the `MediaAssetWorker` coroutine fork on both arms.
 *
 * `runOnce()` picks `processConcurrently()` (Channel-as-semaphore fan-out)
 * when `isCoroutineContext()` is true and `processSequentially()`, otherwise.
 * The existing `MediaAssetBackfillTest` never enters a coroutine, so the
 * concurrent arm — the one a production worker's asset generation executes —
 * was unexecuted by the suite (the S170 defect class).
 *
 * Branch identity is OBSERVED via the arm's own log line: the concurrent arm
 * logs `processConcurrently Starting batch`, the sequential arm logs
 * `processSequentially Starting batch`. Both arms are driven through the REAL
 * fork decision (the body runs inside a real Swoole coroutine) against a REAL
 * file-backed `MediaAssetJobStore` over a temp directory (the store is
 * `final` and the estate's convention is real-store tests — see
 * `MediaAssetBackfillTest`); only the processor and logger are faked.
 */
final class MediaAssetWorkerCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    private string $queueDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueDir = sys_get_temp_dir() . '/phlix_mediaasset_fork_' . bin2hex(random_bytes(4));
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
     * Builds a worker over a REAL store seeded with $jobCount jobs and
     * returns it with the recording bucket (an object, so closures share it
     * unambiguously — array-by-reference returns do NOT survive list()
     * destructuring; measured S196 lesson).
     *
     * @return array{0: MediaAssetWorker, 1: object{messages: list<string>}}
     */
    private function buildWorker(int $jobCount): array
    {
        $store = new MediaAssetJobStore($this->queueDir);
        for ($i = 0; $i < $jobCount; $i++) {
            $store->enqueue(new MediaAssetJob('item-' . $i, '/tmp/media/' . $i . '.mkv', 3600));
        }

        $record = new class {
            /** @var list<string> */
            public array $messages = [];
        };

        $processor = $this->createMock(MediaAssetGenerationJob::class);
        $processor->expects($this->exactly($jobCount))->method('process');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(
            static function (string $message) use ($record): void {
                $record->messages[] = $message;
            }
        );

        $worker = new MediaAssetWorker($store, $processor, $logger, 4);

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
            'MediaAssetWorker::processConcurrently Starting batch [count=3]',
            $record->messages,
            'the coroutine arm must take the concurrent path'
        );
        $this->assertNotContains(
            'MediaAssetWorker::processSequentially Starting batch [count=3]',
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
            'MediaAssetWorker::processSequentially Starting batch [count=2]',
            $record->messages,
            'the main-stack arm must take the sequential path'
        );
        $this->assertNotContains(
            'MediaAssetWorker::processConcurrently Starting batch [count=2]',
            $record->messages
        );
    }
}
