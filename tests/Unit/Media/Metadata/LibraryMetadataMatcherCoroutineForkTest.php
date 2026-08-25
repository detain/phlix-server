<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;

/**
 * S196 — the `LibraryMetadataMatcher` coroutine fork on both arms.
 *
 * `matchBatchConcurrently()` degrades to per-item sequential processing when
 * `coroutineFanOutAvailable()` is false and takes the Channel-as-semaphore
 * bounded fan-out when true. The existing matcher test suite never enters a
 * coroutine, so the concurrent arm — the one a production worker's metadata
 * match pass executes — was unexecuted by the suite (the S170 defect class).
 *
 * Branch identity is OBSERVED via the per-item log CONTEXT: the sequential
 * arm logs `LibraryMetadataMatcher: item not matched` with `processed` and
 * `matched` counters in the context, the concurrent arm logs the same message
 * WITHOUT them. Both arms are driven through the REAL fork decision (the body
 * runs inside a real Swoole coroutine; the resolver is faked to return
 * "no match" so nothing persists).
 */
final class LibraryMetadataMatcherCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    /**
     * Recording bucket handed to closures (an object, so closures share it
     * unambiguously — array-by-reference returns do NOT survive list()
     * destructuring; measured S196 lesson).
     *
     * @return array{0: LibraryMetadataMatcher, 1: object{calls: list<array{message: string, context: array<string, mixed>}>}}
     */
    private function buildMatcher(): array
    {
        $record = new class {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $calls = [];
        };

        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('debug')->willReturnCallback(
            static function (string|Stringable $message, array $context = []) use ($record): void {
                $record->calls[] = ['message' => (string) $message, 'context' => $context];
            }
        );
        $logger->method('info')->willReturnCallback(
            static function (string|Stringable $message, array $context = []) use ($record): void {
                $record->calls[] = ['message' => (string) $message, 'context' => $context];
            }
        );
        $logger->method('warning')->willReturnCallback(
            static function (string|Stringable $message, array $context = []) use ($record): void {
                $record->calls[] = ['message' => (string) $message, 'context' => $context];
            }
        );

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturn(null);

        $items = $this->createMock(ItemRepository::class);

        $matcher = new LibraryMetadataMatcher(
            items: $items,
            resolver: $resolver,
            logger: $logger,
            forceRefresh: true,
        );

        return [$matcher, $record];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batchOf(int $count): array
    {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'id' => '00000000-0000-4000-8000-00000000000' . $i,
                'name' => 'Matrix ' . $i,
                'type' => 'movie',
            ];
        }

        return $batch;
    }

    private function notMatchedCalls(object $record): array
    {
        return array_values(array_filter(
            $record->calls,
            static fn (array $call): bool => $call['message'] === 'LibraryMetadataMatcher: item not matched'
        ));
    }

    /**
     * INSIDE a real coroutine, matchBatchConcurrently() must take the bounded
     * fan-out arm: every item is still visited, and the per-item log context
     * carries NO batch counters (the concurrent arm's shape).
     */
    public function testCoroutineArmFansOutBatch(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        [$matcher, $record] = $this->buildMatcher();

        $matchBatch = new \ReflectionMethod(LibraryMetadataMatcher::class, 'matchBatchConcurrently');
        $matchBatch->setAccessible(true);

        $result = $this->runInCoroutine(
            fn () => $matchBatch->invoke($matcher, $this->batchOf(3), null, 'library-1')
        );

        $this->assertSame(['matched' => 0, 'processed' => 3], $result);
        $notMatched = $this->notMatchedCalls($record);
        $this->assertCount(3, $notMatched, 'every item must be visited');
        $this->assertArrayNotHasKey(
            'processed',
            $notMatched[0]['context'],
            'the coroutine arm logs per-item results WITHOUT batch counters'
        );
    }

    /**
     * OUTSIDE a coroutine the same batch must take the sequential arm: every
     * item is still visited, and the per-item log context carries the batch
     * counters (the sequential arm's shape).
     */
    public function testBlockingArmProcessesBatchSequentially(): void
    {
        [$matcher, $record] = $this->buildMatcher();

        $matchBatch = new \ReflectionMethod(LibraryMetadataMatcher::class, 'matchBatchConcurrently');
        $matchBatch->setAccessible(true);

        $result = $matchBatch->invoke($matcher, $this->batchOf(2), null, 'library-1');

        $this->assertSame(['matched' => 0, 'processed' => 2], $result);
        $notMatched = $this->notMatchedCalls($record);
        $this->assertCount(2, $notMatched, 'every item must be visited');
        $this->assertArrayHasKey(
            'processed',
            $notMatched[0]['context'],
            'the sequential arm logs per-item results WITH the batch counters'
        );
        $this->assertSame(1, $notMatched[0]['context']['processed']);
        $this->assertSame(2, $notMatched[1]['context']['processed']);
    }
}