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
 * ## F-1b — the cure's first regime miss, and the in-regime re-cure
 *
 * The drive above was host-proved on a RAW Select loop. Production is NOT
 * that regime: the relay worker runs `Events\Swoole` with the estate's curated
 * hook mask 0x42fe, which INCLUDES `SWOOLE_HOOK_SSL|SWOOLE_HOOK_TLS`. Under
 * those bits `stream_socket_enable_crypto()` is a coroutine call that can
 * return TRUE — its own state machine believes every flight was written —
 * while the final flight sits stranded in the coroutine write buffer and NEVER
 * reaches the wire (host forensics: `bytes_sent:1533`, `data_segs_out:1`, zero
 * network syscalls under strace, the hub mirror saw only the ClientHello). The
 * `true` return then fired `establish()` at +45 ms: a FALSE SUCCESS inside
 * ESTABLISHED, with no watchdog armed (it sat on the `0` branch only), no
 * `onClose`, no reconnect — the stall class moved, it did not die (H3-2).
 *
 * The shipped "primary cure" (the regime itself): the relay-tunnel worker
 * installs the curated mask WITHOUT the SSL/TLS bits
 * (`SwooleRuntime::relayTunnelHookMask()`), INTENDED to make `enable_crypto`
 * native in this worker — the semantics the Select proof exercised.
 * MEASURED INERT on the estate's production Swoole build (6.2.1-dev): the
 * identical dial strands at 1533/1 under the narrowed mask, under a mask
 * whose TLS bits were NEVER enabled anywhere (master-side 0x429e), and after
 * `Runtime::setHookFlags()` replaces the flags — the function swap does not
 * revert once any hook is live in the process. The ONLY client-side regime
 * proven to complete in-host is a ZERO-HOOK worker (no `enableCoroutine`
 * call ever) on the Select loop — F-1's original proof regime. The mask
 * narrowing stays wired as the declarative statement of that intent (and a
 * forward-compat pin if a future build honors per-bit swaps); the functional
 * fix is an architecture decision the lane report records as BLOCKED
 * (hookless-worker bootstrap, separate hookless tunnel process, or a
 * coroutine-native TLS client).
 *
 * Belt layers, each pinning a different failure shape, regime-independent:
 * 1. NO completion on the driver return alone — {@see self::confirmCompletion()}
 *    corroborates the stream's crypto meta BEFORE any ESTABLISHED callback; a
 *    `true` the stream itself cannot confirm is a failure (destroy → onClose →
 *    bounded reconnect). Measured limit (host scratch, hooked regime): the
 *    incident's false `true` carries a POPULATED crypto meta (`TLSv1.3`) —
 *    the meta lies along with the return there — so belt 1 does NOT catch
 *    that shape; belt 3 is its only live bound.
 * 2. The watchdog is armed at handshake ENTRY — before the first pass — so the
 *    pre-established phase is never unbounded: it bounds false `true`s belt 1
 *    catches, a lost inbound watch, and a peer that accepts TCP then goes
 *    silent. It does NOT bound the incident's false `true`: that one reports
 *    crypto meta, so belt 1 passes and establish cancels this watchdog
 *    microseconds after arming — for that shape, layer 3 is the bound.
 * 3. `RelayConsumer::HELLO_ACK_TIMEOUT_SECONDS` bounds the post-establish lie:
 *    HELLO sent but no HELLO_ACK within the deadline → visible teardown +
 *    bounded reconnect (kills the silent-ESTAB class at the protocol leg).
 *
 * ## F-2 / F-3 dispositions (F-1b-corrected)
 *
 * - F-2 (the "connected; sending HELLO" log reading as optimistic): that log
 *   lives in the tunnel's `onConnect`, which now fires only from a
 *   CORROBORATED {@see self::establish()}. The pre-fix production incident
 *   proved the original wording was a design assumption, not a fact: a false
 *   `true` DID ride it. With layers 1–3 above the log cannot become a silent
 *   lie — a false completion either fails corroboration loudly BEFORE the log,
 *   or dies inside the bounded ack deadline after it.
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

    /**
     * Event-loop timer id for the handshake watchdog; null when not armed.
     * MUST NOT use 0 as the sentinel: Workerman's `Events\Event::delay()`
     * hands out 0 as its FIRST timer id (measured, M-S1 in the independent
     * review of F-1), so an id-0 watchdog would read as "never armed" and leak
     * uncancelled. `null` is armed-state-honest for every loop implementation.
     */
    private ?int $handshakeTimerId = null;

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
     * Enter the handshake drive: arm the watchdog FIRST, run the first
     * `enable_crypto` pass, then branch — every terminal route (true/false) is
     * bounded from entry, and only the incomplete state parks on a watch.
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

        // F-1b/A3: bound the handshake BEFORE the first branch — a false
        // `true`, a lost watch, or a silent peer can never become an unbounded
        // silent ESTAB. Cancelled only on corroborated completion or teardown.
        $this->armHandshakeWatchdog($address);

        $result = $this->performTlsHandshake();

        if ($result === true) {
            $this->confirmCompletion($address);
            return;
        }

        if ($result === false) {
            $this->failHandshake('tls negotiation failed');
            return;
        }

        // $result === 0 : incomplete. This is the state Workerman's stock driver
        // abandons. Arm the inbound watch (watchdog already armed at entry).
        $this->armInboundWatch($address);
    }

    /**
     * Schedule the handshake watchdog EXACTLY ONCE, at drive entry (F-1b/A3).
     * The arm-once guard compares against null — never 0 — because timer ids
     * may legitimately start at 0 (M-S1).
     *
     * @param string $address Peer `host:port`.
     */
    private function armHandshakeWatchdog(string $address): void
    {
        $loop = $this->eventLoop;
        if ($loop === null || $this->handshakeTimerId !== null) {
            return;
        }

        $this->handshakeTimerId = $loop->delay(
            self::HANDSHAKE_TIMEOUT_SECONDS,
            function () use ($address): void {
                $this->onHandshakeTimeout($address);
            }
        );
    }

    /**
     * Watch the socket for inbound bytes (the peer's next flight). The watchdog
     * is NOT armed here — it is armed at {@see self::beginTlsHandshake()} entry.
     *
     * @param string $address Peer `host:port`.
     */
    private function armInboundWatch(string $address): void
    {
        $loop = $this->eventLoop;
        if ($loop === null) {
            return;
        }

        $this->handshakeArmed = true;
        $loop->onReadable($this->socket, function () use ($address): void {
            $this->onInboundReadiness($address);
        });
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
            $this->confirmCompletion($address);
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
            $this->confirmCompletion($address);
            return;
        }

        if ($result === false) {
            $this->failHandshake('tls negotiation failed mid-handshake');
            return;
        }

        // 0 : nothing more to write; resume waiting for the peer's next flight.
        $this->armInboundWatch($address);
    }

    /**
     * The watchdog fired with the handshake still unresolved: fail loudly so the
     * reconnect path runs, instead of holding a dead ESTAB socket.
     */
    private function onHandshakeTimeout(string $address): void
    {
        $this->handshakeTimerId = null;

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
     * F-1b/A3: the driver reported `true` — CORROBORATE at the stream level
     * BEFORE any ESTABLISHED callback fires. A completion the stream itself
     * cannot confirm (no crypto meta: the transport is not actually TLS) is a
     * FAILURE: destroy → onClose → the caller's bounded reconnect. The driver
     * return alone is never trusted.
     */
    private function confirmCompletion(string $address): void
    {
        if (!$this->corroborateTlsCompletion()) {
            $this->failHandshake(
                'completion reported by the driver but not corroborated by the stream crypto meta'
            );
            return;
        }

        $this->completeTlsHandshake($address);
    }

    /**
     * Corroboration seam: read the live stream's crypto meta. Kept separate
     * from {@see self::confirmCompletion()} so the scripted drive tests can
     * pin the gate verdicts independently of the scripted driver result, and
     * the false-positive test can exercise THIS production method.
     */
    protected function corroborateTlsCompletion(): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }

        /** @var array<string, mixed> $meta */
        $meta = stream_get_meta_data($this->socket);

        return self::cryptoMetaEstablished($meta['crypto'] ?? null);
    }

    /**
     * Pure predicate: does this `crypto` stream-metadata block have the shape
     * of an ESTABLISHED TLS transport? Native openssl streams carry the block
     * (with a non-empty `protocol`) only AFTER a successful enable_crypto;
     * before it the key is absent entirely. Under a Swoole TLS hook the block
     * can exist while flights are still buffered — which is exactly why the
     * relay worker UN-HOOKS those bits (the mask is the cure); this predicate
     * is the belt that fails any completion the native stream denies.
     *
     * @param mixed $crypto The `crypto` entry of `stream_get_meta_data()`.
     */
    public static function cryptoMetaEstablished(mixed $crypto): bool
    {
        if (!is_array($crypto)) {
            return false;
        }

        $protocol = $crypto['protocol'] ?? null;

        return is_string($protocol) && $protocol !== '';
    }

    /**
     * Handshake done AND corroborated: disarm the phase watches and finish
     * establishing. Reached only via {@see self::confirmCompletion()}.
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
            $this->handshakeTimerId = null;
            $this->handshakeArmed = false;
            return;
        }

        if ($this->handshakeTimerId !== null) {
            $loop->offDelay($this->handshakeTimerId);
            $this->handshakeTimerId = null;
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
