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
 * The estate's canonical DETACHED-spawn grammar (E-12).
 *
 * A detached spawn must survive three things a bare `cmd &` does not:
 *  - SIGHUP when the spawning PHP shell returns        -> `nohup`;
 *  - the caller needing the child's real PID           -> `& echo $!`
 *    (both child streams are redirected to the log so the line's ONLY
 *    stdout content is the pid);
 *  - a stuck capture/encode holding a tuner forever    -> the SV-4.2
 *    `timeout <transcode_timeout>` wrapper (0 disables the wrapper).
 *
 * Two spawn sites built this grammar independently (E-12): FfmpegRunner's
 * detached HLS transcodes and Recorder's live capture, each carrying the
 * same SV-4.2 rationale prose. This class is now the single source of the
 * string shape; each site keeps its own POLICY (which timeout value, which
 * log path, whether sentinels are used) and delegates the grammar.
 *
 * SENTINELS ARE OPT-IN BY DESIGN. FfmpegRunner's transcodes publish
 * `.complete`/`.failed` marker files into the output directory for its
 * job-reaper; Recorder deliberately has NO sentinels — its dossier note is
 * explicit that the on-disk footprint of a capture is only the log file.
 * Passing `$sentinelOutDir = null` yields the Recorder shape verbatim;
 * passing a directory yields the FfmpegRunner if/then/else shape verbatim.
 * Adopting this class changes neither consumer's argv (behavior-disclosure
 * law: a refactor may not silently grow the on-disk footprint).
 */
final class Detached
{
    /**
     * Build the `nohup … > log 2>&1 & echo $!` launch line.
     *
     * @param string       $command        The fully-built command to run detached.
     * @param string       $logPath        Combined stdout/stderr log file path.
     * @param int          $timeoutSecs    SV-4.2 wrapper bound; 0 = no wrapper.
     * @param string|null  $sentinelOutDir Output dir for `.complete`/`.failed`
     *                                     sentinels; null = no sentinels and no
     *                                     if/then/else wrapper (Recorder shape).
     * @param array<mixed> $trailingCmds   Extra shell statements appended to the
     *                                     success branch (non-string and empty
     *                                     entries are dropped, matching the
     *                                     historical FfmpegRunner filter).
     *
     * @return string The complete launch line for one shell evaluation.
     *
     * @throws \InvalidArgumentException When trailing commands are passed
     *                                   without a sentinel directory — the
     *                                   trailing list only has a home inside
     *                                   the success branch; silently dropping
     *                                   it would be an invisible behavior loss.
     *
     * @since E-12 (SV-4.2 grammar consolidated)
     */
    public static function buildLaunch(
        string $command,
        string $logPath,
        int $timeoutSecs,
        ?string $sentinelOutDir = null,
        array $trailingCmds = []
    ): string {
        $inner = $command;

        if ($sentinelOutDir !== null) {
            $then = 'touch ' . escapeshellarg($sentinelOutDir . '/.complete');
            foreach ($trailingCmds as $trailing) {
                if (is_string($trailing) && $trailing !== '') {
                    $then .= '; ' . $trailing;
                }
            }
            $inner = 'if ' . $command . '; then ' . $then
                . '; else touch ' . escapeshellarg($sentinelOutDir . '/.failed') . '; fi';
        } elseif ($trailingCmds !== []) {
            throw new \InvalidArgumentException(
                'Detached::buildLaunch(): trailing commands require a sentinel directory — '
                . 'they only execute inside the success branch.'
            );
        }

        // SV-4.2: wrap in timeout to enforce transcode_timeout.
        if ($timeoutSecs > 0) {
            $inner = 'timeout ' . (int) $timeoutSecs . ' sh -c ' . escapeshellarg($inner);
        } else {
            $inner = 'sh -c ' . escapeshellarg($inner);
        }

        return sprintf(
            'nohup %s > %s 2>&1 & echo $!',
            $inner,
            escapeshellarg($logPath)
        );
    }

    /**
     * Parse the PID printed by a launch line's `echo $!`.
     *
     * A non-string or blank result means the spawn never happened — the line
     * always echoes the forked child's PID, so no output means no child.
     *
     * @param mixed $output Raw capture of the launch line's stdout.
     *
     * @return int The child PID, or 0 when the launch failed.
     *
     * @since E-12
     */
    public static function pidFromLaunch(mixed $output): int
    {
        if (!is_string($output) || trim($output) === '') {
            return 0;
        }

        return (int) trim($output);
    }

    /**
     * Whether a detached PID is still alive (signal-0 probe, /proc fallback).
     *
     * @since E-12 (ported verbatim from FfmpegRunner::isProcessRunning)
     */
    public static function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }
}
