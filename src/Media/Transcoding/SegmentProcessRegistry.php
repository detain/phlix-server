<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Per-worker registry of detached on-demand ffmpeg segment-encode PIDs, keyed by
 * an opaque cancel key (typically the final segment path, but any stable
 * request/segment/job identifier works).
 *
 * SV-4.2 ([S-F23]): the scrub-storm orphan-CPU problem. On-demand segment
 * encodes are launched detached and previously ran to completion even after the
 * client abandoned the request (a frantic seek launches — and abandons — many
 * segment encodes). This registry lets the transcode poll loop kill the encode
 * it launched when the wait times out, and lets a cancel/disconnect hook kill by
 * request key, so abandoned encodes stop burning CPU instead of running to the
 * end.
 *
 * Resident-memory discipline (this is Workerman, NOT php-fpm): the map is bounded
 * — every caller MUST {@see release()} or {@see kill()} its key (the transcode
 * path does so in a `finally`). {@see registeredKeyCount()} is exposed so leaks
 * are observable in tests.
 *
 * Coroutine-safety: killing waits between SIGTERM and SIGKILL using a
 * coroutine-yielding sleep when inside a Swoole coroutine (`getCid() > 0`) and a
 * short blocking sleep otherwise, so it never blocks the event loop. The signal
 * send and liveness probe are injectable for tests (no real processes spawned).
 *
 * @since SV-4.2
 */
final class SegmentProcessRegistry
{
    /**
     * Cancel key => list of tracked OS PIDs.
     *
     * @var array<string, array<int, int>>
     */
    private array $pids = [];

    private LoggerInterface $logger;

    /**
     * Signal sender: fn(int $pid, int $signal): void. Defaults to posix_kill /
     * `kill` fallback. Overridable in tests so no real signals are sent.
     *
     * @var callable(int, int): void
     */
    private $signalSender;

    /**
     * Liveness probe: fn(int $pid): bool. Defaults to posix_kill($pid, 0) /
     * /proc probe. Overridable in tests.
     *
     * @var callable(int): bool
     */
    private $isAlive;

    /**
     * Seconds to wait for graceful SIGTERM exit before escalating to SIGKILL.
     * Deliberately short: segment encodes are small and disposable.
     */
    private float $gracePeriodSeconds;

    /**
     * @param callable(int, int): void|null $signalSender
     * @param callable(int): bool|null      $isAlive
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?callable $signalSender = null,
        ?callable $isAlive = null,
        float $gracePeriodSeconds = 0.5
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->signalSender = $signalSender ?? self::defaultSignalSender();
        $this->isAlive = $isAlive ?? self::defaultLivenessProbe();
        $this->gracePeriodSeconds = $gracePeriodSeconds;
    }

    /**
     * Track a launched detached encode PID under the given cancel key.
     *
     * A key may accumulate more than one PID (e.g. an audio + video rendition
     * for the same logical request); all are killed together.
     *
     * @param string $key Opaque cancel key (segment path / request id).
     * @param int    $pid OS process id; non-positive pids are ignored.
     */
    public function register(string $key, int $pid): void
    {
        if ($key === '' || $pid <= 0) {
            return;
        }
        $this->pids[$key][] = $pid;
    }

    /**
     * Drop the tracked PIDs for a key WITHOUT killing them (the encode finished
     * on its own). Prevents the map from leaking. Safe for unknown keys.
     *
     * @param string $key Cancel key to forget.
     */
    public function release(string $key): void
    {
        unset($this->pids[$key]);
    }

    /**
     * Kill every tracked PID for a key (SIGTERM, then SIGKILL after the grace
     * period if still alive) and drop the entry. Safe (no-op) for unknown or
     * already-cleared keys, so it can be called defensively from cancel /
     * disconnect / wait-timeout paths.
     *
     * @param string $key Cancel key whose encodes should be aborted.
     *
     * @return int Number of PIDs that were signalled.
     */
    public function kill(string $key): int
    {
        $pids = $this->pids[$key] ?? [];
        unset($this->pids[$key]);
        if ($pids === []) {
            return 0;
        }

        $this->logger->debug('SegmentProcessRegistry: killing abandoned segment encode(s)', [
            'key' => $key,
            'pids' => $pids,
        ]);

        foreach ($pids as $pid) {
            $this->terminate($pid);
        }
        return count($pids);
    }

    /**
     * The number of keys currently tracked. Exposed for leak assertions in tests
     * and for observability; should return to 0 once all in-flight encodes have
     * been released or killed.
     */
    public function registeredKeyCount(): int
    {
        return count($this->pids);
    }

    /**
     * The PIDs tracked under a key (empty if none). Exposed for tests.
     *
     * @param string $key Cancel key.
     *
     * @return array<int, int>
     */
    public function pidsFor(string $key): array
    {
        return $this->pids[$key] ?? [];
    }

    /**
     * Graceful-then-forced kill of a single PID, coroutine-safe.
     *
     * @param int $pid Process id to terminate.
     */
    private function terminate(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        ($this->signalSender)($pid, self::sigTerm());

        // Bounded, coroutine-yielding wait for graceful exit.
        $deadline = hrtime(true) + (int) ($this->gracePeriodSeconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            if (!($this->isAlive)($pid)) {
                return;
            }
            $this->cooperativeSleep(0.05);
        }

        if (($this->isAlive)($pid)) {
            ($this->signalSender)($pid, self::sigKill());
        }
    }

    /**
     * Sleep that yields to the Swoole event loop inside a coroutine and blocks
     * (briefly) otherwise — never stalls the worker under the coroutine runtime.
     *
     * @param float $seconds Sleep duration.
     */
    private function cooperativeSleep(float $seconds): void
    {
        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * @return callable(int, int): void
     */
    private static function defaultSignalSender(): callable
    {
        return static function (int $pid, int $signal): void {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, $signal);
                return;
            }
            $flag = $signal === self::sigKill() ? '-KILL' : '-TERM';
            @shell_exec(sprintf('kill %s %d 2>/dev/null', $flag, $pid));
        };
    }

    /**
     * @return callable(int): bool
     */
    private static function defaultLivenessProbe(): callable
    {
        return static function (int $pid): bool {
            if ($pid <= 0) {
                return false;
            }
            if (function_exists('posix_kill')) {
                return @posix_kill($pid, 0);
            }
            return is_dir('/proc/' . $pid);
        };
    }

    private static function sigTerm(): int
    {
        return defined('SIGTERM') ? SIGTERM : 15;
    }

    private static function sigKill(): int
    {
        return defined('SIGKILL') ? SIGKILL : 9;
    }
}
