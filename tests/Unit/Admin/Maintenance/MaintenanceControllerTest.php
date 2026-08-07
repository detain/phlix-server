<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin\Maintenance;

use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceTask;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see MaintenanceController} — the HTTP contract S78 is written against (S77).
 *
 * The two things asserted hardest here are the two an S78 author will build on:
 * WHICH status code each mode answers, and that a repeated click does not queue
 * a second expensive run.
 */
final class MaintenanceControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    private function request(
        string $method = 'POST',
        ?string $userId = 'admin-1',
        array $body = [],
        array $query = [],
    ): Request {
        $request = new Request();
        $request->method = $method;
        $request->path = '/api/v1/admin/maintenance/reap-scan-jobs';
        $request->userId = $userId;
        $request->body = $body;
        $request->query = $query;

        return $request;
    }

    /**
     * An {@see AdminMiddleware} that admits `admin-1` and refuses everyone else.
     */
    private function adminGuard(bool $isAdmin = true): AdminMiddleware
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $isAdmin && $id === 'admin-1' ? ['id' => $id] : null
        );

        return new AdminMiddleware($users, $this->createMock(AuditLogger::class));
    }

    private function controller(
        ?MaintenanceJobRepository $jobs = null,
        ?MaintenanceTaskRunner $runner = null,
        ?AdminMiddleware $guard = null,
    ): MaintenanceController {
        return new MaintenanceController(
            $jobs ?? $this->createMock(MaintenanceJobRepository::class),
            $runner ?? $this->createMock(MaintenanceTaskRunner::class),
            $guard ?? $this->adminGuard(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, 'Every response must be a JSON object: ' . $response->body);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // -----------------------------------------------------------------
    // Auth — the in-body second check
    // -----------------------------------------------------------------

    /**
     * 🚨 EVERY handler re-checks admin in its own body.
     *
     * These routes already sit inside `AdminRoutes`' `AdminMiddleware` group, so
     * this is belt and braces — but two of the five tasks are destructive, and
     * this repo has already shipped a route group whose middleware silently did
     * not apply. Asserted for all EIGHT handlers, because a check present on
     * seven of them is the exact shape of the bug.
     */
    public function test_every_handler_refuses_an_anonymous_caller_with_401(): void
    {
        $controller = $this->controller();
        $anonymous = $this->request(userId: null);

        $handlers = [
            'tasks', 'jobs', 'job',
            'storageSnapshot', 'reapScanJobs', 'reapTranscodeJobs',
            'cleanupOrphanedStats', 'dedupePaths',
        ];

        foreach ($handlers as $handler) {
            /** @var Response $response */
            $response = $controller->{$handler}($anonymous, ['id' => 'x']);

            self::assertSame(401, $response->statusCode, "{$handler}() admitted an anonymous caller");
            self::assertFalse($this->body($response)['success']);
            self::assertSame('auth.required', $this->body($response)['code']);
        }

        self::assertCount(8, $handlers, 'ANTI-VACUITY: the handler list must cover the whole surface.');
    }

    /**
     * A signed-in NON-admin is refused 403 — the 401 above is about being
     * anonymous, which is a different question.
     */
    public function test_a_non_admin_is_refused_with_403(): void
    {
        $controller = $this->controller(guard: $this->adminGuard(isAdmin: false));

        $response = $controller->cleanupOrphanedStats($this->request(userId: 'user-9'), []);

        self::assertSame(403, $response->statusCode);
        self::assertSame('auth.not_admin', $this->body($response)['code']);
    }

    /**
     * THE CONTROL that makes the two refusals above discriminating: the SAME
     * controller admits a real admin.
     */
    public function test_an_admin_is_admitted(): void
    {
        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->method('run')->willReturn(['reaped' => 0]);

        $response = $this->controller(runner: $runner)->reapScanJobs($this->request(), []);

        self::assertSame(200, $response->statusCode);
        self::assertTrue($this->body($response)['success']);
    }

    // -----------------------------------------------------------------
    // Synchronous tasks — 200 with the result inline
    // -----------------------------------------------------------------

    public function test_a_synchronous_task_runs_inline_and_answers_200_with_its_result(): void
    {
        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(MaintenanceTask::REAP_SCAN_JOBS, ['older_than_seconds' => 90000])
            ->willReturn(['reaped' => 2, 'older_than_seconds' => 90000]);

        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->expects(self::never())->method('enqueue');

        $response = $this->controller($jobs, $runner)
            ->reapScanJobs($this->request(body: ['older_than_seconds' => 90000]), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->body($response);
        self::assertSame(MaintenanceTask::MODE_SYNC, $body['mode']);
        self::assertSame(MaintenanceTask::REAP_SCAN_JOBS, $body['task']);
        self::assertSame(['reaped' => 2, 'older_than_seconds' => 90000], $body['data']);
    }

    /**
     * The request BODY reaches the runner.
     *
     * ⚠ This is the assertion that catches the `$request->jsonBody` mistake:
     * that property does not exist on `Phlix\Server\Http\Request` (the decoded
     * body is `Request::$body`), so a handler reading it passes `[]` for every
     * parameter a client sends. {@see \Phlix\Server\Http\Controllers\Admin\BackupController}
     * has that defect today — its `label` option is permanently null.
     */
    public function test_the_request_body_actually_reaches_the_runner(): void
    {
        $seen = null;
        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->method('run')->willReturnCallback(
            static function (string $task, array $params) use (&$seen): array {
                $seen = $params;

                return [];
            }
        );

        $this->controller(runner: $runner)
            ->cleanupOrphanedStats($this->request(body: ['limit' => 25]), []);

        self::assertSame(['limit' => 25], $seen, 'The handler dropped the request body.');
    }

    /**
     * A throwing synchronous task answers 500 with the MESSAGE, not a generic
     * string: on an admin-only endpoint "`media_items` reports zero rows" is
     * exactly what the operator needs in order to act.
     */
    public function test_a_failing_synchronous_task_answers_500_with_the_reason(): void
    {
        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->method('run')->willThrowException(new RuntimeException('`users` reports zero rows'));

        $response = $this->controller(runner: $runner)->cleanupOrphanedStats($this->request(), []);

        self::assertSame(500, $response->statusCode);
        self::assertFalse($this->body($response)['success']);
        self::assertSame('`users` reports zero rows', $this->body($response)['message']);
    }

    // -----------------------------------------------------------------
    // Queued tasks — 202, and never run inline
    // -----------------------------------------------------------------

    /**
     * 🚨 A QUEUED task is enqueued and NEVER executed in the request.
     *
     * `storage-snapshot` shells out to `du -sb` per vault bucket — on the live
     * host `/vault1` and `/vault2` hold the whole media library — so running it
     * inline would stall every concurrent connection on the Workerman worker
     * that served the click. `never()->method('run')` is the load-bearing half
     * of this test.
     */
    public function test_a_queued_task_is_enqueued_and_never_run_in_the_request(): void
    {
        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::never())->method('run');

        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->expects(self::once())
            ->method('enqueue')
            ->with(MaintenanceTask::STORAGE_SNAPSHOT, [], 'admin-1')
            ->willReturn(['job' => ['id' => 'job-1', 'status' => 'queued'], 'created' => true]);

        $response = $this->controller($jobs, $runner)->storageSnapshot($this->request(), []);

        self::assertSame(202, $response->statusCode);
        $body = $this->body($response);
        self::assertSame(MaintenanceTask::MODE_QUEUED, $body['mode']);
        self::assertTrue($body['created']);
        self::assertSame('job-1', $body['data']['job']['id']);
    }

    /**
     * The same for `dedupe-paths`, with its params — a whole-table scan plus a
     * transaction per group must not happen on the event loop either.
     */
    public function test_dedupe_is_queued_with_its_params_and_not_run_inline(): void
    {
        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::never())->method('run');

        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->expects(self::once())
            ->method('enqueue')
            ->with(MaintenanceTask::DEDUPE_PATHS, ['apply' => true], 'admin-1')
            ->willReturn(['job' => ['id' => 'job-2'], 'created' => true]);

        $response = $this->controller($jobs, $runner)
            ->dedupePaths($this->request(body: ['apply' => true]), []);

        self::assertSame(202, $response->statusCode);
    }

    /**
     * A REPEAT click while the same task is pending answers 200 with
     * `created: false` and the existing job — not a second 202.
     *
     * The distinction is the contract S78 keys "already running" off, and it is
     * why a double-clicked button cannot start two `du -sb` walks.
     */
    public function test_a_repeat_click_answers_200_with_created_false(): void
    {
        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->method('enqueue')->willReturn([
            'job' => ['id' => 'already-1', 'status' => 'running'],
            'created' => false,
        ]);

        $response = $this->controller($jobs)->storageSnapshot($this->request(), []);

        self::assertSame(200, $response->statusCode);
        self::assertFalse($this->body($response)['created']);
        self::assertSame('already-1', $this->body($response)['data']['job']['id']);
    }

    public function test_a_failing_enqueue_answers_500(): void
    {
        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->method('enqueue')->willThrowException(new RuntimeException('table missing'));

        $response = $this->controller($jobs)->storageSnapshot($this->request(), []);

        self::assertSame(500, $response->statusCode);
        self::assertSame('Failed to queue maintenance task', $this->body($response)['error']);
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    public function test_the_task_catalogue_is_served(): void
    {
        $response = $this->controller()->tasks($this->request('GET'), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->body($response);
        self::assertSame(count(MaintenanceTask::ALL), $body['count']);
        self::assertSame(MaintenanceTask::ALL, array_column($body['data'], 'task'));
    }

    /**
     * The `limit`/`task` query parameters are read via `Request::queryInt()` /
     * `queryString()`.
     *
     * ⚠ Never `$_GET`: Workerman does not populate the superglobal at all, so a
     * `$_GET` read here would be null on every request — the defect that left
     * the hub's `:8804` surface authenticating nobody.
     */
    public function test_the_jobs_list_reads_its_filters_from_the_query(): void
    {
        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->expects(self::once())
            ->method('recent')
            ->with(7, MaintenanceTask::DEDUPE_PATHS)
            ->willReturn([['id' => 'j1']]);

        $request = $this->request('GET', query: ['limit' => '7', 'task' => MaintenanceTask::DEDUPE_PATHS]);
        $response = $this->controller($jobs)->jobs($request, []);

        self::assertSame(200, $response->statusCode);
        self::assertSame(1, $this->body($response)['count']);
    }

    public function test_an_unknown_task_filter_is_a_400_not_an_empty_list(): void
    {
        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->expects(self::never())->method('recent');

        $response = $this->controller($jobs)->jobs($this->request('GET', query: ['task' => 'bogus']), []);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('bogus', $this->body($response)['error']);
    }

    public function test_a_known_job_is_returned_and_an_unknown_one_is_404(): void
    {
        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->method('findById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'j1' ? ['id' => 'j1', 'status' => 'completed'] : null
        );

        $found = $this->controller($jobs)->job($this->request('GET'), ['id' => 'j1']);
        self::assertSame(200, $found->statusCode);
        self::assertSame('j1', $this->body($found)['data']['id']);

        $missing = $this->controller($jobs)->job($this->request('GET'), ['id' => 'nope']);
        self::assertSame(404, $missing->statusCode);
        self::assertFalse($this->body($missing)['success']);
    }

    public function test_a_missing_job_id_is_a_400(): void
    {
        $response = $this->controller()->job($this->request('GET'), []);

        self::assertSame(400, $response->statusCode);
    }

    /**
     * Every endpoint answers the `{success: …}` envelope {@see \Phlix\Server\Http\Controllers\Admin\BackupController}
     * established — the shape S78 parses. Asserted across the whole surface so
     * one handler cannot quietly answer something else.
     */
    public function test_every_endpoint_answers_the_success_envelope(): void
    {
        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->method('enqueue')->willReturn(['job' => ['id' => 'j'], 'created' => true]);
        $jobs->method('recent')->willReturn([]);
        $jobs->method('findById')->willReturn(['id' => 'j']);

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->method('run')->willReturn([]);

        $controller = $this->controller($jobs, $runner);

        foreach (
            ['tasks', 'jobs', 'job', 'storageSnapshot', 'reapScanJobs',
                'reapTranscodeJobs', 'cleanupOrphanedStats', 'dedupePaths'] as $handler
        ) {
            /** @var Response $response */
            $response = $controller->{$handler}($this->request(), ['id' => 'j']);

            self::assertArrayHasKey('success', $this->body($response), "{$handler}() lost the envelope");
            self::assertTrue($this->body($response)['success']);
        }
    }
}
