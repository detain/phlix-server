<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Server\WebPortal\WebPortalRouter;
use PHPUnit\Framework\TestCase;

/**
 * M-1 (security scan @9e765895) — `POST /api/v1/music/scan` is ADMIN-ONLY.
 *
 * The handler walks an operator-supplied ABSOLUTE path with synchronous
 * filesystem I/O inside a resident HTTP worker and was reachable by ANY
 * authenticated user. The fix mirrors the sibling `LibraryController::scan`
 * posture by moving the registration into WebPortalRouter's AdminMiddleware
 * group — the same house gate that guards `DELETE /api/v1/media/{id}`:
 * unauthenticated → 401 `auth.required`, authenticated non-admin → 403
 * `auth.not_admin` + a permission-denied audit entry, admin → the handler
 * runs. The scan of an over-cap-by-role caller must never touch the disk,
 * so the service double is configured to FAIL LOUDLY if consulted.
 */
final class MusicScanAdminGateTest extends TestCase
{
    /**
     * @param array{id: string, is_admin: int}|null $userRow  findById() answer.
     * @param array<string, mixed>|null             $adminRow findAdminById() answer.
     */
    private function router(?array $userRow, ?array $adminRow, bool $failOnScanCall): WebPortalRouter
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn($userRow);
        $users->method('findAdminById')->willReturn($adminRow);

        $audit = $this->createMock(AuditLogger::class);
        if ($adminRow === null) {
            $audit->method('logPermissionDenied')->willReturnSelf();
        }

        $music = $this->createMock(MusicLibraryService::class);
        if ($failOnScanCall) {
            $music->expects($this->never())->method('scanDirectory');
        } else {
            $music->method('scanDirectory')->willReturn(new ScanResult());
        }

        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $users,
            null,
            null,
            null,
            null,
            $audit,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $music,
        );
    }

    private function scanRequest(?string $userId): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/music/scan';
        $request->userId = $userId;
        $request->body = ['path' => sys_get_temp_dir()];

        return $request;
    }

    public function testAnonymousScanIsRejectedWith401BeforeAnyDiskAccess(): void
    {
        $response = $this->router(null, null, true)->dispatch($this->scanRequest(null));

        $this->assertSame(401, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('auth.required', $decoded['code'] ?? null);
    }

    public function testAuthenticatedNonAdminScanIsRejectedWith403BeforeAnyDiskAccess(): void
    {
        $response = $this->router(
            ['id' => 'u2', 'is_admin' => 0],
            null,
            true
        )->dispatch($this->scanRequest('u2'));

        $this->assertSame(403, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('auth.not_admin', $decoded['code'] ?? null);
    }

    public function testAdminScanReachesTheHandler(): void
    {
        $response = $this->router(
            ['id' => 'u1', 'is_admin' => 1],
            ['id' => 'u1', 'is_admin' => 1],
            false
        )->dispatch($this->scanRequest('u1'));

        $this->assertSame(200, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('scanned', $decoded);
    }

    public function testRouteVanishesWhenAdminCollaboratorsAreUnwired(): void
    {
        // The gate is STRUCTURAL: with no UserRepository/AuditLogger the admin
        // group does not register at all, so the route fails closed to a 404
        // (falls through HttpHandler) instead of degrading to auth-only —
        // the S282 fail-open class must not re-enter through this door.
        $router = new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
        );

        $response = $router->dispatch($this->scanRequest('u1'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->statusCode);
    }
}
