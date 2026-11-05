<?php

/**
 * Phlix media server test: relay TLS-handshake drive (F-1).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\TlsRelayConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;

/**
 * Test double for the event loop: records the handshake-phase watches the
 * connection arms and lets a test fire ONLY the readable, ONLY the writable, or
 * the watchdog timer — so the state machine can be driven without a live socket,
 * a kernel timer, or an event loop actually running.
 */
final class RecordingTlsEventLoop implements EventInterface
{
    public bool $readableArmed = false;
    public bool $writableArmed = false;

    /** @var callable|null */
    public $readableCb = null;
    /** @var callable|null */
    public $writableCb = null;
    /** @var callable|null */
    public $timeoutCb = null;

    public int $delayCalls = 0;
    public int $offDelayCalls = 0;

    /**
     * FIRST timer id handed out is 0 — mirroring Workerman's own
     * `Events\Event::delay()` (M-S1 of the F-1 review: a watchdog-armed
     * sentinel that compares against 0 reads this very id as "never armed"
     * and leaks the timer; this double reddens any such regression).
     */
    private int $nextTimerId = 0;

    public function delay(float $delay, callable $func, array $args = []): int
    {
        $this->delayCalls++;
        $this->timeoutCb = $func;
        return $this->nextTimerId++;
    }

    public function offDelay(int $timerId): bool
    {
        $this->offDelayCalls++;
        $this->timeoutCb = null;
        return true;
    }

    public function repeat(float $interval, callable $func, array $args = []): int
    {
        return 0;
    }

    public function offRepeat(int $timerId): bool
    {
        return true;
    }

    public function onReadable($stream, callable $func): void
    {
        $this->readableArmed = true;
        $this->readableCb = $func;
    }

    public function offReadable($stream): bool
    {
        $this->readableArmed = false;
        $this->readableCb = null;
        return true;
    }

    public function onWritable($stream, callable $func): void
    {
        $this->writableArmed = true;
        $this->writableCb = $func;
    }

    public function offWritable($stream): bool
    {
        $this->writableArmed = false;
        $this->writableCb = null;
        return true;
    }

    public function onSignal(int $signal, callable $func): void
    {
    }

    public function offSignal(int $signal): bool
    {
        return true;
    }

    public function deleteAllTimer(): void
    {
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
    }

    public function getTimerCount(): int
    {
        return $this->timeoutCb === null ? 0 : 1;
    }

    public function setErrorHandler(callable $errorHandler): void
    {
    }

    public function fireReadable(): void
    {
        $cb = $this->readableCb;
        if ($cb !== null) {
            $cb();
        }
    }

    public function fireWritable(): void
    {
        $cb = $this->writableCb;
        if ($cb !== null) {
            $cb();
        }
    }

    public function fireTimeout(): void
    {
        $cb = $this->timeoutCb;
        if ($cb !== null) {
            $cb();
        }
    }
}

/**
 * A {@see TlsRelayConnection} whose crypto-driver call is scripted, so the
 * incomplete (`0`) → complete (`true`) and failure (`false`) return sequence the
 * fix exists to survive can be produced deterministically — the exact return
 * values `stream_socket_enable_crypto()` yields on a non-blocking socket.
 */
final class ScriptedTlsRelayConnection extends TlsRelayConnection
{
    /** @var list<bool|int> */
    private array $script = [];

    private bool $productionCorroboration = false;

    /** Observability for the delegation pin (F-1b/A3). */
    public int $performCalls = 0;

    /**
     * @param list<bool|int> $script
     */
    public function queueHandshake(array $script): void
    {
        $this->script = $script;
    }

    /**
     * Route corroboration back to the PRODUCTION method (reads the real
     * stream's crypto meta) for the F-1b false-positive scenario.
     */
    public function useProductionCorroboration(): void
    {
        $this->productionCorroboration = true;
    }

    protected function performTlsHandshake(): bool|int
    {
        $this->performCalls++;

        if ($this->script === []) {
            // Default: keep asking (incomplete) rather than inventing a result.
            return 0;
        }

        return array_shift($this->script);
    }

    /**
     * The scripted world stands in for the crypto RESULT, so it must also
     * stand in for the corroborated verdict that result would produce — the
     * scripted tests pin the DRIVE state machine, not openssl. The
     * false-positive test opts back into production corroboration (see
     * {@see self::useProductionCorroboration()}).
     */
    protected function corroborateTlsCompletion(): bool
    {
        if ($this->productionCorroboration) {
            return parent::corroborateTlsCompletion();
        }

        // The scripted driver says "crypto done" — the scripted world agrees.
        return true;
    }
}

/**
 * F-1: the hub relay TLS handshake must be driven to completion across the
 * inbound→outbound alternation a real TLS flight exchange produces, must tear
 * down (fire onClose) on a failure/FIN during the handshake, and must never
 * strand — bounded by a watchdog.
 *
 * F-1b extends the law: the watchdog bounds from ENTRY (before any branch),
 * and a completion is only honored when the stream's own crypto meta
 * corroborates it — the hooked-Swoole false `true` must die, never establish.
 *
 * Token [RELAYTLSF1WDRIVE7Q2K].
 *
 * @see \Phlix\Hub\TlsRelayConnection
 */
final class TlsRelayConnectionTest extends TestCase
{
    /**
     * @return resource
     */
    private function makeSocket()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair unavailable');
        }
        return $pair[0];
    }

    private function makeConnection(RecordingTlsEventLoop $loop): ScriptedTlsRelayConnection
    {
        $conn = new ScriptedTlsRelayConnection('ws://127.0.0.1:8802');
        // Drive the pure state machine: no HTTP Upgrade, no protocol layering.
        $conn->protocol = null;
        $conn->eventLoop = $loop;
        return $conn;
    }

    /**
     * THE load-bearing proof of the fix (the exact production sequence):
     *
     *   1. first pass writes the ClientHello, returns 0 (need the peer)  → inbound armed
     *   2. peer flight arrives (readable), pass returns 0 (need to WRITE)  → outbound armed
     *   3. transient writable pass returns true                            → ESTABLISHED
     *
     * Workerman 5.2.3 stops after step 2 (it never re-arms anything), which is
     * why the live tunnel sat at `data_segs_out: 1` for 26 minutes. Delete the
     * outbound (`onWritable`) arm in TlsRelayConnection and step 3 never runs.
     */
    public function test_handshake_alternates_inbound_then_outbound_and_completes(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $established = false;
        $conn->onConnect = function () use (&$established): void {
            $established = true;
        };

        $conn->queueHandshake([0, 0, true]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');

        self::assertSame(TcpConnection::STATUS_CONNECTING, $conn->getStatus(), 'step 1: still CONNECTING');
        self::assertTrue($loop->readableArmed, 'step 1: inbound watch armed');
        self::assertFalse($loop->writableArmed, 'step 1: NOT yet write-driven');
        self::assertSame(1, $loop->delayCalls, 'step 1: watchdog scheduled');

        $loop->fireReadable();

        self::assertSame(
            TcpConnection::STATUS_CONNECTING,
            $conn->getStatus(),
            'step 2: inbound consumed, still CONNECTING'
        );
        // THE FIX: a fresh inbound flight that still reports incomplete must
        // schedule the deferred write. This assertion reddens if onWritable is
        // removed (Workerman's readable-only stranding).
        self::assertTrue($loop->writableArmed, 'step 2: writable MUST be armed (triggered write pending)');

        $loop->fireWritable();

        self::assertSame(
            TcpConnection::STATUS_ESTABLISHED,
            $conn->getStatus(),
            'step 3: outbound pass completes the handshake'
        );
        self::assertTrue($established, 'onConnect fires only after a genuine completion (F-2 truth)');
        self::assertFalse($conn->isHandshakeDriving(), 'phase watches disarmed on completion');
    }

    /**
     * A handshake that completes purely on inbound (the second pass finishes)
     * must complete without any outbound pass being needed.
     */
    public function test_completes_on_inbound_alone(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $established = false;
        $conn->onConnect = function () use (&$established): void {
            $established = true;
        };

        $conn->queueHandshake([0, true]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');

        $loop->fireReadable();

        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $conn->getStatus());
        self::assertTrue($established);
    }

    /**
     * F-1b/A3 LAW ROTATION (was: `test_immediate_completion_arms_nothing`,
     * `delayCalls === 0`): the watchdog is armed at drive ENTRY, before the
     * first pass — so an immediately-completing handshake arms it and then
     * cancels it. The old law WAS the H3-2 hole: production's false `true`
     * completed "immediately" (+45 ms) and nothing bounded it. Rotation
     * measured from the red this rewrite expects against the old behavior.
     */
    public function test_immediate_completion_arms_then_cancels_the_watchdog(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $conn->onConnect = function (): void {
        };
        $conn->queueHandshake([true]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');

        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $conn->getStatus());
        self::assertFalse($conn->isHandshakeDriving());
        self::assertSame(1, $loop->delayCalls, 'watchdog armed at entry, BEFORE the first pass');
        self::assertSame(1, $loop->offDelayCalls, 'a corroborated completion must cancel it again');
        self::assertNull($loop->timeoutCb, 'no pending timeout may survive completion');
    }

    /**
     * THE F-1b scenario, deterministic: the driver reports `true` on a stream
     * that is not TLS at all (the hooked-Swoole false success wrote only a
     * ClientHello; the stream denies establishment). Production corroboration
     * must convert the lie into a teardown — onConnect NEVER fires, onClose
     * does, and the entry watchdog is cancelled (no stale timer).
     */
    public function test_completion_reported_without_stream_corroboration_tears_down(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);
        $conn->useProductionCorroboration();

        $closed = false;
        $errored = 0;
        $connected = false;
        $conn->onConnect = function () use (&$connected): void {
            $connected = true;
        };
        $conn->onClose = function () use (&$closed): void {
            $closed = true;
        };
        $conn->onError = function () use (&$errored): void {
            $errored++;
        };

        $conn->queueHandshake([true]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');

        self::assertSame(TcpConnection::STATUS_CLOSED, $conn->getStatus(), 'an uncorroborated completion is a FAILURE');
        self::assertFalse($connected, 'H3-2 law: onConnect must NEVER ride an uncorroborated completion');
        self::assertTrue($closed, 'the teardown must be visible to the caller (reconnect drive)');
        self::assertSame(1, $errored);
        self::assertSame(1, $loop->delayCalls, 'watchdog armed at entry even for a false `true`');
        self::assertSame(1, $loop->offDelayCalls, 'the teardown must cancel the watchdog');
    }

    /**
     * The pure predicate behind corroboration, pinned on every meta shape a
     * stream can hand back (measured PHP shape: `protocol` is a non-empty
     * string only on an established TLS transport; the key is absent before).
     */
    public function test_crypto_meta_predicate_accepts_only_established_shapes(): void
    {
        self::assertTrue(TlsRelayConnection::cryptoMetaEstablished(['protocol' => 'TLSv1.3']));
        self::assertTrue(TlsRelayConnection::cryptoMetaEstablished([
            'protocol' => 'TLSv1.2',
            'cipher_name' => 'ECDHE-RSA-AES256-GCM-SHA384',
        ]));
        self::assertFalse(TlsRelayConnection::cryptoMetaEstablished(null), 'absent crypto block = not established');
        self::assertFalse(TlsRelayConnection::cryptoMetaEstablished([]));
        self::assertFalse(TlsRelayConnection::cryptoMetaEstablished(['protocol' => '']));
        self::assertFalse(TlsRelayConnection::cryptoMetaEstablished(['protocol' => null]));
        self::assertFalse(TlsRelayConnection::cryptoMetaEstablished(['protocol' => 123]));
        self::assertFalse(TlsRelayConnection::cryptoMetaEstablished('yes'));
    }

    /**
     * A3 plaintext-leg byte-identity: a non-ssl transport delegates VERBATIM
     * to the vendored parent — none of this class's machinery may run there.
     * A unix-pair socket has no peer name, so the parent takes its
     * connect-fail branch; the pin is what this class never did.
     */
    public function test_plaintext_transport_delegates_verbatim_to_parent(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $errored = 0;
        $conn->onError = function () use (&$errored): void {
            $errored++;
        };

        $socket = $this->makeSocket();
        // `socket` and `status` are protected on the vendored parent; the
        // unix pair has no peer name, so parent::checkConnection() lands in
        // its address-less fail branch.
        (function () use ($socket): void {
            $this->socket = $socket;
            $this->status = TcpConnection::STATUS_CONNECTING;
        })->call($conn);
        $conn->checkConnection();

        self::assertSame(0, $conn->performCalls, 'the TLS drive must never run for a non-ssl transport');
        self::assertSame(0, $loop->delayCalls, 'no handshake watchdog on the delegated leg');
        self::assertGreaterThan(0, $errored, 'the vendored fail branch ran — delegation, not theater');
    }

    /**
     * A failure on the very first pass must close cleanly (onClose) and surface
     * exactly one onError, never firing onConnect.
     */
    public function test_immediate_handshake_failure_closes_cleanly(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $closed = false;
        $errored = 0;
        $connected = false;
        $conn->onConnect = function () use (&$connected): void {
            $connected = true;
        };
        $conn->onClose = function () use (&$closed): void {
            $closed = true;
        };
        $conn->onError = function () use (&$errored): void {
            $errored++;
        };

        $conn->queueHandshake([false]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');

        self::assertSame(TcpConnection::STATUS_CLOSED, $conn->getStatus());
        self::assertTrue($closed);
        self::assertFalse($connected, 'onConnect must NOT fire when the handshake fails (F-2 truth)');
        self::assertSame(1, $errored);
        self::assertFalse($loop->writableArmed, 'watches disarmed after teardown');
    }

    /**
     * F-3: a FIN/EOF (a failed pass) observed on a parked inbound watch must
     * tear down and fire onClose so the relay reconnects — not park in CONNECTING.
     */
    public function test_failure_during_parked_state_fires_on_close(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $closed = false;
        $errored = 0;
        $connected = false;
        $conn->onConnect = function () use (&$connected): void {
            $connected = true;
        };
        $conn->onClose = function () use (&$closed): void {
            $closed = true;
        };
        $conn->onError = function () use (&$errored): void {
            $errored++;
        };

        $conn->queueHandshake([0, false]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');
        self::assertSame(TcpConnection::STATUS_CONNECTING, $conn->getStatus());

        $loop->fireReadable();

        self::assertSame(TcpConnection::STATUS_CLOSED, $conn->getStatus());
        self::assertTrue($closed, 'a failed handshake pass must fire onClose (F-3)');
        self::assertFalse($connected, 'onConnect must NOT fire when the handshake fails (F-2 truth)');
        self::assertSame(1, $errored, 'the failure must surface once via onError');
    }

    /**
     * The watchdog: a peer that accepts TCP then goes silent must not hold a dead
     * ESTAB forever. When the delay fires with the handshake still pending, the
     * connection fails closed and onClose runs.
     */
    public function test_watchdog_timeout_fails_closed_and_fires_on_close(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $closed = false;
        $conn->onClose = function () use (&$closed): void {
            $closed = true;
        };
        $conn->onError = function (): void {
        };

        $conn->queueHandshake([0]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');

        self::assertSame(1, $loop->delayCalls, 'a handshake watchdog must be armed');
        self::assertSame(TcpConnection::STATUS_CONNECTING, $conn->getStatus());

        $loop->fireTimeout();

        self::assertSame(TcpConnection::STATUS_CLOSED, $conn->getStatus());
        self::assertTrue($closed, 'a handshake timeout must fire onClose');
        self::assertFalse($loop->readableArmed, 'inbound watch disarmed on timeout teardown');
    }

    /**
     * Successful completion must cancel the watchdog so the established
     * connection is not torn down 15s later by a stale timer.
     */
    public function test_successful_completion_disarms_watchdog(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $conn->onConnect = function (): void {
        };
        $conn->queueHandshake([0, true]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802');

        $loop->fireReadable();

        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $conn->getStatus());
        self::assertFalse($conn->isHandshakeDriving(), 'phase watches disarmed on completion');
        self::assertSame(1, $loop->offDelayCalls, 'watchdog must be cancelled on completion');
        self::assertNull($loop->timeoutCb, 'no pending timeout after a completed handshake');
    }

    /**
     * A longer exchange (multiple inbound→outbound cycles) still converges and
     * must never leave a writable watch permanently armed (no busy-spin).
     */
    public function test_multi_cycle_exchange_converges_without_permanent_writable(): void
    {
        $loop = new RecordingTlsEventLoop();
        $conn = $this->makeConnection($loop);

        $established = false;
        $conn->onConnect = function () use (&$established): void {
            $established = true;
        };

        // in(0) → out(0) → in(0) → out(true)
        $conn->queueHandshake([0, 0, 0, 0, true]);
        $socket = $this->makeSocket();
        $conn->beginTlsHandshake($socket, '127.0.0.1:8802'); // pop 0 → arm inbound

        $loop->fireReadable(); // pop 0 → arm outbound
        self::assertTrue($loop->writableArmed);
        $loop->fireWritable();  // pop 0 → disarm self, re-arm inbound
        self::assertFalse($loop->writableArmed, 'a transient outbound pass disarms itself (no spin)');
        self::assertTrue($loop->readableArmed);

        $loop->fireReadable(); // pop 0 → arm outbound
        self::assertTrue($loop->writableArmed);
        $loop->fireWritable();  // pop true → complete

        self::assertSame(TcpConnection::STATUS_ESTABLISHED, $conn->getStatus());
        self::assertTrue($established);
    }
}
