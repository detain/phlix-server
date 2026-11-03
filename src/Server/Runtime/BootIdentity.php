<?php

/**
 * Phlix media server component: Server runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Runtime;

/**
 * The one immutable identity stamp of THIS daemon boot.
 *
 * Review deferred-item #15 (devops2 minor 2): the boot paths that strand an
 * admin control (unwritable `worker.pid_file`, bridge-socket collision
 * between two daemons started from the same checkout) reported themselves as
 * bare warnings with nothing to correlate them by — two daemons logging the
 * same generic text are indistinguishable in a journal. `start.php` stamps
 * this identity once in the master, before `Worker::runAll()`, so every fork
 * inherits it through memory and every degraded-rail log line carries the
 * same instance token an operator can grep.
 *
 * The stamp is process-local by design: it is a correlation handle, not
 * state — nothing here is persisted, and no wire payload carries it except
 * the best-effort bridge-owner sidecar ({@see \Phlix\Session\SyncPlay\SyncPlayBridgeListener}),
 * which lives on the private 0600 unix-socket boundary, not on the API.
 *
 * @package Phlix\Server\Runtime
 */
final class BootIdentity
{
    private static ?self $current = null;

    private function __construct(
        public readonly string $instanceToken,
        public readonly int $masterPid,
        public readonly int $startedAt,
    ) {
    }

    /**
     * Stamps this boot; the FIRST stamp wins, later calls return it unchanged.
     *
     * Called once by start.php in the master before any fork. A worker (or a
     * test) calling again can never re-key an already-stamped process —
     * re-stamping would silently split log correlation, the exact hazard this
     * class exists to remove.
     *
     * @param string|null $instanceToken Explicit token for tests/sealed boots;
     *                                   null generates 16 hex chars.
     */
    public static function stamp(?string $instanceToken = null): self
    {
        $existing = self::$current;
        if ($existing !== null) {
            return $existing;
        }

        $token = $instanceToken !== null && $instanceToken !== ''
            ? $instanceToken
            : bin2hex(random_bytes(8));

        self::$current = new self($token, getmypid() ?: 0, time());

        return self::$current;
    }

    /** The stamped identity, or null before {@see stamp()} (pre-boot context). */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * One-line, log-safe description; never throws, unstamped-safe.
     *
     * `unstamped` is the honest rendering for a context that booted without
     * start.php (unit tests, standalone tooling) — the lines that call it
     * degrade to their old text, never to a fabricated identity.
     */
    public static function describe(): string
    {
        $identity = self::$current;
        if ($identity === null) {
            return 'instance=unstamped pid=0 started_at=none';
        }

        return sprintf(
            'instance=%s pid=%d started_at=%d',
            $identity->instanceToken,
            $identity->masterPid,
            $identity->startedAt
        );
    }

    /** Test seam: clears the stamp so each case can boot its own identity. */
    public static function resetForTesting(): void
    {
        self::$current = null;
    }
}
