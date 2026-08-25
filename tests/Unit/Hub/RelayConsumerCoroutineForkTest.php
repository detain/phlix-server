<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;
use Workerman\Timer;
use Workerman\Worker;

/**
 * S196 — the `RelayConsumer::dispatchWithDeadlineInner()` coroutine fork on
 * both arms.
 *
 * Inside a coroutine the dispatch enforces a deadline via a Workerman timer
 * and converts a timed-out (504) dispatcher result into a sentinel
 * `sendHttpError()` + `null` return; outside one (the fallback arm) the
 * dispatcher's response is returned unchanged. The existing
 * `RelayConsumerTest` never enters a coroutine, so the deadline-enforcing arm
 * — the one a production worker's relay dispatch executes — was unexecuted by
 * the suite (the S170 defect class).
 *
 * Branch identity is OBSERVED through the documented outcome set: the SAME
 * 504-returning dispatcher yields `null` on the coroutine arm (the deadline
 * sentinel path) and the raw 504 response on the blocking arm. The Workerman
 * timer registry is seeded per the estate's established pattern
 * (`ApplicationBackupTimerTest`: `Worker::$workers` via reflection +
 * `Timer::delAll()`) so the arm's `Timer::add` cannot throw.
 */
final class RelayConsumerCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $workers = new \ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        /** @var array<int, Worker> $existing */
        $existing = $workers->getValue();
        $this->savedWorkers = $existing;

        $stub = new Worker();
        $workers->setValue(null, [spl_object_id($stub) => $stub]);

        Timer::delAll();
    }

    protected function tearDown(): void
    {
        Timer::delAll();

        $workers = new \ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        parent::tearDown();
    }

    private function buildConsumer(callable $dispatcher): RelayConsumer
    {
        $config = new RelayConfig();
        $hubClient = $this->createMock(HubClient::class);
        $logger = $this->createMock(StructuredLogger::class);

        return new RelayConsumer($config, $hubClient, $logger, 'server-1', httpDispatcher: $dispatcher);
    }

    private function envelope(): RelayHttpRequest
    {
        return new RelayHttpRequest(
            method: 'GET',
            path: '/api/v1/health',
            query: '',
            headers: [],
            body: '',
        );
    }

    /**
     * INSIDE a real coroutine, a 504 from the dispatcher must take the
     * deadline-sentinel path: the call returns null (the 504 was converted
     * into a relayed error response).
     */
    public function testCoroutineArmConvertsDispatcher504ToNull(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $dispatcher = static fn () => (new \Phlix\Server\Http\Response())
            ->status(504)
            ->text('relay request timed out');
        $consumer = $this->buildConsumer($dispatcher);

        $dispatch = new \ReflectionMethod(RelayConsumer::class, 'dispatchWithDeadlineInner');
        $dispatch->setAccessible(true);

        $result = $this->runInCoroutine(
            fn () => $dispatch->invoke($consumer, 42, $this->envelope())
        );

        $this->assertNull($result, 'the coroutine arm must swallow the 504 and report the timeout');
    }

    /**
     * OUTSIDE a coroutine the same 504-returning dispatcher must take the
     * fallback arm: the response is returned unchanged.
     */
    public function testBlockingArmPassesDispatcher504Through(): void
    {
        $dispatcher = static fn () => (new \Phlix\Server\Http\Response())
            ->status(504)
            ->text('relay request timed out');
        $consumer = $this->buildConsumer($dispatcher);

        $dispatch = new \ReflectionMethod(RelayConsumer::class, 'dispatchWithDeadlineInner');
        $dispatch->setAccessible(true);

        $result = $dispatch->invoke($consumer, 42, $this->envelope());

        $this->assertNotNull($result, 'the blocking arm must return the dispatcher response unchanged');
        $this->assertSame(504, $result->statusCode);
    }
}