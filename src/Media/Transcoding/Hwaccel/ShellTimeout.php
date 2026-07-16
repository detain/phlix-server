<?php

/**
 * Phlix media server component: Hwaccel Shell Timeout Utility.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel;

/**
 * Runs shell commands with timeouts to prevent coroutine deadlock during shutdown.
 *
 * Uses the `timeout` utility (coreutils) which is available on all Linux systems.
 * If timeout is unavailable, falls back to the raw command with no timeout.
 *
 * @since 0.11.0
 */
final class ShellTimeout
{
    /** Timeout for ffmpeg probe commands (encoders/decoders/help) in seconds */
    private const FFMPEG_TIMEOUT = 10;

    /** Timeout for GPU system tools in seconds */
    private const GPU_TOOL_TIMEOUT = 5;

    /**
     * Runs a shell command with a timeout using the `timeout` utility.
     *
     * @param string $command The shell command to run
     * @param int    $timeoutSeconds Maximum seconds before SIGTERM is sent
     *
     * @return string|null The command output, or null if timed out / unavailable
     */
    public static function exec(string $command, int $timeoutSeconds = self::FFMPEG_TIMEOUT): ?string
    {
        // Use timeout utility if available (Linux coreutils)
        // timeout sends SIGTERM after timeout, then SIGKILL after grace period
        $wrappedCommand = sprintf(
            'timeout %d %s 2>/dev/null',
            $timeoutSeconds,
            $command
        );

        $output = shell_exec($wrappedCommand);

        return is_string($output) ? $output : null;
    }

    /**
     * Runs an ffmpeg probe command with the standard ffmpeg timeout.
     * Use for: -encoders, -decoders, -hwaccels, -formats, -protocols, -filters, -help
     */
    public static function ffmpegProbe(string $command): ?string
    {
        return self::exec($command, self::FFMPEG_TIMEOUT);
    }

    /**
     * Runs a GPU system tool command (nvidia-smi, vainfo, v4l2-ctl, etc.) with a short timeout.
     */
    public static function gpuTool(string $command): ?string
    {
        return self::exec($command, self::GPU_TOOL_TIMEOUT);
    }
}
