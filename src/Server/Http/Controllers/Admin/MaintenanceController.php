<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceTask;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;

/**
 * One-off admin maintenance tasks — the backend for the admin Tasks page (S77).
 *
 * ## The contract, in one place
 *
 * | Verb | Path | Mode |
 * |------|------|------|
 * | GET  | `/api/v1/admin/maintenance/tasks`                  | — (catalogue) |
 * | GET  | `/api/v1/admin/maintenance/jobs`                   | — (recent queued jobs) |
 * | GET  | `/api/v1/admin/maintenance/jobs/{id}`              | — (one job, for polling) |
 * | POST | `/api/v1/admin/maintenance/storage-snapshot`       | queued → `202` |
 * | POST | `/api/v1/admin/maintenance/reap-scan-jobs`         | sync → `200` |
 * | POST | `/api/v1/admin/maintenance/reap-transcode-jobs`    | sync → `200` |
 * | POST | `/api/v1/admin/maintenance/cleanup-orphaned-stats` | sync → `200` |
 * | POST | `/api/v1/admin/maintenance/dedupe-paths`           | queued → `202` |
 *
 * Every response is `{success: bool, …}`, matching {@see BackupController} —
 * the shape the step names as the template and the one the admin SPA's other
 * task-style buttons already parse.
 *
 * A `202` carries `data.job`, a `maintenance_jobs` row. Poll
 * `GET …/maintenance/jobs/{id}` until `status` leaves `queued`/`running`; the
 * `result` object is whatever the task reported. A repeat POST while the same
 * task is still pending returns `200` with `data.created = false` and the
 * EXISTING job rather than queuing a second one — a double-clicked button must
 * not run `du -sb` over the vault twice.
 *
 * ## Why `requireAdmin()` is in every handler body
 *
 * These routes are registered inside {@see \Phlix\Server\Http\Routes\AdminRoutes}'
 * group, which already carries {@see AdminMiddleware}. The in-body check is
 * deliberate belt-and-braces for the two destructive tasks
 * (`cleanup-orphaned-stats` deletes rows; `dedupe-paths --apply` merges media
 * items): the group's middleware is one edit away from being lost, and this
 * repo has already shipped a route group whose middleware silently did not
 * apply. A defence that only exists in the registration is a defence that a
 * refactor can delete without a single test noticing. Since S338 the guard
 * is a REQUIRED constructor parameter, so there is no construction path on
 * which the in-body check silently does nothing.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 1.9
 */
class MaintenanceController
{
    /**
     * @param MaintenanceJobRepository $jobs       Queue store for the async tasks.
     * @param MaintenanceTaskRunner    $runner     Executes a synchronous task inline.
     * @param AdminMiddleware          $adminGuard Second admin check, run in each
     *        handler body. REQUIRED since S338: an optional guard is exactly the
     *        shape that ends up null in production (PHP-DI's `autowire()` SKIPS
     *        optional parameters), silently turning the in-body check into a
     *        no-op that lets any logged-in user reach the destructive tasks.
     */
    public function __construct(
        private readonly MaintenanceJobRepository $jobs,
        private readonly MaintenanceTaskRunner $runner,
        private readonly AdminMiddleware $adminGuard,
    ) {
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /**
     * `GET /api/v1/admin/maintenance/tasks` — the catalogue the Tasks page renders.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function tasks(Request $request, array $params): Response
    {
        $denied = $this->requireAdmin($request);
        if ($denied !== null) {
            return $denied;
        }

        $catalogue = MaintenanceTask::catalogue();

        return (new Response())->json([
            'success' => true,
            'data' => $catalogue,
            'count' => count($catalogue),
        ]);
    }

    /**
     * `GET /api/v1/admin/maintenance/jobs` — recent queued-task runs, newest first.
     *
     * Query: `limit` (clamped by the repository), `task` (filter; an unknown
     * name is a 400 rather than a silently empty list).
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function jobs(Request $request, array $params): Response
    {
        $denied = $this->requireAdmin($request);
        if ($denied !== null) {
            return $denied;
        }

        // `queryInt`/`queryString`, never `$_GET`: Workerman never populates the
        // superglobal, so a `$_GET` read here would be null on every request.
        $limit = $request->queryInt('limit', MaintenanceJobRepository::DEFAULT_RECENT_LIMIT);

        $taskRaw = $request->queryString('task');
        $task = is_string($taskRaw) && $taskRaw !== '' ? $taskRaw : null;
        if ($task !== null && !MaintenanceTask::isValid($task)) {
            return $this->badRequest('Unknown maintenance task: ' . $task);
        }

        try {
            $rows = $this->jobs->recent($limit, $task);
        } catch (Throwable $e) {
            return $this->failure('Failed to list maintenance jobs', $e);
        }

        return (new Response())->json([
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
        ]);
    }

    /**
     * `GET /api/v1/admin/maintenance/jobs/{id}` — one job, for progress polling.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters; `id`.
     */
    public function job(Request $request, array $params): Response
    {
        $denied = $this->requireAdmin($request);
        if ($denied !== null) {
            return $denied;
        }

        $id = $params['id'] ?? '';
        if ($id === '') {
            return $this->badRequest('Missing job id');
        }

        try {
            $job = $this->jobs->findById($id);
        } catch (Throwable $e) {
            return $this->failure('Failed to read maintenance job', $e);
        }

        if ($job === null) {
            return (new Response())->status(404)->json([
                'success' => false,
                'error' => 'Maintenance job not found',
            ]);
        }

        return (new Response())->json(['success' => true, 'data' => $job]);
    }

    // -----------------------------------------------------------------
    // Actions — one thin handler each, all through runTask()
    // -----------------------------------------------------------------

    /**
     * `POST /api/v1/admin/maintenance/storage-snapshot` — QUEUED.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function storageSnapshot(Request $request, array $params): Response
    {
        return $this->runTask($request, MaintenanceTask::STORAGE_SNAPSHOT);
    }

    /**
     * `POST /api/v1/admin/maintenance/reap-scan-jobs` — SYNC.
     *
     * Body: `{"older_than_seconds": int}`. Values below
     * {@see MaintenanceTaskRunner::MIN_SCAN_JOB_AGE_SECONDS} are raised to it
     * and the response says so in `data.floor_applied`.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function reapScanJobs(Request $request, array $params): Response
    {
        return $this->runTask($request, MaintenanceTask::REAP_SCAN_JOBS);
    }

    /**
     * `POST /api/v1/admin/maintenance/reap-transcode-jobs` — SYNC.
     *
     * Body: `{"older_than_seconds": int}`.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function reapTranscodeJobs(Request $request, array $params): Response
    {
        return $this->runTask($request, MaintenanceTask::REAP_TRANSCODE_JOBS);
    }

    /**
     * `POST /api/v1/admin/maintenance/cleanup-orphaned-stats` — SYNC, DESTRUCTIVE.
     *
     * Body: `{"limit": int}` (per table, clamped). `data.truncated = true`
     * means the cap was hit and there is more to remove.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function cleanupOrphanedStats(Request $request, array $params): Response
    {
        return $this->runTask($request, MaintenanceTask::CLEANUP_ORPHANED_STATS);
    }

    /**
     * `POST /api/v1/admin/maintenance/dedupe-paths` — QUEUED, DESTRUCTIVE when applied.
     *
     * Body: `{"apply": bool, "batch_size": int}`. `apply` defaults to FALSE, so
     * the plain button is a preview and only an explicit `true` deletes rows.
     *
     * @param Request               $request The HTTP request.
     * @param array<string, string> $params  Route parameters (unused).
     */
    public function dedupePaths(Request $request, array $params): Response
    {
        return $this->runTask($request, MaintenanceTask::DEDUPE_PATHS);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * The one path every action takes: admin check → mode → run or enqueue.
     *
     * Whether a task runs inline is read from {@see MaintenanceTask::mode()},
     * never decided per handler. That is what keeps a future task from being
     * added as "just one more sync one" and stalling the event loop: the
     * declaration and the dispatch are the same fact.
     */
    private function runTask(Request $request, string $task): Response
    {
        $denied = $this->requireAdmin($request);
        if ($denied !== null) {
            return $denied;
        }

        // The decoded body is `$request->body` — filled by both `fromGlobals()`
        // and `fromWorkerman()`. `Phlix\Server\Http\Request` has no `jsonBody`
        // property; reading a non-existent one yields null with an "undefined
        // property" warning, so a handler that used it would silently ignore
        // every parameter a client sent — and PHPStan L9 does not flag it.
        // {@see BackupController::create()} and `::updateSchedule()` carried
        // exactly that dead read until S271 fixed them (`label`,
        // `auto_backup_interval_days` and `retention_count` were permanently
        // null for every caller). `::restore()` never read a body at all.
        $body = $request->body;

        if (MaintenanceTask::isSynchronous($task)) {
            try {
                $result = $this->runner->run($task, $body);
            } catch (Throwable $e) {
                return $this->failure('Maintenance task failed', $e);
            }

            return (new Response())->json([
                'success' => true,
                'task' => $task,
                'mode' => MaintenanceTask::MODE_SYNC,
                'data' => $result,
            ]);
        }

        try {
            $enqueued = $this->jobs->enqueue($task, $body, $request->userId);
        } catch (Throwable $e) {
            return $this->failure('Failed to queue maintenance task', $e);
        }

        // 202 for a NEW job, 200 for "one was already pending". Both are
        // successes and both carry the job to poll; the distinction is there so
        // the UI can say "already running" instead of implying it started a
        // second run.
        return (new Response())
            ->status($enqueued['created'] ? 202 : 200)
            ->json([
                'success' => true,
                'task' => $task,
                'mode' => MaintenanceTask::MODE_QUEUED,
                'created' => $enqueued['created'],
                'data' => ['job' => $enqueued['job']],
            ]);
    }

    /**
     * Belt-and-braces admin check, run inside every handler body.
     *
     * @return Response|null Null to continue; a 401/403 to short-circuit.
     */
    private function requireAdmin(Request $request): ?Response
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return (new Response())->status(401)->json([
                'success' => false,
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $status = $this->adminGuard->checkAccess($request);
        if ($status === null) {
            return null;
        }

        return (new Response())->status($status)->json([
            'success' => false,
            'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
            'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
        ]);
    }

    private function badRequest(string $message): Response
    {
        return (new Response())->status(400)->json([
            'success' => false,
            'error' => $message,
        ]);
    }

    /**
     * The 500 shape. The exception MESSAGE is surfaced deliberately: these are
     * admin-only endpoints whose failures ("`media_items` reports zero rows",
     * "TranscodeManager is unavailable") are exactly what the operator needs in
     * order to act, and hiding them behind a generic string is what made the
     * DLNA and plugin failures in this repo so hard to diagnose.
     */
    private function failure(string $error, Throwable $e): Response
    {
        return (new Response())->status(500)->json([
            'success' => false,
            'error' => $error,
            'message' => $e->getMessage(),
        ]);
    }
}
