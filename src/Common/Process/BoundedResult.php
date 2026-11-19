<?php

/**
 * Phlix media server component: Common process primitives.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Process;

/**
 * Immutable outcome of a bounded (proc_open) run.
 *
 * Deliberately carries NO command, argv, or environment fields: the result
 * object is what site log lines and exception messages receive downstream,
 * and the estate's MYSQL_PWD posture (E-12: BackupManager passes the DB
 * password through the child environment ONLY) requires that an environment
 * value can never travel into a captured value that someone later serialises.
 * {@see \Phlix\Tests\Unit\Common\Process\BoundedTest} pins the property
 * census so a future "just add the command for context" field is a red
 * test, not a silent leak vector.
 *
 * @since E-12
 */
final class BoundedResult
{
    /**
     * Exit code reported by proc_close()/proc_get_status(); for a
     * signal-killed child this is the shell-convention status.
     */
    public readonly int $exitCode;

    /**
     * Captured stdout. Empty when stdout was not captured or the read
     * failed — normalized false→'' so callers never branch on two flavors
     * of "nothing".
     */
    public readonly string $stdout;

    /**
     * Captured stderr, same normalization as {@see $stdout}.
     */
    public readonly string $stderr;

    /**
     * True only when the deadline kill-ladder fired (run() mode).
     */
    public readonly bool $timedOut;

    public function __construct(
        int $exitCode,
        string $stdout,
        string $stderr,
        bool $timedOut
    ) {
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->timedOut = $timedOut;
    }
}
