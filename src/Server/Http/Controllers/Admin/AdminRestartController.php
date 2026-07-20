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

/**
 * Graceful server restart via SIGUSR1.
 *
 * POST /api/v1/admin/restart → reads the master PID from the pid_file
 * (config/server.php: worker.pid_file) and sends SIGUSR1 to request a
 * graceful worker-cycle. SIGUSR1 was chosen over SIGTERM because SIGTERM
 * kills active connections immediately whereas SIGUSR1 lets workers drain
 * their in-flight requests before restarting.
 *
 * Route is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}).
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since   Phase 8
 */
class AdminRestartController
{
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
     * Send a graceful restart signal to the master process.
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

            $result = $this->sendSignal((int) $pid, SIGUSR1);
            if ($result === false) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'Signal send failed',
                    'message' => sprintf('Failed to send SIGUSR1 to process %d.', $pid),
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
     * Send a signal to a process.
     *
     * Extracted to a protected method so tests can mock it.
     *
     * @param int $pid    Process ID.
     * @param int $signal Signal constant (e.g. SIGUSR1).
     *
     * @return bool True when posix_kill() returned true; false otherwise.
     */
    protected function sendSignal(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }
}
