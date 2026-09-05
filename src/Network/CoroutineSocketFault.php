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
 * WHY {@see CoroutineSocketGuard} refused to construct a coroutine socket — the typed
 * failure shape S434 asked for, modelled on S169's {@see PortProbeOutcome}.
 *
 * A SIGSEGV is not throwable, so the containment for this hazard is PREVENTION: the
 * faulting state is classified before `new \Swoole\Coroutine\Socket(...)` is ever
 * reached, and the refusal names which precondition failed. Total classification —
 * every case here is decided from in-repo, measured evidence (see each case).
 *
 * @package Phlix\Network
 * @since 0.32.0
 */
enum CoroutineSocketFault: string
{
    /**
     * No live coroutine context (`Swoole\Coroutine::getCid() <= 0`, or ext-swoole
     * absent). Every construction site sits behind an `inCoroutine()` fork;
     * arriving here anyway means the caller's own precondition is broken, so the
     * construction is refused rather than trusted.
     */
    case NotInCoroutine = 'not-in-coroutine';

    /**
     * An argument the August 2026 SIGSEGV build took through `socket(2)` and
     * crashed on rather than throwing: S207/S197 measured
     * `new \Swoole\Coroutine\Socket(AF_INET, 99999, 0)` faulting inside `new`,
     * THROUGH an enclosing `catch (\Throwable)`. Only the domains and types this
     * estate actually constructs (AF_INET/AF_INET6; SOCK_STREAM/SOCK_DGRAM) and
     * protocol 0 are admitted; anything else is refused before construction.
     */
    case InvalidArguments = 'invalid-arguments';

    /**
     * The descriptor headroom is below {@see CoroutineSocketGuard::MIN_FREE_DESCRIPTORS}.
     * The second measured fault trigger: with `RLIMIT_NOFILE` clamped down via FFI
     * `setrlimit()`, the failing `socket(2)` inside the constructor SIGSEGVed on
     * the filing build instead of raising `Swoole\Coroutine\Socket\Exception`.
     */
    case DescriptorExhaustion = 'descriptor-exhaustion';

    /**
     * The headroom could not be measured (no `/proc/self/fd`, no `posix_getrlimit`,
     * no parseable `/proc/self/limits`). Refusing is the fail-closed side of the
     * trade: every caller degrades to its blocking fallback, which is correct on
     * every platform, while the coroutine arm is a Linux-prod optimisation.
     */
    case UnmeasurableHeadroom = 'unmeasurable-headroom';

    /**
     * True when the refusal is a verdict about THIS process's state right now
     * (headroom/context) rather than a programming error in the arguments.
     */
    public function isRuntimeCondition(): bool
    {
        return $this !== self::InvalidArguments;
    }
}
