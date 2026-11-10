<?php

/**
 * Phlix media server component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Network;

/**
 * The one home of local-address discovery for the port-forwarding clients — SH-007.
 *
 * ## WHAT THIS REPLACED
 *
 * `NatPmpClient`, `StunClient`, `UpnpIgdClient` and `PortForwardService` each
 * carried a byte-identical private copy of the same two-step discovery: scan
 * `net_get_interfaces()` for the first non-private, non-reserved unicast
 * address, then fall back to a UDP "connect" to `8.8.8.8:53` and read back the
 * local socket name. Four copies of one logic is four places for the S146
 * (`Swoole\Coroutine\Socket` has no `setTimeout()`) and S197
 * (`Swoole\Exception` is not a `RuntimeException`; exactly one `close()` per
 * path) lessons to have to agree — the history recorded in
 * `tests/Unit/Network/CoroutineSocketFailureContainmentTest.php` shows they
 * were only ever kept honest by a data provider running the same ceremony
 * against all four. The copies are now thin delegates — the shared scan and
 * probe bodies live here, the classes keep the scan-then-probe step in
 * `getLocalIpAddress()` — and that family is
 * UNCHANGED: it still drives every class's own private methods through
 * reflection, so what it pins after the extraction is exactly what it pinned
 * before it.
 *
 * ## THE SEAM CONTRACT
 *
 * Coroutine-branch socket construction arrives as `$socketFactory`, not as a
 * direct `new`: each class keeps its `protected createCoroutineSocket()` seam
 * (overriding it never reaches `CoroutineSocketGuard`, and it is how the S197
 * tests throw a real `Swoole\Exception` from the exact production line), so
 * folding construction in here would delete the only hook those pins have.
 * `localInterfaceAddress()` accepts an injected interface table for the same
 * reason in the other direction: the filter edge shapes (IPv6 zone ids,
 * bracketed hosts, `0.0.0.0`, the loopback classes) are pinned against a
 * synthetic table because no test can script this machine's real interfaces.
 *
 * The blocking fallback's outbound probe is deliberately NOT seam-ized: it is
 * the env-gated leg the existing family already treats as
 * "null-or-string, never empty" (`PortForwardServiceTest` skips on its own
 * `no-local-ip` marker in network-isolated containers), and a synthetic seam
 * there would pin a shape production never exercises.
 *
 * ## ONE DISCLOSED DIVERGENCE (SH-007 R2)
 *
 * The adversarial review of the extraction
 * (`.reviews/2026-11-10/REVIEW-w22L2server.md`, m1) measured one behavioral
 * difference against the four originals on the error path: when
 * `net_get_interfaces()` itself answers non-array, each original's
 * `getLocalIpAddress()` short-circuited to null WITHOUT running the UDP
 * probe, while the thin delegates call `localInterfaceAddress()` (which
 * still answers null there) and then fall through to their probe. The
 * divergence is deliberate, strictly error-path-only and more-lenient: a
 * failed interface scan is exactly when the fallback answer is most
 * valuable, and a failing probe still terminates in the parent's null — the
 * reachable outcomes are the originals' set plus one improvement (a correct
 * address where an original had none). No unit venue can script
 * `net_get_interfaces()` to fail (it is not a seam), so the behavior is
 * disclosed here rather than pinned by a test, and the four delegate
 * docblocks point at this paragraph instead of claiming blanket parity.
 *
 * @package Phlix\Network
 * @since   1.2.3
 */
final class LocalAddressProbe
{
    /**
     * Static-entry helper — like `CoroutineSocketGuard`, there is nothing to
     * instantiate and the private constructor says so.
     */
    private function __construct()
    {
    }

    /**
     * Returns true if Swoole coroutine context is active.
     *
     * `class_exists` rather than `extension_loaded` because the guards at the
     * call sites pair it with `class_exists(Swoole\Coroutine\Socket::class)`:
     * the question asked is "is the coroutine API present and are we inside a
     * coroutine", not "is the .so loaded".
     */
    public static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * The interface-table leg: first non-private, non-reserved unicast address.
     *
     * @param array<array-key, mixed>|null $interfaces Table in
     *        `net_get_interfaces()` shape; null reads this machine's real
     *        table. An injected table is the documented test seam for the
     *        edge shapes listed in the class header — it is never passed by
     *        production callers.
     */
    public static function localInterfaceAddress(?array $interfaces = null): ?string
    {
        $connections = $interfaces ?? @net_get_interfaces();
        if (!is_array($connections)) {
            return null;
        }

        foreach ($connections as $info) {
            if (!is_array($info) || !isset($info['unicast']) || !is_array($info['unicast'])) {
                continue;
            }
            foreach ($info['unicast'] as $addr) {
                if (!is_array($addr) || !isset($addr['address']) || !is_string($addr['address'])) {
                    continue;
                }
                $ip = $addr['address'];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * The UDP-probe leg: coroutine socket when inside one, blocking fallback
     * otherwise.
     *
     * @param \Closure(int): \Swoole\Coroutine\Socket $socketFactory
     *        Constructs the coroutine socket — production call sites pass a
     *        closure over their own `createCoroutineSocket()` (the S197 test
     *        seam; an override here never reaches `CoroutineSocketGuard`).
     */
    public static function udpProbeAddress(\Closure $socketFactory): ?string
    {
        if (self::inCoroutine() && class_exists(\Swoole\Coroutine\Socket::class)) {
            try {
                $sock = $socketFactory(SOCK_DGRAM);
                // S146: Swoole\Coroutine\Socket has NO setTimeout() — verified
                // absent from the class in swoole 6.2.2. The old call raised an
                // \Error ("Call to undefined method"). The timeout is connect()'s
                // third argument.
                // Connect to 8.8.8.8:53 (DNS) to determine local IP
                $connected = $sock->connect('8.8.8.8', 53, 2.0);
                // S197: exactly ONE close() on every path. The old shape closed
                // inside the if AND again after it, so a connected socket whose
                // local address was unusable was closed twice. Measured on swoole
                // 6.2.1: the second close() returns false rather than throwing, so
                // that was dead code and not a fault — but it read as a defect and
                // only the redundant call is removed here.
                $localAddr = $connected ? $sock->getsockname() : false;
                $sock->close();
                if ($localAddr !== false && is_array($localAddr)) {
                    $host = $localAddr['host'] ?? null;
                    if (is_string($host) && $host !== '') {
                        return $host;
                    }
                }
            } catch (\Throwable $e) {
                // \Throwable, not RuntimeException: NOTHING this block can raise is
                // a RuntimeException. Swoole\Exception and its subclass
                // Swoole\Coroutine\Socket\Exception (what the constructor is
                // documented to raise) both extend \Exception DIRECTLY — measured
                // 2026-08-03, `Swoole\Exception -> Exception -> END` — and the
                // S146 note above records an \Error, which is not an Exception at
                // all. The old catch (RuntimeException) therefore contained none of
                // the three, and this block exists only to degrade to the blocking
                // fallback below. S197.
            }
        }

        // Blocking fallback
        $sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 2);
        if ($sock !== false) {
            $localAddr = stream_socket_get_name($sock, false);
            fclose($sock);
            if ($localAddr !== false && $localAddr !== '') {
                $colonPos = strrpos($localAddr, ':');
                $host = $colonPos !== false ? substr($localAddr, 0, $colonPos) : $localAddr;
                if ($host !== '') {
                    return $host;
                }
            }
        }

        return null;
    }
}
