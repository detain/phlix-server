<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Network\NatPmpClient;
use Phlix\Network\PortForwardService;
use Phlix\Network\StunClient;
use Phlix\Network\UpnpIgdClient;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * S197 — a swoole failure must not escape a block written to contain it.
 *
 * ## THE DEFECT
 *
 * Five `try` blocks in `src/Network/` wrap `Swoole\Coroutine\Socket` work and end in
 * `catch (RuntimeException $e)`, each with a comment saying the failure is contained
 * and the code degrades to a blocking fallback. **`Swoole\Exception` is not a
 * `RuntimeException`** — measured on swoole 6.2.1 by walking the parent chain:
 *
 *     Swoole\Exception -> Exception -> END
 *     Swoole\Coroutine\Socket\Exception -> Swoole\Exception -> Exception -> END
 *
 * so none of the five could contain the one class the swoole socket API is
 * documented to raise, and none could contain the `\Error` their own S146 comment
 * says they were written for either (`\Error` is not an `Exception` at all, which is
 * why the fix is `\Throwable` and not the narrower `\Exception`).
 * {@see testTheClassesTheOldCatchCouldNotContain} pins that hierarchy, because the
 * whole defect is a subtyping fact rather than a logic error.
 *
 * ## ⚠ WHY THESE TESTS CAN LOOK GREEN WHILE PROVING NOTHING — TWO WAYS
 *
 * 1. **PHPUnit never runs inside a coroutine.** Every guarded block sits behind
 *    `if (self::inCoroutine() && class_exists(Swoole\Coroutine\Socket::class))`, and
 *    `Swoole\Coroutine::getCid()` is `-1` on the main stack with the extension
 *    loaded. A test written the obvious way never enters the branch at all. Every
 *    test below therefore runs through {@see RunsInCoroutine} AND asserts
 *    `seamCalls() === 1`, which is only incremented from inside the branch —
 *    so a test that stopped entering it goes red instead of quietly passing.
 * 2. **A `RuntimeException` plant passes before and after.** These tests throw the
 *    real `Swoole\Exception`, plus its concrete subclass and an `\Error`, and never a
 *    `RuntimeException`.
 *
 * ## ⚠ WHY A SEAM (`createCoroutineSocket()`) EXISTS AT ALL
 *
 * Because on this build **nothing inside those try blocks can be provoked into
 * throwing**, so without it the widened catch is untestable. Measured 2026-08-03,
 * swoole 6.2.1 / PHP 8.3.6:
 *
 *   - `connect()` to an unroutable host — returns false, errCode 111. No throw.
 *   - `connect()`/`getsockname()`/`close()` on an already-closed socket — return
 *     false. No throw.
 *   - a second `close()` — returns false. No throw. (So the double `close()` this
 *     step also removes was dead code, NOT a fault: see
 *     {@see testTheSocketIsClosedExactlyOnceWhenTheLocalAddressIsUnusable}.)
 *   - the same socket used from two coroutines at once — returns false, errCode 114.
 *   - `Swoole\Coroutine::cancel()` on a coroutine parked in `connect()` — no throw.
 *   - a genuinely failing `socket(2)`, reproduced twice (EMFILE via an FFI
 *     `setrlimit(RLIMIT_NOFILE, 16)`, and an invalid socket type) — **SIGSEGV inside
 *     `new`, straight through an enclosing `catch (\Throwable)`**. It does not raise
 *     `Swoole\Coroutine\Socket\Exception` on this build at all.
 *
 * That last one is worth stating plainly because it cuts against the step's own
 * premise: on swoole 6.2.1 a construction failure kills the worker regardless of how
 * wide the catch is. The catch is still wrong and still worth widening — the class it
 * must contain exists, swoole's API contract says the constructor raises it, the
 * `\Error` case is real, and `probeViaCoroutineSocket()` in the same file already
 * catches `\Throwable` for exactly these reasons — but nobody should read a green
 * suite here as "a swoole socket failure is now survivable".
 */
class CoroutineSocketFailureContainmentTest extends TestCase
{
    use RunsInCoroutine;

    /**
     * The subtyping fact the whole step rests on, asserted rather than reasoned.
     */
    public function testTheClassesTheOldCatchCouldNotContain(): void
    {
        $this->assertTrue(extension_loaded('swoole'), 'swoole is expected in CI and on the dev box');

        $swooleException = new \Swoole\Exception('planted');
        $socketException = new \Swoole\Coroutine\Socket\Exception('planted');
        $error = new \Error('planted');

        // What the OLD `catch (RuntimeException $e)` could actually contain: none of them.
        $this->assertNotInstanceOf(RuntimeException::class, $swooleException);
        $this->assertNotInstanceOf(RuntimeException::class, $socketException);
        $this->assertNotInstanceOf(RuntimeException::class, $error);

        // Why the fix is \Throwable and not the narrower \Exception: \Error is not one.
        $this->assertInstanceOf(\Exception::class, $swooleException);
        $this->assertNotInstanceOf(\Exception::class, $error);
        $this->assertInstanceOf(\Throwable::class, $swooleException);
        $this->assertInstanceOf(\Throwable::class, $socketException);
        $this->assertInstanceOf(\Throwable::class, $error);

        // And the exact chain, so a swoole release that re-parents the class is noticed.
        $this->assertSame(\Exception::class, get_parent_class($swooleException));
        $this->assertSame(\Swoole\Exception::class, get_parent_class($socketException));
    }

    /**
     * The control for every test below: written the obvious way, none of them would
     * execute the guarded block at all.
     *
     * This is not a hypothetical. `getLocalIpViaUdpSocket()` is invoked here exactly
     * as it is in the containment tests, with the same plant, and the seam counter
     * stays at ZERO — a plain PHPUnit test would report a green "the Swoole\Exception
     * was contained" having never reached a line of the try block. Deleting this test
     * deletes the reason the others go through {@see RunsInCoroutine}.
     */
    public function testTheSameCallOutsideACoroutineNeverEntersTheGuardedBranch(): void
    {
        $client = new SeamedStunClient(new NullLogger());
        $client->planFailure(new \Swoole\Exception('S197: planted swoole socket failure', 99));

        $this->assertTrue(extension_loaded('swoole'), 'swoole is LOADED, which is exactly the trap');
        $this->assertSame(-1, \Swoole\Coroutine::getCid(), 'and PHPUnit is still not in a coroutine');

        $result = (new ReflectionMethod(StunClient::class, 'getLocalIpViaUdpSocket'))->invoke($client);

        $this->assertSame(
            0,
            $client->seamCalls(),
            'the guarded block is behind inCoroutine(), so on the main stack it is skipped entirely '
            . 'and the plant is never even reached'
        );
        $this->assertNullOrString($result);
    }

    // -----------------------------------------------------------------------
    // Site 1..4 — getLocalIpViaUdpSocket(), copy-pasted into four classes.
    // -----------------------------------------------------------------------

    /**
     * The four classes that carry a copy of getLocalIpViaUdpSocket().
     *
     * @return array<string, array{0: class-string, 1: class-string<PlantsSocketFailures>}>
     */
    public static function localIpProbeClasses(): array
    {
        return [
            'StunClient' => [StunClient::class, SeamedStunClient::class],
            'PortForwardService' => [PortForwardService::class, SeamedPortForwardService::class],
            'NatPmpClient' => [NatPmpClient::class, SeamedNatPmpClient::class],
            'UpnpIgdClient' => [UpnpIgdClient::class, SeamedUpnpIgdClient::class],
        ];
    }

    /**
     * @param class-string                          $subject The production class declaring the private method.
     * @param class-string<PlantsSocketFailures>    $double  The seamed subclass used to plant the failure.
     */
    #[DataProvider('localIpProbeClasses')]
    public function testASwooleExceptionIsContainedByTheLocalIpProbe(string $subject, string $double): void
    {
        $client = new $double();
        $client->planFailure(new \Swoole\Exception('S197: planted swoole socket failure', 99));

        // Runs the REAL private method inside a REAL coroutine. Diagnostics are
        // captured because the containment path ends in @fsockopen('8.8.8.8', 53),
        // which warns — and errors the test out — on a box with no egress.
        $captured = $this->runInCoroutineCapturingDiagnostics(
            static fn (): mixed => (new ReflectionMethod($subject, 'getLocalIpViaUdpSocket'))->invoke($client)
        );

        // Reaching this line at all is the containment proof: an escaping throwable
        // is re-thrown on the main stack by RunsInCoroutine and fails the test.
        $this->assertSame(
            1,
            $client->seamCalls(),
            'the coroutine branch must actually have been entered — otherwise this test '
            . 'passes without executing the catch it exists to pin (S170).'
        );
        $this->assertNullOrString(
            $captured['result'],
            'the probe must still answer, having degraded to the blocking fallback'
        );
    }

    /**
     * Shape variation: the concrete class swoole's socket API is documented to raise,
     * thrown from the MIDDLE of the guarded block rather than from its first line.
     */
    public function testASocketExceptionThrownByConnectIsContained(): void
    {
        $client = new SeamedStunClient(new NullLogger());
        $client->planSocket(
            static fn (): FakeCoroutineSocket => (new FakeCoroutineSocket())->throwFromConnect(
                new \Swoole\Coroutine\Socket\Exception('S197: planted connect() failure', 104)
            )
        );

        $captured = $this->runInCoroutineCapturingDiagnostics(
            static fn (): mixed => (new ReflectionMethod(StunClient::class, 'getLocalIpViaUdpSocket'))->invoke($client)
        );

        $this->assertSame(1, $client->seamCalls());
        $this->assertNullOrString($captured['result']);
    }

    /**
     * Shape variation: the `\Error` the block's own S146 comment was written for.
     *
     * This is the case that makes `\Throwable` the right width rather than
     * `\Exception` — swapping the production catch for `catch (\Exception $e)` reds
     * this test and none of the others.
     */
    public function testAnErrorIsContainedToo(): void
    {
        $client = new SeamedStunClient(new NullLogger());
        $client->planFailure(new \Error('Call to undefined method Swoole\Coroutine\Socket::setTimeout()'));

        $captured = $this->runInCoroutineCapturingDiagnostics(
            static fn (): mixed => (new ReflectionMethod(StunClient::class, 'getLocalIpViaUdpSocket'))->invoke($client)
        );

        $this->assertSame(1, $client->seamCalls());
        $this->assertNullOrString($captured['result']);
    }

    // -----------------------------------------------------------------------
    // Site 5 — PortForwardService::findGatewayForIp(). The one site whose
    // blocking fallback sits in an `else`, so nothing else runs in a coroutine
    // and the assertion can be exact.
    // -----------------------------------------------------------------------

    public function testASwooleExceptionIsContainedByTheGatewayProbe(): void
    {
        $service = new SeamedPortForwardService();
        $service->planFailure(new \Swoole\Exception('S197: planted swoole socket failure', 99));

        $gateway = $this->runInCoroutine(
            static fn (): mixed => (new ReflectionMethod(PortForwardService::class, 'findGatewayForIp'))
                ->invoke($service, '192.168.77.42')
        );

        $this->assertSame(1, $service->seamCalls(), 'the coroutine branch must have been entered');
        $this->assertSame(
            '192.168.77.1',
            $gateway,
            'a failed probe still returns the .1 guess — the catch exists so the caller keeps '
            . 'getting an answer, and widening it must not change that'
        );
    }

    // -----------------------------------------------------------------------
    // The double close(). Measured harmless (a second close() returns false),
    // so this pins the removal of dead code, not a crash.
    // -----------------------------------------------------------------------

    /**
     * @param class-string $subject
     * @param class-string $double
     */
    #[DataProvider('localIpProbeClasses')]
    public function testTheSocketIsClosedExactlyOnceWhenTheLocalAddressIsUnusable(
        string $subject,
        string $double
    ): void {
        $socket = new FakeCoroutineSocket();
        $socket->connectReturns = true;
        // Connected, but the local address carries no usable host: the old code fell
        // out of the `if` and closed the same socket a SECOND time.
        $socket->sockname = ['host' => '', 'port' => 0];

        $client = new $double();
        $client->planSocket(static fn (): FakeCoroutineSocket => $socket);

        $captured = $this->runInCoroutineCapturingDiagnostics(
            static fn (): mixed => (new ReflectionMethod($subject, 'getLocalIpViaUdpSocket'))->invoke($client)
        );

        $this->assertSame(1, $client->seamCalls());
        $this->assertSame(1, $socket->closeCalls, 'the socket must be closed exactly once, not twice');
        $this->assertNullOrString($captured['result']);
    }

    /**
     * The counterweight: removing the redundant close() must not remove the NEEDED
     * one. Without this, "closed exactly once" could be satisfied by closing zero
     * times on the path that used to close once.
     *
     * @param class-string $subject
     * @param class-string $double
     */
    #[DataProvider('localIpProbeClasses')]
    public function testTheSocketIsStillClosedOnceWhenConnectFails(string $subject, string $double): void
    {
        $socket = new FakeCoroutineSocket();
        $socket->connectReturns = false;

        $client = new $double();
        $client->planSocket(static fn (): FakeCoroutineSocket => $socket);

        $captured = $this->runInCoroutineCapturingDiagnostics(
            static fn (): mixed => (new ReflectionMethod($subject, 'getLocalIpViaUdpSocket'))->invoke($client)
        );

        $this->assertSame(1, $client->seamCalls());
        $this->assertSame(0, $socket->socknameCalls, 'getsockname() is pointless on a socket that did not connect');
        $this->assertSame(1, $socket->closeCalls, 'a socket that failed to connect must still be closed');
        $this->assertNullOrString($captured['result']);
    }

    /**
     * And the success path, which returns before the fallback runs and is therefore
     * the one exact assertion available on these four methods.
     *
     * @param class-string $subject
     * @param class-string $double
     */
    #[DataProvider('localIpProbeClasses')]
    public function testAUsableLocalAddressIsReturnedAndTheSocketClosedOnce(string $subject, string $double): void
    {
        $socket = new FakeCoroutineSocket();
        $socket->connectReturns = true;
        $socket->sockname = ['host' => '10.9.8.7', 'port' => 41234];

        $client = new $double();
        $client->planSocket(static fn (): FakeCoroutineSocket => $socket);

        $localIp = $this->runInCoroutine(
            static fn (): mixed => (new ReflectionMethod($subject, 'getLocalIpViaUdpSocket'))->invoke($client)
        );

        $this->assertSame('10.9.8.7', $localIp);
        $this->assertSame(1, $socket->closeCalls);
    }

    /**
     * `?string` without an `assertTrue(A || B)`, whose two failure stories are
     * indistinguishable in the output.
     */
    private function assertNullOrString(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            $this->assertNull($value, $message);
            return;
        }

        $this->assertIsString($value, $message);
    }
}

/**
 * What the four seamed doubles have in common, expressed as a type.
 *
 * It is an interface and not just the trait below because the tests are driven by a
 * data provider over `class-string`s: PHPStan cannot type `new $double()` from a
 * trait (a trait is not a type — `varTag.trait`), and CI's phpstan job runs WITHOUT
 * ext-swoole, so the four subclasses are not otherwise relatable. Measured: with a
 * `@var SocketSeam&object` annotation instead, `phpstan analyse -c phpstan-tests.neon`
 * reports 4 errors under `PHP_INI_SCAN_DIR=<no-swoole>` and none on this box.
 *
 * @internal
 */
interface PlantsSocketFailures
{
    /** Makes the next createCoroutineSocket() throw $e. */
    public function planFailure(\Throwable $e): void;

    /**
     * Makes the next createCoroutineSocket() return $factory()'s socket.
     *
     * @param callable(): \Swoole\Coroutine\Socket $factory
     */
    public function planSocket(callable $factory): void;

    /** How many times the guarded block asked for a socket. Zero means it never ran. */
    public function seamCalls(): int;
}

/**
 * Plants a failure at the one seam the guarded blocks expose.
 *
 * `parent::createCoroutineSocket()` is preserved when nothing is planted so a double
 * that is handed to an un-planted code path still behaves like the real thing.
 *
 * @internal
 */
trait SocketSeam
{
    private ?\Throwable $plantedFailure = null;

    /** @var (callable(): \Swoole\Coroutine\Socket)|null */
    private $plantedSocket = null;

    private int $seamCalls = 0;

    public function planFailure(\Throwable $e): void
    {
        $this->plantedFailure = $e;
    }

    /**
     * @param callable(): \Swoole\Coroutine\Socket $factory
     */
    public function planSocket(callable $factory): void
    {
        $this->plantedSocket = $factory;
    }

    public function seamCalls(): int
    {
        return $this->seamCalls;
    }

    protected function createCoroutineSocket(int $type): \Swoole\Coroutine\Socket
    {
        $this->seamCalls++;

        if ($this->plantedFailure !== null) {
            throw $this->plantedFailure;
        }

        if ($this->plantedSocket !== null) {
            return ($this->plantedSocket)();
        }

        return parent::createCoroutineSocket($type);
    }
}

/**
 * A real `Swoole\Coroutine\Socket` — the class is not final and its methods are not
 * final (verified by reflection) — with connect/getsockname/close driven by the test.
 *
 * It really is constructed, so the object the production code holds is the production
 * type and the fd is real; only the three calls the guarded block makes are scripted.
 *
 * ⚠ Parameters are deliberately UNTYPED. The real extension declares
 * `connect(string $host, int $port = 0, float $timeout = 0): bool`, but PHPStan's
 * bundled phpstorm-stub declares `connect($host, $port = null, $timeout = null)` and
 * CI's phpstan job runs without ext-swoole. Typed parameters satisfy the extension and
 * are a contravariance error against the stub; untyped ones satisfy both, because
 * widening a parameter type is legal in PHP.
 *
 * @internal
 */
class FakeCoroutineSocket extends \Swoole\Coroutine\Socket
{
    public bool $connectReturns = true;

    /** @var array{host: string, port: int}|false */
    public array|false $sockname = ['host' => '10.0.0.1', 'port' => 12345];

    public int $connectCalls = 0;
    public int $socknameCalls = 0;
    public int $closeCalls = 0;

    private ?\Throwable $connectThrows = null;

    public function __construct()
    {
        // AF_INET / SOCK_DGRAM are always defined wherever this class can be
        // instantiated: swoole.so fails to load without ext-sockets on this build
        // ("undefined symbol: socket_ce"), and RunsInCoroutine skips without swoole.
        parent::__construct(AF_INET, SOCK_DGRAM, 0);
    }

    public function throwFromConnect(\Throwable $e): self
    {
        $this->connectThrows = $e;
        return $this;
    }

    /**
     * @param mixed $host
     * @param mixed $port
     * @param mixed $timeout
     */
    public function connect($host, $port = 0, $timeout = 0): bool
    {
        $this->connectCalls++;

        if ($this->connectThrows !== null) {
            throw $this->connectThrows;
        }

        return $this->connectReturns;
    }

    /**
     * @return array{host: string, port: int}|false
     */
    public function getsockname(): array|false
    {
        $this->socknameCalls++;

        return $this->sockname;
    }

    public function close(): bool
    {
        $this->closeCalls++;

        return parent::close();
    }
}

/** @internal */
class SeamedStunClient extends StunClient implements PlantsSocketFailures
{
    use SocketSeam;
}

/** @internal */
class SeamedPortForwardService extends PortForwardService implements PlantsSocketFailures
{
    use SocketSeam;
}

/** @internal */
class SeamedNatPmpClient extends NatPmpClient implements PlantsSocketFailures
{
    use SocketSeam;
}

/** @internal */
class SeamedUpnpIgdClient extends UpnpIgdClient implements PlantsSocketFailures
{
    use SocketSeam;
}
