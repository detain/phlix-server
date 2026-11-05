<?php

/**
 * Phlix media server component: Hub relay transport.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;

/**
 * An {@see AsyncTcpConnection} that drives its non-blocking TLS handshake to
 * completion by re-entering it on the readiness the TLS state machine actually
 * needs next — inbound (readable) and, transiently, outbound (writable) — with a
 * bounded handshake watchdog. This is the connection the hub relay tunnel uses
 * for `wss://` dials; the plaintext and local (`ws://`) legs keep the stock
 * {@see AsyncTcpConnection} behaviour unchanged.
 *
 * ## Why this exists — F-1 (defect the fix closes)
 *
 * On the production host (PHP 8.5 / Swoole 6.2.1 / kernel-7 io_uring) the relay
 * dial to `wss://hub.phlix.is.cc:8802` stalls forever. The kernel accounts for
 * the parked socket show EXACTLY one data segment sent (the 1533-byte TLS
 * ClientHello), the hub's 4885-byte flight received and ACKed, and the client's
 * second flight NEVER written — `Recv-Q: 0`, and `strace` finds the worker
 * parked in the event wait issuing zero further syscalls for 26+ minutes. A
 * blocking-socket probe to the same endpoint completes TLSv1.3 and receives an
 * HTTP 101, so the platform TLS stack is healthy; only the ASYNC drive is broken.
 *
 * Workerman 5.2.3 (the newest published tag — there is no later stable to bump
 * to) drives the async client handshake from
 * {@see AsyncTcpConnection::checkConnection()} and its private
 * `checkSslHandshake()`. The first `stream_socket_enable_crypto()` on the
 * non-blocking socket returns `0` ("incomplete, try again") and only a READABLE
 * watch is armed. The first inbound flight then fires `checkSslHandshake()` ONCE;
 * if that pass returns `0` again — which is precisely what happens when, after
 * consuming the peer's flight, the client must now WRITE its own next flight
 * (the TLS 1.3 `Finished`) — `checkSslHandshake()` returns WITHOUT re-arming any
 * watch. The socket is then permanently unwatched with a pending write and no
 * further inbound traffic expected, so the handshake is stranded mid-negotiation
 * and never times out. `data_segs_out: 1` is the fingerprint of that sequence.
 *
 * ## The fix, and why it does not busy-spin
 *
 * A naïve "watch writable too" is unsafe: on a level-triggered loop (Workerman's
 * select, and Swoole's epoll-backed `Event`) an established client socket whose
 * send buffer has drained is ALWAYS writable, so a persistently armed writable
 * callback would spin at 100% CPU. Workerman itself calls this out in
 * `checkConnection()`. So outbound readiness is armed TRANSIENTLY: exactly one
 * writable pass is scheduled after each inbound pass that still reports `0`, and
 * the writable callback disarms itself on entry. This is sound because a client
 * TLS flight is only ever produced in response to bytes just read from the peer,
 * so the need to write is always discovered immediately after a readable event.
 * Handshake progress therefore alternates inbound→outbound→inbound until it
 * completes, and the socket is never left permanently armed for writes.
 *
 * ## Scope of the override
 *
 * Only the `transport === 'ssl'` completion path changes; a non-TLS connection
 * delegates verbatim to `parent::checkConnection()`. The establishment sequence
 * below mirrors Workerman's private `markEstablished()` (register `baseRead`,
 * flip to ESTABLISHED, flush a queued sendBuffer, fire `onConnect` then
 * `protocol::onConnect`) — and `protocol::onConnect` is the `Ws` client protocol
 * writing the HTTP Upgrade, which is why it MUST run only once
 * {@see TcpConnection::$sslHandshakeCompleted} is genuinely `true` so the
 * upgrade request is encrypted.
 *
 * ## F-2 / F-3 dispositions
 *
 * - F-2 (the "connected; sending HELLO" log reading as optimistic): that log
 *   lives in the tunnel's `onConnect`, which fires only from
 *   {@see self::establish()} below — i.e. strictly AFTER a genuine handshake
 *   completion. It can no longer fire on a stalled/half-negotiated socket, so
 *   there is no pre-handshake success log to move.
 * - F-3 (a FIN during a parked handshake is invisible to the server): both the
 *   inbound and the outbound pass now re-enter the drive and treat a
 *   failed/EOF {@see self::performTlsHandshake()} as terminal (`destroy()` →
 *   `onClose`), and the watchdog bounds the case where a peer accepts TCP and
 *   then goes silent — so a dead tunnel tears down and reconnects instead of
 *   holding a silent ESTAB forever.
 *
 * Survival token (code-resident, never in a .md): [RELAYTLSF1WDRIVE7Q2K].
 *
 * @see \Phlix\Hub\RelayConsumer::openHubConnection() — the sole producer.
 * @see tests/Unit/Hub/TlsRelayConnectionTest.php — the red-first drive tests.
 */
// Not `final`: {@see self::performTlsHandshake()} is the crypto seam a test
// subclass scripts to reproduce the incomplete/failure returns (see the test).
class TlsRelayConnection extends AsyncTcpConnection
{
    /**
     * Hard ceiling on how long a parked TLS handshake may stay unresolved before
     * the connection is failed and the relay reconnects. Normal TLS 1.3 to the
     * hub is sub-second; this only catches a peer that accepts TCP then goes
     * silent (the 26-minute silent ESTAB of the pre-fix incident).
     */
    public const HANDSHAKE_TIMEOUT_SECONDS = 15.0;

    /** Event-loop timer id for the handshake watchdog; 0 when not armed. */
    private int $handshakeTimerId = 0;

    /** True while the handshake state machine still holds an event watch. */
    private bool $handshakeArmed = false;

    /**
     * After the TCP connect resolves, drive a TLS handshake through this class's
     * inbound/outbound state machine; everything else (plaintext/local legs) is
     * delegated verbatim to the vendored parent so its behaviour is untouched.
     */
    public function checkConnection(): void
    {
        if ($this->transport !== 'ssl') {
            parent::checkConnection();
            return;
        }

        if ($this->status !== TcpConnection::STATUS_CONNECTING) {
            return;
        }

        // TCP writable fired: stop watching for connect, then validate the socket.
        if ($this->eventLoop !== null) {
            $this->eventLoop->offWritable($this->socket);
        }

        $address = stream_socket_get_name($this->socket, true);
        if (!$address) {
            $this->emitError(
                self::CONNECT_FAIL,
                'connect ' . $this->remoteAddress . ' fail after '
                    . round(microtime(true) - $this->connectStartTime, 4) . ' seconds'
            );
            if ($this->status === TcpConnection::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === TcpConnection::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }

        if (!is_resource($this->socket)) {
            $this->emitError(self::CONNECT_FAIL, 'connect ' . $this->remoteAddress . ' fail: socket not a resource');
            if ($this->status === TcpConnection::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === TcpConnection::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }

        stream_set_blocking($this->socket, false);
        stream_set_read_buffer($this->socket, 0);

        $this->beginTlsHandshake($this->socket, $address);
    }

    /**
     * Enter the handshake drive. Runs the first `enable_crypto` pass and, when it
     * reports incomplete, arms the inbound watch (plus the one-shot watchdog).
     *
     * @param resource $socket  The TCP-established, non-blocking client socket.
     * @param string   $address Peer `host:port` from `stream_socket_get_name`.
     */
    public function beginTlsHandshake($socket, string $address): void
    {
        // Re-assert invariants the production entry relies on; a test may drive
        // this directly without a real connect().
        $this->socket = $socket;
        $this->status = TcpConnection::STATUS_CONNECTING;

        $result = $this->performTlsHandshake();

        if ($result === true) {
            $this->completeTlsHandshake($address);
            return;
        }

        if ($result === false) {
            $this->failHandshake('tls negotiation failed');
            return;
        }

        // $result === 0 : incomplete. This is the state Workerman's stock driver
        // abandons. Arm the inbound watch + watchdog.
        $this->armInboundWatch($address, true);
    }

    /**
     * Watch the socket for inbound bytes (the peer's next flight) and, on first
     * arm, schedule the handshake watchdog.
     *
     * @param string $address     Peer `host:port`.
     * @param bool   $withWatchdog Schedule {@see self::HANDSHAKE_TIMEOUT_SECONDS} (once).
     */
    private function armInboundWatch(string $address, bool $withWatchdog): void
    {
        $loop = $this->eventLoop;
        if ($loop === null) {
            return;
        }

        $this->handshakeArmed = true;
        $loop->onReadable($this->socket, function () use ($address): void {
            $this->onInboundReadiness($address);
        });

        if ($withWatchdog && $this->handshakeTimerId === 0) {
            $this->handshakeTimerId = $loop->delay(
                self::HANDSHAKE_TIMEOUT_SECONDS,
                function () use ($address): void {
                    $this->onHandshakeTimeout($address);
                }
            );
        }
    }

    /**
     * A readable fired while the handshake is pending: run the next pass. If it
     * still reports incomplete, the client most likely has a flight to WRITE, so
     * schedule one transient outbound pass.
     */
    private function onInboundReadiness(string $address): void
    {
        if ($this->status !== TcpConnection::STATUS_CONNECTING) {
            return;
        }

        $result = $this->performTlsHandshake();

        if ($result === true) {
            $this->completeTlsHandshake($address);
            return;
        }

        if ($result === false) {
            $this->failHandshake('tls negotiation failed mid-handshake');
            return;
        }

        // 0 : a fresh inbound flight was consumed and more negotiation is pending
        // — drive exactly one outbound pass to flush the client's triggered write.
        $this->armOutboundWatch($address);
    }

    /**
     * Schedule ONE transient writable pass (the deferred client flight). Never
     * left armed across loop iterations, so it cannot spin a drained socket.
     */
    private function armOutboundWatch(string $address): void
    {
        $loop = $this->eventLoop;
        if ($loop === null) {
            return;
        }

        $loop->onWritable($this->socket, function () use ($address): void {
            $this->onOutboundReadiness($address);
        });
    }

    /**
     * The transient writable fired: disarm itself immediately (so a level loop
     * cannot re-fire it), then run the next pass. Still incomplete means we are
     * once again waiting on the peer, so fall back to the inbound watch.
     */
    private function onOutboundReadiness(string $address): void
    {
        if ($this->eventLoop !== null) {
            $this->eventLoop->offWritable($this->socket);
        }

        if ($this->status !== TcpConnection::STATUS_CONNECTING) {
            return;
        }

        $result = $this->performTlsHandshake();

        if ($result === true) {
            $this->completeTlsHandshake($address);
            return;
        }

        if ($result === false) {
            $this->failHandshake('tls negotiation failed mid-handshake');
            return;
        }

        // 0 : nothing more to write; resume waiting for the peer's next flight.
        $this->armInboundWatch($address, false);
    }

    /**
     * The watchdog fired with the handshake still unresolved: fail loudly so the
     * reconnect path runs, instead of holding a dead ESTAB socket.
     */
    private function onHandshakeTimeout(string $address): void
    {
        $this->handshakeTimerId = 0;

        if ($this->status !== TcpConnection::STATUS_CONNECTING) {
            return;
        }

        $this->failHandshake('tls handshake timed out');
    }

    /**
     * The single crypto-driver call. Extracted so a test can script the
     * incomplete (`0`) → complete (`true`) and failure (`false`) return sequence
     * — the exact states this class exists to survive — without a live TLS peer.
     *
     * Delegates verbatim to the vendored public
     * {@see AsyncTcpConnection::doSslHandshake()}.
     *
     * @return bool|int `true` complete, `false` failed, `0` needs another pass.
     */
    protected function performTlsHandshake(): bool|int
    {
        return $this->doSslHandshake($this->socket);
    }

    /**
     * Handshake done: disarm the phase watches and finish establishing.
     */
    private function completeTlsHandshake(string $address): void
    {
        $this->disarmHandshakeWatches();
        $this->sslHandshakeCompleted = true;
        $this->establish($address);
    }

    /**
     * Fail the handshake: emit onError (unless already emitted by the driver),
     * then destroy so `onClose` fires and the caller can reconnect.
     */
    private function failHandshake(string $reason): void
    {
        $this->disarmHandshakeWatches();

        if ($this->status === TcpConnection::STATUS_CONNECTING) {
            $this->emitError(
                self::CONNECT_FAIL,
                sprintf('SSL handshake with %s failed: %s', $this->getRemoteHost(), $reason)
            );
        }

        $this->destroy();
    }

    /**
     * Flip to ESTABLISHED and emit the connect callbacks — the mirror of
     * `markEstablished()`. `protocol::onConnect` (the `Ws` client upgrade) is
     * fired last and only now, because the upgrade request must be encrypted.
     */
    private function establish(string $address): void
    {
        $loop = $this->eventLoop;
        if ($loop === null) {
            return;
        }

        $loop->onReadable($this->socket, $this->baseRead(...));

        $this->status = TcpConnection::STATUS_ESTABLISHED;
        $this->remoteAddress = $address;

        if ($this->sendBuffer !== '') {
            $loop->onWritable($this->socket, $this->baseWrite(...));
        }

        if ($this->onConnect) {
            try {
                ($this->onConnect)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }

        if ($this->protocol && method_exists($this->protocol, 'onConnect')) {
            try {
                $this->protocol::onConnect($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * Remove the handshake-phase watches and cancel the watchdog. Safe to call
     * before anything was armed.
     */
    private function disarmHandshakeWatches(): void
    {
        $loop = $this->eventLoop;
        if ($loop === null) {
            $this->handshakeTimerId = 0;
            $this->handshakeArmed = false;
            return;
        }

        if ($this->handshakeTimerId !== 0) {
            $loop->offDelay($this->handshakeTimerId);
            $this->handshakeTimerId = 0;
        }

        $loop->offReadable($this->socket);
        $loop->offWritable($this->socket);
        $this->handshakeArmed = false;
    }

    /**
     * Whether the handshake state machine currently holds a live watch (inbound
     * or a transient outbound pass). Read-only; never mutates.
     */
    public function isHandshakeDriving(): bool
    {
        return $this->handshakeArmed;
    }

    /**
     * Test seam: the connection's live stream socket, which the parent keeps
     * protected. Exposed read-only for the drive tests.
     *
     * @return resource|null
     */
    public function getSocketResource()
    {
        return $this->socket;
    }
}
