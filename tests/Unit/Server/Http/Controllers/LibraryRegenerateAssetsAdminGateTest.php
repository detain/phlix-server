<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * S284 — the admin gate on `POST /api/v1/libraries/{id}/regenerate-assets`, and
 * the idempotency of what it enqueues.
 *
 * ## Which gate, and which construction path
 *
 * `ApplicationRouterWirePathGuardTest::ROUTE_MANIFEST` records this route with an
 * EMPTY route-level middleware list, exactly like the six destructive library
 * routes S272 investigated. That reading is accurate and the inference "therefore
 * ungated" is wrong: the gate is the first statement of the handler
 * (`LibraryController::requireAdmin()`), which the manifest cannot see by
 * construction. This file is the regression net, since the manifest would not
 * notice the gate being deleted.
 *
 * The controller here is built the way `Application::getLibraryController()`
 * builds it — a REAL {@see AdminMiddleware} over a mocked {@see UserRepository},
 * with `setAdminMiddleware()` called. That matters because
 * `requireAdmin()` currently FAILS OPEN when `$this->adminMiddleware` is null
 * (filed as S282, deliberately not fixed here): every test below therefore
 * exercises the wired path, which is the path a served request takes, and none of
 * them depends on the null path being safe.
 *
 * ## Why three cases and not one
 *
 * A 401 alone cannot tell an admin gate from a plain auth gate — both emit it. So
 * the authenticated NON-ADMIN case (403 `auth.not_admin`) is the actual
 * experiment, and the authenticated ADMIN case (202) is the control that stops a
 * blanket-deny bug from reading as a pass. All three run in the same file against
 * the same object graph.
 */
final class LibraryRegenerateAssetsAdminGateTest extends TestCase
{
    /**
     * Build a LibraryController wired exactly as production wires it.
     *
     * @param string|null            $adminUserId Id `findAdminById()` treats as an
     *                                            admin; null means nobody is.
     * @param ScanJobRepository|null $scanJobs    Optional pre-configured mock.
     * @param bool                   $libraryExists Whether `getLibrary()` resolves.
     */
    private function makeController(
        ?string $adminUserId,
        ?ScanJobRepository $scanJobs = null,
        bool $libraryExists = true
    ): LibraryController {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => ($adminUserId !== null && $id === $adminUserId)
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn(
            $libraryExists ? ['id' => 'lib-1', 'name' => 'Movies'] : null
        );

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

    /** Case 1 of 3 — anonymous. The floor of the control, not evidence on its own. */
    public function testAnonymousRequestIsRejectedWith401(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::never())->method('enqueueIfNoneActiveOfType');

        $controller = $this->makeController('admin-1', $scanJobs);

        $request = new Request();
        // userId intentionally left null — no credential presented.

        $response = $controller->regenerateAssets($request, ['id' => 'lib-1']);

        self::assertSame(401, $response->statusCode);
        self::assertSame('auth.required', $this->decode($response)['code'] ?? null);
    }

    /**
     * Case 2 of 3 — AUTHENTICATED NON-ADMIN. **This is the experiment.**
     *
     * A global auth middleware would let this request through; only an admin gate
     * refuses it. `auth.not_admin` (rather than `auth.required`) is what proves
     * the ADMIN branch fired.
     */
    public function testAuthenticatedNonAdminIsRejectedWith403(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::never())->method('enqueueIfNoneActiveOfType');

        // 'admin-1' is the only admin; the caller is 'user-1'.
        $controller = $this->makeController('admin-1', $scanJobs);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->regenerateAssets($request, ['id' => 'lib-1']);

        self::assertSame(
            403,
            $response->statusCode,
            'a 202 here means any logged-in user can queue a library-wide ffmpeg pass'
        );
        self::assertSame(
            'auth.not_admin',
            $this->decode($response)['code'] ?? null,
            'the refusal must come from the ADMIN branch, not the auth branch'
        );
    }

    /**
     * Case 3 of 3 — authenticated ADMIN. The SUCCEEDING control beside the two
     * refusals: without it, a gate that denied every request would pass both.
     */
    public function testAuthenticatedAdminReachesTheHandlerAndEnqueuesAMediaAssetsJob(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::once())
            ->method('enqueueIfNoneActiveOfType')
            ->with('lib-1', 'media_assets')
            ->willReturn(['job_id' => 'job-1', 'created' => true]);

        $controller = $this->makeController('admin-1', $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->regenerateAssets($request, ['id' => 'lib-1']);

        self::assertSame(202, $response->statusCode);
        $body = $this->decode($response);
        self::assertSame('queued', $body['status'] ?? null);
        self::assertSame('job-1', $body['job_id'] ?? null);
    }

    /**
     * The endpoint must use the DE-DUPLICATING enqueue, not the plain one every
     * sibling action uses. Asserted as "the plain `enqueue()` is never called",
     * because a handler that called both would still look correct from the
     * response body alone.
     */
    public function testItNeverUsesThePlainNonIdempotentEnqueue(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::never())->method('enqueue');
        $scanJobs->method('enqueueIfNoneActiveOfType')
            ->willReturn(['job_id' => 'job-1', 'created' => true]);

        $controller = $this->makeController('admin-1', $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        self::assertSame(202, $controller->regenerateAssets($request, ['id' => 'lib-1'])->statusCode);
    }

    /**
     * A second request while one is already active reports the EXISTING job
     * rather than minting a second one — and says so, so the admin UI can poll
     * `scan-status` with an id that is really running.
     */
    public function testASecondRequestReportsTheJobAlreadyDoingTheWork(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueueIfNoneActiveOfType')
            ->willReturn(['job_id' => 'job-already', 'created' => false]);

        $controller = $this->makeController('admin-1', $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->regenerateAssets($request, ['id' => 'lib-1']);
        $body = $this->decode($response);

        self::assertSame(202, $response->statusCode);
        self::assertSame('already_queued', $body['status'] ?? null);
        self::assertSame('job-already', $body['job_id'] ?? null);
    }

    /**
     * A missing library is a 404, and it must not enqueue — otherwise the queue
     * accumulates jobs for libraries that do not exist.
     */
    public function testAnAdminRequestForAMissingLibraryIs404AndEnqueuesNothing(): void
    {
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects(self::never())->method('enqueueIfNoneActiveOfType');

        $controller = $this->makeController('admin-1', $scanJobs, false);

        $request = new Request();
        $request->userId = 'admin-1';

        $response = $controller->regenerateAssets($request, ['id' => 'nope']);

        self::assertSame(404, $response->statusCode);
    }
}
