<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Request;
use Phlix\Server\Runtime\PidFile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the graceful restart endpoint (Phase 8).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller and is covered by the middleware's own tests.
 *
 * These tests deliberately cover the failure modes the original suite could
 * not, because it stubbed `sendSignal()` and asserted nothing about WHICH
 * signal was sent or WHERE the PID was read from:
 *
 *  - the pid file the controller reads is the SAME path `start.php` makes
 *    Workerman write (the config-consistency test — this is what would have
 *    caught the endpoint 500-ing on every real box);
 *  - the reload signal is **SIGUSR2** (Workerman's graceful reload), not
 *    SIGUSR1 (its non-graceful one);
 *  - the signal is **deferred** rather than fired inline, so the JSON ack
 *    flushes before the workers cycle.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminRestartController
 * @covers \Phlix\Server\Runtime\PidFile
 */
final class AdminRestartControllerTest extends TestCase
{
    /** Temp PID file path. */
    private string $pidFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pidFile = sys_get_temp_dir() . '/phlix_test_pid_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->pidFile)) {
            unlink($this->pidFile);
        }
        parent::tearDown();
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->body = [];

        return $request;
    }

    // ---------------------------------------------------------------------
    // PID file resolution — writer and reader must agree.
    // ---------------------------------------------------------------------

    /**
     * The endpoint reads `config/server.php`'s `worker.pid_file`; `start.php`
     * must make Workerman WRITE that same path. Before `PidFile` existed,
     * `Worker::$pidFile` was never assigned at all, so Workerman wrote
     * `dirname(start.php)/workerman.start.php.pid` while the controller looked
     * in `/var/run/phlix/pid` — a guaranteed HTTP 500 in production that no
     * test noticed.
     */
    public function testStartPhpAppliesTheSamePidPathTheControllerReads(): void
    {
        $repoRoot = dirname(__DIR__, 6);

        /** @var array<string, mixed> $config */
        $config = include $repoRoot . '/config/server.php';

        $configured = PidFile::configuredPath($config);
        self::assertNotNull($configured, 'config/server.php must declare worker.pid_file');

        // start.php must actually apply it — a bare config key nothing consumes
        // is exactly the defect this asserts against.
        $startPhp = file_get_contents($repoRoot . '/start.php');
        self::assertIsString($startPhp);
        self::assertStringContainsString(
            'PidFile::apply($config)',
            $startPhp,
            'start.php must assign Worker::$pidFile from config via PidFile::apply()',
        );

        // And the DI provider must hand the controller that same path.
        $provider = file_get_contents(
            $repoRoot . '/src/Common/Container/Providers/AdminServicesProvider.php'
        );
        self::assertIsString($provider);
        self::assertStringContainsString(
            "\$worker['pid_file']",
            $provider,
            'AdminServicesProvider must source the restart controller pid path from worker.pid_file',
        );
    }

    public function testPidFileConfiguredPathReadsTheWorkerBlock(): void
    {
        self::assertSame(
            '/var/run/phlix/pid',
            PidFile::configuredPath(['worker' => ['pid_file' => '/var/run/phlix/pid']]),
        );
        self::assertNull(PidFile::configuredPath([]));
        self::assertNull(PidFile::configuredPath(['worker' => []]));
        self::assertNull(PidFile::configuredPath(['worker' => ['pid_file' => '']]));
        self::assertNull(PidFile::configuredPath(['worker' => 'nope']));
    }

    public function testPidFileApplyReturnsNullForAnUnusableDirectory(): void
    {
        // Boot must not be aborted by an unwritable pid location — apply()
        // reports the failure and leaves Workerman's default in place.
        self::assertNull(PidFile::apply(['worker' => ['pid_file' => '/proc/phlix-nope/pid']]));
    }

    public function testPidFileApplyAssignsAUsablePath(): void
    {
        $path = sys_get_temp_dir() . '/phlix_pidfile_apply_' . uniqid('', true) . '/pid';

        self::assertSame($path, PidFile::apply(['worker' => ['pid_file' => $path]]));
        self::assertSame($path, \Workerman\Worker::$pidFile);

        @rmdir(dirname($path));
        \Workerman\Worker::$pidFile = '';
    }

    // ---------------------------------------------------------------------
    // Failure modes.
    // ---------------------------------------------------------------------

    public function testRestartFailsWhenPidFileIsMissing(): void
    {
        $controller = new AdminRestartController($this->pidFile);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('PID file not found', $body['error']);
    }

    public function testRestartFailsWhenPidFileIsEmpty(): void
    {
        file_put_contents($this->pidFile, '');

        $controller = new AdminRestartController($this->pidFile);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Invalid PID in file', $body['error']);
    }

    public function testRestartFailsWhenPidFileContainsNonNumericValue(): void
    {
        file_put_contents($this->pidFile, "not-a-pid\n");

        $controller = new AdminRestartController($this->pidFile);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Invalid PID in file', $body['error']);
    }

    public function testRestartFailsWhenTheMasterProcessIsNotSignalable(): void
    {
        file_put_contents($this->pidFile, '99999');

        $controller = new TestableRestartController($this->pidFile, false);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Signal send failed', $body['error']);

        // Only the probe ran; no reload signal was scheduled for a dead PID.
        self::assertSame([[99999, 0]], $controller->sent);
        self::assertSame([], $controller->scheduled);
    }

    // ---------------------------------------------------------------------
    // Happy path — the parts that were previously unasserted.
    // ---------------------------------------------------------------------

    public function testRestartSchedulesGracefulSigusr2AfterResponding(): void
    {
        file_put_contents($this->pidFile, '12345');

        $controller = new TestableRestartController($this->pidFile, true);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        self::assertSame('Restart signal sent', $body['message']);

        // The ONLY signal actually delivered inline is the no-op probe (0).
        // Anything else here would mean the master was signalled mid-request,
        // before the ack reached the socket (plan §3.35).
        self::assertSame([[12345, 0]], $controller->sent);

        // The real reload signal is deferred — and it is SIGUSR2 (Workerman's
        // GRACEFUL reload), never SIGUSR1 (its non-graceful one).
        self::assertSame([[12345, SIGUSR2]], $controller->scheduled);
        self::assertNotSame(SIGUSR1, $controller->scheduled[0][1]);
    }

    public function testRestartFailsWhenTheSignalCannotBeScheduled(): void
    {
        file_put_contents($this->pidFile, '12345');

        $controller = new TestableRestartController($this->pidFile, true, false);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Signal send failed', $body['error']);
    }

    /**
     * Outside a Workerman event loop (`Timer::add()` throws), the real
     * `scheduleSignal()` must fall back to sending the signal rather than
     * silently dropping the restart.
     */
    public function testScheduleSignalFallsBackWhenNoEventLoopIsRunning(): void
    {
        // Force the "no Workerman runtime" state deterministically: another test
        // in the suite may already have installed an event loop or registered a
        // Worker, in which case Timer::add() succeeds and this path is never
        // reached. Both statics are restored afterwards.
        $timerEvent = new \ReflectionProperty(\Workerman\Timer::class, 'event');
        $timerEvent->setAccessible(true);
        $previousEvent = $timerEvent->getValue();

        $workers = new \ReflectionProperty(\Workerman\Worker::class, 'workers');
        $workers->setAccessible(true);
        /** @var array<mixed> $previousWorkers */
        $previousWorkers = $workers->getValue();

        $timerEvent->setValue(null, null);
        $workers->setValue(null, []);

        try {
            $controller = new RecordingSendRestartController($this->pidFile);

            $method = new \ReflectionMethod(AdminRestartController::class, 'scheduleSignal');
            $method->setAccessible(true);

            self::assertTrue($method->invoke($controller, 4242, SIGUSR2));
            self::assertSame([[4242, SIGUSR2]], $controller->sent);
        } finally {
            $timerEvent->setValue(null, $previousEvent);
            $workers->setValue(null, $previousWorkers);
        }
    }

    // ---------------------------------------------------------------------
    // scheduleGracefulReload() — the reusable core S28 added for the DLNA
    // toggle. It returns a plain bool for every outcome, never a Response.
    // ---------------------------------------------------------------------

    public function testScheduleGracefulReloadReturnsFalseWhenPidFileMissing(): void
    {
        $controller = new TestableRestartController($this->pidFile, true);

        self::assertFalse($controller->scheduleGracefulReload());
        // Nothing was probed or scheduled — the missing file short-circuits.
        self::assertSame([], $controller->sent);
        self::assertSame([], $controller->scheduled);
    }

    public function testScheduleGracefulReloadReturnsFalseForNonNumericPid(): void
    {
        file_put_contents($this->pidFile, "not-a-pid\n");

        $controller = new TestableRestartController($this->pidFile, true);

        self::assertFalse($controller->scheduleGracefulReload());
        self::assertSame([], $controller->scheduled);
    }

    public function testScheduleGracefulReloadReturnsFalseWhenMasterUnsignalable(): void
    {
        file_put_contents($this->pidFile, '99999');

        // sendSignal() returns false → the probe fails → no reload scheduled.
        $controller = new TestableRestartController($this->pidFile, false);

        self::assertFalse($controller->scheduleGracefulReload());
        self::assertSame([[99999, 0]], $controller->sent);
        self::assertSame([], $controller->scheduled);
    }

    public function testScheduleGracefulReloadSchedulesSigusr2OnSuccess(): void
    {
        file_put_contents($this->pidFile, '12345');

        $controller = new TestableRestartController($this->pidFile, true);

        self::assertTrue($controller->scheduleGracefulReload());
        // Only the no-op probe (signal 0) runs inline; the real reload is
        // deferred and is SIGUSR2 (Workerman's GRACEFUL reload), not SIGUSR1.
        self::assertSame([[12345, 0]], $controller->sent);
        self::assertSame([[12345, SIGUSR2]], $controller->scheduled);
        self::assertNotSame(SIGUSR1, $controller->scheduled[0][1]);
    }

    /**
     * @param mixed $body
     *
     * @return array<string, mixed>
     */
    private function decode($body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

/**
 * Test double that records every signal sent and every signal scheduled, so
 * tests can assert WHICH signal is used and WHEN it is delivered.
 */
final class TestableRestartController extends AdminRestartController
{
    /** @var list<array{0:int,1:int}> Signals delivered inline. */
    public array $sent = [];

    /** @var list<array{0:int,1:int}> Signals deferred to the event loop. */
    public array $scheduled = [];

    private bool $signalResult;

    private bool $scheduleResult;

    public function __construct(string $pidFile, bool $signalResult, bool $scheduleResult = true)
    {
        parent::__construct($pidFile);
        $this->signalResult   = $signalResult;
        $this->scheduleResult = $scheduleResult;
    }

    protected function sendSignal(int $pid, int $signal): bool
    {
        $this->sent[] = [$pid, $signal];

        return $this->signalResult;
    }

    protected function scheduleSignal(int $pid, int $signal): bool
    {
        $this->scheduled[] = [$pid, $signal];

        return $this->scheduleResult;
    }
}

/**
 * Records `sendSignal()` calls but keeps the REAL `scheduleSignal()`, so the
 * no-event-loop fallback path can be exercised without signalling anything.
 */
final class RecordingSendRestartController extends AdminRestartController
{
    /** @var list<array{0:int,1:int}> */
    public array $sent = [];

    protected function sendSignal(int $pid, int $signal): bool
    {
        $this->sent[] = [$pid, $signal];

        return true;
    }
}
