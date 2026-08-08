<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * S272 — pins the admin gate on the six destructive
 * `POST /api/v1/libraries/{id}/*` actions.
 *
 * ## Why this file exists
 *
 * `ApplicationRouterWirePathGuardTest::ROUTE_MANIFEST` records these six routes
 * with an EMPTY route-level middleware list, while every `/api/v1/admin/*` route
 * records `[AdminMiddleware]`. That reading is literally ACCURATE —
 * {@see \Phlix\Server\Core\Application::loadLibraryRoutes()} registers them with
 * no middleware array — but the security inference drawn from it was wrong.
 *
 * The gate is applied INSIDE the controller: every one of the six actions calls
 * `LibraryController::requireAdmin()` as its first statement, which delegates to
 * {@see AdminMiddleware::checkAccess()}. The manifest only ever describes
 * ROUTE-level middleware, so an in-controller gate is invisible to it.
 *
 * This was confirmed at runtime against a booted server (Workerman, real MySQL):
 * anonymous → 401, authenticated NON-ADMIN → 403 `auth.not_admin`, admin → 202.
 * These tests are the regression net for that behaviour, so the gate cannot be
 * removed silently — the manifest would not notice.
 *
 * ## What each test proves
 *
 * Each route is driven THREE ways, because a 401 alone proves nothing: a global
 * auth middleware and an admin gate emit the same 401. Only the authenticated
 * non-admin case distinguishes them, and it needs the succeeding admin case
 * beside it so a blanket-deny bug cannot read as a pass.
 *
 * A real {@see AdminMiddleware} is wired in (over a mocked {@see UserRepository})
 * rather than a stub, so these tests exercise the same object graph
 * `Application::getLibraryController()` builds in production.
 *
 * NB: this file carries NO coverage-metadata annotation, deliberately. Per this
 * repo's policy (S141, enforced by CoverageMetadataPolicyTest) such a marker in
 * `tests/` silently DISCARDS every other file the test executes. The policy
 * check matches the token itself, so it must not be spelled out even in prose.
 */
final class LibraryDestructiveRoutesAdminGateTest extends TestCase
{
    /**
     * The six destructive actions, as `[controller method, scan-job type]`.
     *
     * The job type is what the action enqueues on the admin (success) path; it
     * doubles as proof that the ADMIN request reached the handler body rather
     * than being short-circuited by the gate.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function destructiveActionProvider(): array
    {
        return [
            'POST /api/v1/libraries/{id}/scan'           => ['scan', 'scan'],
            'POST /api/v1/libraries/{id}/rescan'         => ['rescan', 'rescan'],
            'POST /api/v1/libraries/{id}/prune'          => ['prune', 'prune'],
            'POST /api/v1/libraries/{id}/clear-metadata' => ['clearMetadata', 'clear_metadata'],
            'POST /api/v1/libraries/{id}/clear-artwork'  => ['clearArtwork', 'clear_artwork'],
            'POST /api/v1/libraries/{id}/delete-all'     => ['deleteAll', 'delete_all'],
        ];
    }

    /**
     * Build a LibraryController wired exactly as production wires it: a real
     * AdminMiddleware over a mocked UserRepository.
     *
     * @param string|null            $adminUserId Id that `findAdminById()` should
     *                                            treat as an admin. `null` means
     *                                            NO user is an admin.
     * @param ScanJobRepository|null $scanJobs    Optional pre-configured mock.
     */
    private function makeController(
        ?string $adminUserId,
        ?ScanJobRepository $scanJobs = null
    ): LibraryController {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => ($adminUserId !== null && $id === $adminUserId)
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $libraryManager = $this->createMock(LibraryManager::class);
        // Any library id resolves, so a 404 can never be mistaken for a refusal.
        $libraryManager->method('getLibrary')->willReturn(['id' => 'lib-1', 'name' => 'Movies']);

        $controller = new LibraryController(
            $libraryManager,
            $scanJobs ?? $this->createMock(ScanJobRepository::class)
        );
        $controller->setAdminMiddleware(
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = json_decode($response->body, true);
        self::assertIsArray($body, 'response body must be a JSON object');
        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * Case 1 of 3 — anonymous. Expected 401.
     *
     * On its own this proves nothing (see the class docblock); it is the floor
     * of the three-way control.
     *
     * @dataProvider destructiveActionProvider
     */
    public function testAnonymousRequestIsRejectedWith401(string $method): void
    {
        $controller = $this->makeController('admin-1');

        $request = new Request();
        // userId intentionally left null — no credential presented.

        /** @var Response $response */
        $response = $controller->{$method}($request, ['id' => 'lib-1']);

        self::assertSame(401, $response->statusCode, "{$method}() must 401 an anonymous caller");
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
    }

    /**
     * Case 2 of 3 — AUTHENTICATED NON-ADMIN. Expected 403.
     *
     * **This is the whole experiment.** A global auth middleware would let this
     * request through; only an admin gate refuses it. The distinct
     * `auth.not_admin` code (as opposed to `auth.required`) proves the ADMIN
     * branch fired rather than the auth branch, so a regression that downgrades
     * the gate to auth-only reds here.
     *
     * @dataProvider destructiveActionProvider
     */
    public function testAuthenticatedNonAdminIsRejectedWith403(string $method): void
    {
        // 'admin-1' is the only admin; the caller is 'user-1'.
        $controller = $this->makeController('admin-1');

        $request = new Request();
        $request->userId = 'user-1';

        /** @var Response $response */
        $response = $controller->{$method}($request, ['id' => 'lib-1']);

        self::assertSame(
            403,
            $response->statusCode,
            "{$method}() must 403 an authenticated NON-ADMIN — a 200/202 here means the "
            . 'destructive action is reachable by any logged-in user'
        );
        self::assertSame(
            'auth.not_admin',
            $this->decode($response)['code'] ?? null,
            "{$method}() must refuse on the ADMIN branch, not the auth branch"
        );
    }

    /**
     * Case 3 of 3 — authenticated ADMIN. Expected 202, job enqueued.
     *
     * The succeeding control beside the refusal: without it, a gate that denied
     * EVERY request would pass the 401 and 403 cases and look correct.
     *
     * @dataProvider destructiveActionProvider
     */
    public function testAuthenticatedAdminReachesHandlerAndEnqueuesJob(
        string $method,
        string $jobType
    ): void {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::once())
            ->method('enqueue')
            ->with('lib-1', $jobType)
            ->willReturn('job-1');

        $controller = $this->makeController('admin-1', $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        // delete-all additionally requires an explicit confirmation flag.
        $request->body = ['confirm' => true];

        /** @var Response $response */
        $response = $controller->{$method}($request, ['id' => 'lib-1']);

        self::assertSame(
            202,
            $response->statusCode,
            "{$method}() must reach the handler for an admin (got {$response->statusCode})"
        );
        self::assertSame('queued', $this->decode($response)['status'] ?? null);
    }

    /**
     * The gate must be applied by EVERY one of the six actions, not merely by
     * the ones a reviewer happened to spot-check.
     *
     * This is a shape assertion over the whole set, so adding a seventh
     * destructive action without gating it is caught by the provider growing
     * rather than by anyone remembering to write a test.
     */
    public function testEveryDestructiveActionIsCoveredByTheThreeWayControl(): void
    {
        $actions = self::destructiveActionProvider();

        self::assertCount(6, $actions, 'the six destructive routes named in S272');

        foreach ($actions as $route => [$method]) {
            self::assertTrue(
                method_exists(LibraryController::class, $method),
                "{$route} maps to LibraryController::{$method}(), which must exist"
            );
        }
    }
}
