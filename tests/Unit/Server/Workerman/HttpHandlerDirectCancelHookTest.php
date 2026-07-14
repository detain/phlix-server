<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * @covers \Phlix\Server\Workerman\HttpHandler
 *
 * SV-4.2-disconnect, Chunk 2: the direct-LAN disconnect→kill wiring.
 *
 * A direct-LAN client (hitting the :8096 HTTP worker directly, NOT via the hub
 * relay) that disconnects mid-encode must get its on-demand ffmpeg segment
 * encode killed. {@see HttpHandler} arms a per-connection `onClose` before
 * dispatch that {@see SegmentProcessRegistry::killGroup()}s a per-request cancel
 * id, publishes that id into {@see RequestContext} so the encode registers under
 * it, and tears the hook down in the `finally`.
 *
 * These tests prove the wiring: the minted id is unique + monotonic, the
 * RequestContext carries it during dispatch and is cleared in the `finally`, the
 * `onClose` closure fires `killGroup`, the new caller inherits Chunk 1's
 * waiter-aware defer, and a normal completion resets `onClose` so a later socket
 * close cannot fire a stale kill.
 *
 * OUT OF SCOPE for unit tests (owed on-box verify): the real socket-close→onClose
 * timing while the handler coroutine is parked in `produceSegment`'s yieldable
 * poll — that cannot be exercised without a live event loop + a real FIN/RST.
 */
final class HttpHandlerDirectCancelHookTest extends TestCase
{
    protected function tearDown(): void
    {
        // The cancel group is per-coroutine context; clear it so a test that
        // arms-without-disarm cannot leak into the next test in this process.
        RequestContext::clearCancelGroup();
        parent::tearDown();
    }

    /**
     * @param array<int, int>               $signalled   Filled with each PID the
     *                                                   registry's signal sender receives.
     * @param (callable(string): bool)|null $waiterGuard Optional Chunk 1 waiter guard.
     */
    private function makeRegistry(array &$signalled, ?callable $waiterGuard = null): SegmentProcessRegistry
    {
        $signalled = [];
        $registry = new SegmentProcessRegistry(
            null,
            static function (int $pid, int $signal) use (&$signalled): void {
                $signalled[] = $pid;
            },
            // Report dead immediately so terminate() returns after a single
            // SIGTERM without a coroutine sleep / SIGKILL escalation.
            static fn (int $pid): bool => false,
            0.01,
            static function (string $tmp): void {
                // no-op temp cleaner (never touch the filesystem in tests)
            },
        );
        if ($waiterGuard !== null) {
            $registry->setWaiterGuard($waiterGuard);
        }

        return $registry;
    }

    private function makeHandler(SegmentProcessRegistry $registry, ?Application $application = null): HttpHandler
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($registry): mixed {
                if ($id === SegmentProcessRegistry::class) {
                    return $registry;
                }
                throw new \RuntimeException('unexpected container get: ' . $id);
            }
        );
        $container->method('has')->willReturn(true);

        $authenticator = new RequestAuthenticator($this->createMock(AuthManager::class));

        return new HttpHandler(
            $container,
            $authenticator,
            '/nonexistent/public',
            $application ?? $this->createMock(Application::class),
            null,
        );
    }

    private function makeConnection(): TcpConnection
    {
        $conn = $this->createMock(TcpConnection::class);
        $conn->bytesRead = 0;
        $conn->bytesWritten = 0;

        return $conn;
    }

    private function arm(HttpHandler $handler, TcpConnection $conn): string
    {
        $m = new ReflectionMethod(HttpHandler::class, 'armDirectCancelHook');
        $m->setAccessible(true);
        $id = $m->invoke($handler, $conn);
        self::assertIsString($id);

        return $id;
    }

    private function disarm(HttpHandler $handler, TcpConnection $conn): void
    {
        $m = new ReflectionMethod(HttpHandler::class, 'disarmDirectCancelHook');
        $m->setAccessible(true);
        $m->invoke($handler, $conn);
    }

    // --- SS-4(a): id minting is unique + monotonic --------------------------

    public function testMintsUniqueMonotonicIdsAcrossSequentialInvocations(): void
    {
        $signalled = [];
        $handler = $this->makeHandler($this->makeRegistry($signalled));

        // Same connection object reused (as a keep-alive connection would be):
        // bare spl_object_id() would collide here — the per-worker counter must not.
        $conn = $this->makeConnection();
        $id1 = $this->arm($handler, $conn);
        $id2 = $this->arm($handler, $conn);

        self::assertNotSame($id1, $id2, 'sequential requests on one connection must mint distinct ids');
        self::assertStringStartsWith('dl-', $id1);
        self::assertStringStartsWith('dl-', $id2);
        self::assertGreaterThan(
            (int) substr($id1, 3),
            (int) substr($id2, 3),
            'ids must be monotonically increasing',
        );
    }

    public function testMintsDistinctIdsAcrossConcurrentConnections(): void
    {
        $signalled = [];
        $handler = $this->makeHandler($this->makeRegistry($signalled));

        $id1 = $this->arm($handler, $this->makeConnection());
        $id2 = $this->arm($handler, $this->makeConnection());

        self::assertNotSame($id1, $id2, 'two concurrent connections must mint distinct ids');
    }

    // --- SS-4(b): the id is published into RequestContext -------------------

    public function testArmPublishesCancelGroupIntoRequestContext(): void
    {
        $signalled = [];
        $handler = $this->makeHandler($this->makeRegistry($signalled));
        $conn = $this->makeConnection();

        $id = $this->arm($handler, $conn);

        self::assertSame(
            $id,
            RequestContext::getCancelGroup(),
            'the minted id must be readable via the cancel group so the encode registers under it',
        );
        // TranscodeManager reads getRelayCancelGroup() — the alias must resolve
        // to the SAME value (one key backs both transports).
        self::assertSame($id, RequestContext::getRelayCancelGroup());
    }

    // --- SS-4(c): onClose is set and fires killGroup ------------------------

    public function testArmSetsOnCloseThatKillsTheRegisteredGroup(): void
    {
        $signalled = [];
        $registry = $this->makeRegistry($signalled);
        $handler = $this->makeHandler($registry);
        $conn = $this->makeConnection();

        $id = $this->arm($handler, $conn);

        // Model an encode launched during dispatch: a PID tracked under the id.
        $registry->register('/tmp/phlix_hls/job/seg-00042.ts', 9999, $id);
        self::assertSame([9999], $registry->pidsFor('/tmp/phlix_hls/job/seg-00042.ts'));

        $onClose = $conn->onClose;
        self::assertIsCallable($onClose, 'arm must set a per-connection onClose hook');

        // Fire it — this is what Workerman does when the socket FINs/RSTs.
        ($onClose)();

        self::assertSame([9999], $signalled, 'onClose must killGroup() the abandoned encode');
        self::assertSame(
            [],
            $registry->pidsFor('/tmp/phlix_hls/job/seg-00042.ts'),
            'the killed encode must be dropped from the registry',
        );
        self::assertSame(0, $registry->registeredGroupCount(), 'group torn down — no leak');
    }

    public function testOnCloseIsANoOpWhenNoEncodeWasRegistered(): void
    {
        $signalled = [];
        $registry = $this->makeRegistry($signalled);
        $handler = $this->makeHandler($registry);
        $conn = $this->makeConnection();

        $this->arm($handler, $conn);

        // A non-streaming request registers nothing — the disconnect hook is a
        // cheap no-op (killGroup on an empty group signals nothing).
        $onClose = $conn->onClose;
        self::assertIsCallable($onClose);
        ($onClose)();

        self::assertSame([], $signalled);
    }

    // --- Chunk 1 guard is inherited by the new caller ----------------------

    public function testOnCloseHonoursChunk1WaiterGuardAndDefers(): void
    {
        // A piggybacking peer is still waiting on the same segment: the shared
        // encode MUST NOT be killed on the launcher's disconnect (it would 404
        // the peer). Chunk 1's waiter guard defers it — and the NEW direct-LAN
        // caller inherits that defer for free.
        $signalled = [];
        $registry = $this->makeRegistry($signalled, static fn (string $key): bool => true);
        $handler = $this->makeHandler($registry);
        $conn = $this->makeConnection();

        $id = $this->arm($handler, $conn);
        $registry->register('/tmp/phlix_hls/job/seg-00099.ts', 7777, $id);

        $onClose = $conn->onClose;
        self::assertIsCallable($onClose);
        ($onClose)();

        self::assertSame([], $signalled, 'a piggybacked encode must NOT be signalled');
        self::assertSame(
            [7777],
            $registry->pidsFor('/tmp/phlix_hls/job/seg-00099.ts'),
            'the deferred encode stays fully tracked so the remaining waiter is served',
        );
    }

    // --- SS-4(d): teardown resets the hook + clears the group --------------

    public function testDisarmClearsCancelGroupAndNullsOnClose(): void
    {
        $signalled = [];
        $handler = $this->makeHandler($this->makeRegistry($signalled));
        $conn = $this->makeConnection();

        $this->arm($handler, $conn);
        self::assertIsCallable($conn->onClose);
        self::assertNotNull(RequestContext::getCancelGroup());

        $this->disarm($handler, $conn);

        self::assertNull($conn->onClose, 'onClose must be reset so a later close cannot fire a stale kill');
        self::assertNull(RequestContext::getCancelGroup(), 'the cancel group must be cleared after the request');
    }

    // --- Full __invoke: arm-before-dispatch + reset-in-finally --------------

    public function testInvokeArmsHookBeforeDispatchAndResetsInFinally(): void
    {
        $signalled = [];
        $registry = $this->makeRegistry($signalled);

        // The dispatcher stands in for the app: at dispatch time it captures the
        // armed onClose + the published cancel group (proving arm ran BEFORE
        // dispatch), and registers an encode under that group as TranscodeManager
        // would. It returns a non-404 so __invoke completes on the app-router path.
        $conn = $this->makeConnection();
        $captured = ['onClose' => 'UNSET', 'group' => 'UNSET'];
        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturnCallback(
            function ($request) use ($conn, $registry, &$captured): Response {
                $captured['onClose'] = $conn->onClose;
                $captured['group'] = RequestContext::getCancelGroup();
                if (is_string($captured['group'])) {
                    $registry->register('/tmp/phlix_hls/job/seg-invoke.ts', 4242, $captured['group']);
                }
                return (new Response())->status(200)->json(['ok' => true]);
            }
        );

        $handler = $this->makeHandler($registry, $application);
        $wr = new WorkermanRequest("GET /hls/job/seg-invoke.ts HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $handler->__invoke($conn, $wr);

        // During dispatch the hook was armed and the id was carried in context.
        self::assertIsCallable($captured['onClose'], 'onClose must be armed BEFORE dispatch');
        self::assertIsString($captured['group']);
        self::assertStringStartsWith('dl-', $captured['group']);

        // After a normal completion the hook is reset (SS-4(d)).
        self::assertNull($conn->onClose, 'onClose must be null after normal completion');
        self::assertNull(RequestContext::getCancelGroup(), 'cancel group must be cleared in the finally');

        // The onClose closure captured mid-dispatch still kills the encode that
        // registered under the request's group — the exact behaviour a real
        // mid-encode socket close triggers (the timing itself is the on-box verify).
        self::assertIsCallable($captured['onClose']);
        ($captured['onClose'])();
        self::assertSame([4242], $signalled, 'the armed onClose must kill the request-group encode');
    }
}
