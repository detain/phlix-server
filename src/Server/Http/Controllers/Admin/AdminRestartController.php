<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;
use Workerman\Timer;

/**
 * Graceful worker reload — `POST /api/v1/admin/restart`.
 *
 * Reads the master PID from the configured pid file (`config/server.php`'s
 * `worker.pid_file`, which {@see \Phlix\Server\Runtime\PidFile::apply()}
 * assigns to `Worker::$pidFile` in `start.php`, so the reader and the writer
 * agree) and signals the master to cycle its workers.
 *
 * **Signal choice — SIGUSR2, not SIGUSR1.** In Workerman
 * (`Worker::reload()`: `$sig = static::getGracefulStop() ? SIGUSR2 : SIGUSR1;`)
 * **SIGUSR2 is the GRACEFUL reload and SIGUSR1 is the NON-graceful one.**
 * SIGUSR1 tells children to stop immediately and arms a `SIGKILL` after
 * `stopTimeout`; SIGUSR2 lets them drain in-flight requests first. Earlier
 * revisions of this controller sent SIGUSR1 while asserting the inverse in
 * this docblock — that was backwards and is fixed.
 *
 * **Ordering.** The signal is NOT sent inline. `restart()` pre-flights the
 * target with `posix_kill($pid, 0)` (an existence/permission probe that sends
 * no signal) so it can still report a bad PID as a 500, then schedules the real
 * SIGUSR2 on a Workerman one-shot timer and returns immediately. The JSON ack
 * is therefore written to the socket *before* the master cycles the worker
 * handling this very request — per plan §3.35, "return a JSON ack, then reload
 * *after* the response flushes (never mid-request)". Signalling inline risked
 * the caller seeing a connection reset instead of the ack, which the SPA
 * renders as "Failed to restart server" for a restart that did happen.
 *
 * **What a reload does and does NOT re-read — read this before promising a
 * user that a setting will take effect.** A reload re-forks the workers from
 * the existing master, so each child re-runs `onWorkerStart` and rebuilds its
 * DI container. What it does NOT do is re-read `config/server.php`: `start.php`
 * `include`s that file ONCE in the master and closes over the resulting
 * `$config` array, and nothing merges `server_settings` DB overrides into it at
 * boot. Consequently a setting whose consumer reads boot `$config` (rather than
 * `SettingsRepository::getEffective()`) does not change on reload — and would
 * not change on a full `systemctl restart` either. That gap covers every
 * `restart: true` schema key today. It is a known, documented architectural
 * limitation, NOT something this endpoint fixes; see
 * `docs/dev/settings-restart-gap.md`. Do not describe this endpoint as making
 * boot-only settings take effect.
 *
 * Route is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}).
 *
 * Resident-memory rules: no `exit`/`die`, no blocking `sleep()` — the deferral
 * is a Workerman event-loop timer, not a sleep — and no request state parked in
 * `static`/`global`.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since   Phase 8
 */
class AdminRestartController
{
    /**
     * Seconds to wait after responding before signalling the master.
     *
     * Long enough for the ack to be flushed to the client socket, short enough
     * that the operator sees the restart happen promptly.
     */
    protected const SIGNAL_DELAY_SECONDS = 1.0;

    /** @var string Absolute path to the PID file, sourced from config. */
    private string $pidFile;

    /**
     * @param string $pidFile Absolute path to the PID file (from config/server.php).
     *
     * @since Phase 8
     */
    public function __construct(string $pidFile)
    {
        $this->pidFile = $pidFile;
    }

    /**
     * Schedule a graceful reload of the server's workers.
     *
     * POST /api/v1/admin/restart
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON `{ success: bool, message?: string, error?: string }`.
     *
     * @since Phase 8
     */
    public function restart(Request $request, array $params): Response
    {
        try {
            if (!is_file($this->pidFile)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'PID file not found',
                    'message' => 'Server may not be running, or pid_file is misconfigured.',
                ]);
            }

            $raw = file_get_contents($this->pidFile);
            if ($raw === false) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'Failed to read PID file',
                    'message' => 'Server may not be running, or pid_file is misconfigured.',
                ]);
            }

            $pid = trim($raw);
            if ($pid === '' || !is_numeric($pid)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'Invalid PID in file',
                    'message' => 'The pid_file contains an invalid value.',
                ]);
            }

            $masterPid = (int) $pid;

            // Signal 0 sends nothing — it only probes that the process exists
            // and that we may signal it. Doing this synchronously keeps a stale
            // or unsignalable PID a real 500, even though the actual reload
            // signal is deferred until after this response flushes.
            if ($this->sendSignal($masterPid, 0) === false) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'Signal send failed',
                    'message' => sprintf('Process %d is not signalable from this worker.', $masterPid),
                ]);
            }

            if ($this->scheduleSignal($masterPid, SIGUSR2) === false) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'Signal send failed',
                    'message' => sprintf('Failed to schedule SIGUSR2 for process %d.', $masterPid),
                ]);
            }

            return (new Response())->json([
                'success' => true,
                'message' => 'Restart signal sent',
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'Restart failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Defer the reload signal until after this response has been flushed.
     *
     * Uses a Workerman ONE-SHOT timer (`Timer::add(..., [], false)` — the 4th
     * argument must be `false`, since `Timer::add()` repeats by default). Never
     * a blocking `sleep()`: this is a resident event-loop process.
     *
     * Falls back to signalling immediately when there is no Workerman event
     * loop to schedule on (`Timer::add()` throws "Timer can only be used in
     * workerman running environment"), e.g. the CGI/`public/index.php` dispatch
     * path — better a slightly early signal than a restart that never happens.
     *
     * Extracted to a protected method so tests can observe it.
     *
     * @param int $pid    Process ID of the master.
     * @param int $signal Signal constant to deliver.
     *
     * @return bool True when the signal was scheduled (or sent by fallback).
     */
    protected function scheduleSignal(int $pid, int $signal): bool
    {
        try {
            Timer::add(
                static::SIGNAL_DELAY_SECONDS,
                function () use ($pid, $signal): void {
                    $this->sendSignal($pid, $signal);
                },
                [],
                false,
            );

            return true;
        } catch (Throwable) {
            return $this->sendSignal($pid, $signal);
        }
    }

    /**
     * Send a signal to a process.
     *
     * Extracted to a protected method so tests can mock it.
     *
     * @param int $pid    Process ID.
     * @param int $signal Signal constant (e.g. SIGUSR2, or 0 to probe only).
     *
     * @return bool True when posix_kill() returned true; false otherwise.
     */
    protected function sendSignal(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }
}
