<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Hub\HubClient;
use Phlix\Hub\RelayConfig;
use Phlix\Hub\RelayConsumer;
use Phlix\Server\Http\Response;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\Timer;
use Workerman\Worker;

/**
 * S196 + M3 — the `RelayConsumer::dispatchWithDeadlineInner()` coroutine fork on
 * both arms.
 *
 * Inside a coroutine the dispatch now runs the dispatcher in a CHILD coroutine
 * raced against `Swoole\Coroutine\Channel::pop($deadline)` in the parent: a
 * dispatcher that overruns its deadline gets a sentinel 504 sent by the parent
 * and a `null` return (the child is orphaned), while a dispatcher that RETURNS
 * — genuinely 504 or otherwise — has its response passed through UNCHANGED.
 * (The pre-M3 sentinel could never fire: the dispatcher ran synchronously in the
 * same coroutine and its late return overwrote the timer's 504, so an immediate
 * genuine 504 was misread as a timeout and any real overrun never reached the
 * check. These tests pin the corrected semantics.) Outside a coroutine (the
 * fallback arm) the dispatcher's response is returned unchanged. The existing
 * `RelayConsumerTest` never enters a coroutine, so the deadline-enforcing arm
 * — the one a production worker's relay dispatch executes — was unexecuted by
 * the suite (the S170 defect class).
 *
 * Branch identity is OBSERVED through the documented outcome set: the SAME
 * 504-returning dispatcher yields the raw 504 response on BOTH arms now — the
 * arms are distinguished by the overrun case (deadline 1 s, dispatcher sleeps
 * 2 s), which only the coroutine arm can time out on.
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

    private function buildConsumer(callable $dispatcher, ?int $deadlineSeconds = null): RelayConsumer
    {
        $config = new RelayConfig();
        $hubClient = $this->createMock(HubClient::class);
        $logger = $this->createMock(StructuredLogger::class);

        return new RelayConsumer(
            $config,
            $hubClient,
            $logger,
            'server-1',
            httpDispatcher: $dispatcher,
            dispatchDeadlineSeconds: $deadlineSeconds,
        );
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
     * Invoke the private deadline-enforcing body so both arms are testable
     * without a live tunnel (its sendHttpError() side effects no-op when the
     * consumer has no connection).
     *
     * @return Response|null
     */
    private function dispatch(RelayConsumer $consumer): ?Response
    {
        $dispatch = new \ReflectionMethod(RelayConsumer::class, 'dispatchWithDeadlineInner');
        $dispatch->setAccessible(true);

        /** @var Response|null $result */
        $result = $dispatch->invoke($consumer, 42, $this->envelope());
        return $result;
    }

    /**
     * OUTSIDE a coroutine the same 504-returning dispatcher must take the
     * fallback arm: the response is returned unchanged.
     */
    public function test_blocking_arm_passes_dispatcher_504_through(): void
    {
        $dispatcher = static fn (): Response => (new Response())
            ->status(504)
            ->text('relay request timed out');
        $consumer = $this->buildConsumer($dispatcher);

        $result = $this->dispatch($consumer);

        $this->assertNotNull($result, 'the blocking arm must return the dispatcher response unchanged');
        $this->assertSame(504, $result->statusCode);
    }

    /**
     * M3 REGRESSION PIN: INSIDE a coroutine, a dispatcher that RETURNS a genuine
     * 504 quickly must have it passed through UNCHANGED. Under the broken
     * pre-M3 sentinel this exact shape was swallowed and reported as a timeout —
     * a real gateway-timeout verdict from the app was indistinguishable from the
     * relay's own deadline, and the pass-through is what proves the parent took
     * the pop-returned branch of the race, not the timeout branch.
     */
    public function test_coroutine_arm_returns_genuine_dispatcher_504_unchanged(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $dispatcher = static fn (): Response => (new Response())
            ->status(504)
            ->text('genuine upstream timeout');
        $consumer = $this->buildConsumer($dispatcher);

        $result = $this->runInCoroutine(fn () => $this->dispatch($consumer));

        $this->assertNotNull($result, 'a genuine dispatcher 504 must NOT be swallowed as a deadline timeout');
        $this->assertSame(504, $result->statusCode);
        $this->assertSame('genuine upstream timeout', $result->body);
    }

    /**
     * THE DEADLINE ITSELF: a dispatcher that overruns the (test-shortened)
     * deadline must produce the null return (the parent answered 504 and
     * abandoned the request) while the slow child is orphaned. Coroutine\run
     * joins the orphan before returning, so the test spends ≈ the sleep length;
     * the assertion is that the PARENT returned null at the deadline — the
     * pre-M3 code could never reach this state on a never-returning dispatcher.
     */
    public function test_coroutine_arm_answers_timeout_when_dispatcher_overruns(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $dispatcher = static function (): Response {
            \Swoole\Coroutine::sleep(2.0);
            return (new Response())->status(200)->text('too late, nobody is listening');
        };
        $consumer = $this->buildConsumer($dispatcher, deadlineSeconds: 1);

        $result = $this->runInCoroutine(fn () => $this->dispatch($consumer));

        $this->assertNull($result, 'the deadline arm must answer 504 and return null once the dispatcher overruns');
    }

    /**
     * A dispatcher that throws inside the child coroutine must surface as the
     * documented 500 sentinel on the coroutine arm (the child's exception is
     * marshalled back through the channel, not allowed to escape the race).
     */
    public function test_coroutine_arm_converts_dispatcher_exception_to_500(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $dispatcher = static function (): Response {
            throw new RuntimeException('dispatcher exploded');
        };
        $consumer = $this->buildConsumer($dispatcher);

        $result = $this->runInCoroutine(fn () => $this->dispatch($consumer));

        $this->assertNotNull($result, 'a throwing dispatcher must yield the 500 sentinel, not null');
        $this->assertSame(500, $result->statusCode);
    }
}
