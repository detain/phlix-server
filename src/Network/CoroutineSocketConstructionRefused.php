<?php

/**
 * Phlix media server component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Network;

use RuntimeException;

/**
 * A PREVENTION verdict, not a crash report: raised by
 * {@see CoroutineSocketGuard::create()} when a coroutine-socket construction would
 * enter the state that S207 measured faulting the worker (a SIGSEGV inside
 * `new \Swoole\Coroutine\Socket(...)`, through an enclosing `catch (\Throwable)`),
 * and so refuses to reach the construction at all.
 *
 * Extends `RuntimeException` deliberately: every guarded site catches `\Throwable`
 * (widened in S197 precisely because `Swoole\Exception` extends `\Exception`
 * directly), and a first-party domain refusal belongs at the narrow end of that
 * hierarchy, not beside the vendor class it replaces. Callers degrade exactly as
 * they do for a caught swoole failure — the blocking fallback arms in
 * `src/Network/` are the recovery path for this exception too.
 *
 * @package Phlix\Network
 * @since 0.32.0
 */
final class CoroutineSocketConstructionRefused extends RuntimeException
{
    /**
     * @param CoroutineSocketFault $fault   Which precondition failed (typed, S169 shape).
     * @param array<string, mixed> $context The arguments and measurements behind the verdict.
     */
    public function __construct(
        public readonly CoroutineSocketFault $fault,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
