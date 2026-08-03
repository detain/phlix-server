<?php

/**
 * Phlix media server component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Network;

/**
 * The classified result of one TCP connect probe against `ip:port`.
 *
 * ## S169 — WHY THIS TYPE EXISTS, AND WHAT "REFUSED" MEANS HERE
 *
 * {@see StunClient::testPortAccessibility()} used to answer the question with a
 * `bool` computed like this (coroutine arm):
 *
 *     $connected = $sock->connect($ip, $port, 3.0);
 *     if ($connected) { return true; }
 *     // Connection refused (ECONNREFUSED) also means port is accessible
 *     return true;
 *
 * Both arms returned `true`, so the only non-`true` exit was an exception — the
 * method could not answer "no". The blocking fallback had the same shape in
 * milder form: `return $errno === 111 || $errno === 0;`.
 *
 * The comment is not nonsense, and that is the trap. "The host answered, so it
 * is REACHABLE" is a defensible reading of ECONNREFUSED — an RST proves a host
 * is there. But reachable is not OPEN, and every caller of that method is asking
 * the second question:
 *
 *   * `PortForwardService::autoConfigure()` — "is my port ALREADY FORWARDED, so
 *     I can stop here and tell the user remote access works?" It persists
 *     `['method' => 'stun-already-open', 'enabled' => true]` on `true` and never
 *     reaches the manual-instructions fallback.
 *   * `PortForwardService::discoverHostnameCandidates()` — "may I ADVERTISE
 *     `http://<public-ip>:<port>` to clients as a working URL?"
 *   * `scripts/port-forward.php` — prints `OPEN` vs `BLOCKED/FILTERED` to an
 *     operator debugging their router.
 *
 * All three want "a client can connect and be served". And note the direction of
 * this specific probe: it is the server connecting to its OWN public IP to see
 * whether the NAT forwards the port back in. In that direction an RST usually
 * comes from the ROUTER, not from Phlix, and means the port is **not forwarded**
 * (or the router does not do hairpin/NAT-loopback at all). Reading it as "open"
 * inverts the answer precisely where a user is relying on it.
 *
 * So: **only a COMPLETED TCP handshake is `Open`.** Everything else — refused,
 * timed out, unreachable, unresolved, or an unclassifiable failure — is not
 * open. The bias is deliberate: a false negative sends the user to the manual
 * port-forward instructions (annoying, correct), a false positive tells them
 * remote access works when it does not (silent, wrong).
 *
 * ⚠ Do NOT "correct" {@see self::Refused} back to open. If some future caller
 * genuinely wants "did a host answer at all", that is a different question and
 * needs its own predicate — `Open` and `Refused` are kept as separate cases
 * precisely so it can be added without reopening this one.
 *
 * @package Phlix\Network
 * @since 0.11.0
 */
enum PortProbeOutcome: string
{
    /** The TCP handshake completed: something is listening and reachable. */
    case Open = 'open';

    /**
     * The peer answered with an RST (ECONNREFUSED). The host exists; the port
     * is not serving us. For a NAT-forwarding check this is a NO — see the
     * class docblock before changing it.
     */
    case Refused = 'refused';

    /** No answer within the timeout: silently dropped, typically a firewall. */
    case TimedOut = 'timed-out';

    /** The network or host is unreachable (no route, ICMP unreachable). */
    case Unreachable = 'unreachable';

    /** The target name could not be resolved, so nothing was probed. */
    case Unresolved = 'unresolved';

    /**
     * The probe failed for a reason we cannot classify — including "no errno
     * was reported at all", which the old fallback treated as open
     * (`$errno === 0` was half of its `return`). An unmeasurable probe must
     * never read as success.
     */
    case Failed = 'failed';

    /**
     * Linux errno values, as integers on purpose.
     *
     * The `SOCKET_*` constants would be clearer but come from ext-sockets,
     * which is NOT in composer.json's require list and is NOT loaded in CI's
     * phpstan/phpcs jobs (`extensions: json`). These four values are fixed by
     * the Linux ABI and are asserted against the `SOCKET_*` constants by
     * PortProbeOutcomeTest whenever the extension happens to be present.
     */
    public const ECONNREFUSED = 111;
    public const ETIMEDOUT = 110;
    public const EHOSTUNREACH = 113;
    public const ENETUNREACH = 101;

    /**
     * Swoole's own DNS failure code, measured on swoole 6.2.1: a
     * `Swoole\Coroutine\Socket::connect()` to an unresolvable name returns
     * false with `errCode = 711` / `errMsg = "DNS Lookup resolve failed"`. It
     * is a Swoole code, not an errno, hence its own constant.
     */
    public const SWOOLE_DNS_LOOKUP_FAILED = 711;

    /**
     * Classifies a failed connect() by its error number.
     *
     * Pure and total: every input maps to a case, and an unrecognised code maps
     * to {@see self::Failed} rather than to anything optimistic. `0` lands here
     * too — measured, `fsockopen()` reports errno `0` with a `getaddrinfo`
     * message when the NAME fails to resolve, and PHP leaves it at `0` when it
     * has nothing to report.
     *
     * @param int $errno Platform error number from the failed connect.
     */
    public static function fromErrno(int $errno): self
    {
        return match ($errno) {
            self::ECONNREFUSED => self::Refused,
            self::ETIMEDOUT => self::TimedOut,
            self::EHOSTUNREACH, self::ENETUNREACH => self::Unreachable,
            self::SWOOLE_DNS_LOOKUP_FAILED => self::Unresolved,
            default => self::Failed,
        };
    }

    /**
     * Whether this outcome means a client can connect to the port.
     *
     * Only {@see self::Open} qualifies. See the class docblock for why
     * {@see self::Refused} does not.
     */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
