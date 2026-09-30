<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use PHPUnit\Framework\TestCase;

/**
 * L-4 (security scan, CGI/web-portal twin) — `GET /api/v1/libraries` and
 * `GET /api/v1/libraries/{id}` on WebPortalRouter must not hand absolute
 * filesystem roots (`paths`) to authenticated non-admins.
 *
 * Same defect class, same convention as the daemon twin pinned by
 * {@see \Phlix\Tests\Unit\Server\Http\Controllers\LibraryControllerPathsRedactionTest}:
 * the media surface has always admin-gated its raw-file `files` block via
 * {@see WebPortalRouter::isAdminUser()} — the library surface now mirrors it.
 * The two handlers run under AuthMiddleware (WirePathGuard-pinned), so
 * `$request->userId` is the authenticated subject; the gate predicate is the
 * repo-wide admin predicate `is_admin = 1 AND status = 'active'` (AdminMiddleware
 * / findAdminById — see UserRepository::findAdminById) through the wired
 * UserRepository. Disabled/pending admins are NOT admin for disclosure purposes.
 */
final class WebPortalRouterPathsRedactionTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $userRow findById() answer for the caller;
     *                                         findAdminById() is stubbed to mirror
     *                                         the real SQL (`is_admin = 1 AND
     *                                         status = 'active'`), so the fixtures
     *                                         stay faithful to UserRepository under
     *                                         either side of the predicate swap.
     */
    private function router(?array $userRow, LibraryManager $libraryManager): WebPortalRouter
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn($userRow);
        $isActiveAdmin = $userRow !== null
            && ($userRow['is_admin'] ?? 0) === 1
            && ($userRow['status'] ?? '') === 'active';
        $users->method('findAdminById')->willReturn($isActiveAdmin ? $userRow : null);

        return new WebPortalRouter(
            $libraryManager,
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null,
            $users,
            null,
            $this->createMock(UserProfileManager::class),
        );
    }

    private function request(?string $userId): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/libraries';
        $request->userId = $userId;

        return $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function libraryRows(): array
    {
        return [[
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/mnt/media/movies'],
        ]];
    }

    private function adminUser(): array
    {
        return ['id' => 'admin-1', 'is_admin' => 1, 'status' => 'active'];
    }

    private function nonAdminUser(): array
    {
        return ['id' => 'user-1', 'is_admin' => 0, 'status' => 'active'];
    }

    public function testGetLibrariesKeepsPathsForAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getAllLibraries')->willReturn($this->libraryRows());

        $response = $this->router($this->adminUser(), $libraryManager)
            ->getLibraries($this->request('admin-1'), []);

        $this->assertSame(200, $response->statusCode);
        $decoded = $this->json($response);
        $this->assertSame(['/mnt/media/movies'], $decoded['libraries'][0]['paths']);
    }

    public function testGetLibrariesRedactsPathsForNonAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getAllLibraries')->willReturn($this->libraryRows());

        $response = $this->router($this->nonAdminUser(), $libraryManager)
            ->getLibraries($this->request('user-1'), []);

        $decoded = $this->json($response);
        $this->assertArrayNotHasKey('paths', $decoded['libraries'][0]);
        // Browse contract untouched beside the redaction.
        $this->assertSame('lib-1', $decoded['libraries'][0]['id']);
        $this->assertArrayHasKey('item_count', $decoded['libraries'][0]);
    }

    public function testGetLibraryKeepsPathsForAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn($this->libraryRows()[0]);

        $response = $this->router($this->adminUser(), $libraryManager)
            ->getLibrary($this->request('admin-1'), ['id' => 'lib-1']);

        $decoded = $this->json($response);
        $this->assertArrayHasKey('paths', $decoded['library']);
    }

    public function testGetLibraryRedactsPathsForNonAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn($this->libraryRows()[0]);

        $response = $this->router($this->nonAdminUser(), $libraryManager)
            ->getLibrary($this->request('user-1'), ['id' => 'lib-1']);

        $decoded = $this->json($response);
        $this->assertArrayNotHasKey('paths', $decoded['library']);
        $this->assertSame('lib-1', $decoded['library']['id']);
    }

    /**
     * A user row that satisfies the SOFTER legacy predicate (exists, is_admin=1)
     * but not the repo-wide active-only admin predicate — e.g. an account
     * disabled mid-session whose token is still inside the revocation latency
     * window (direct HTTP: AuthManager's 5s status cache; relay: hub-stamped
     * identity the server never re-checks locally per-request).
     */
    private function inactiveAdminUser(string $status): array
    {
        return ['id' => 'admin-1', 'is_admin' => 1, 'status' => $status];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonActiveStatuses(): array
    {
        return ['disabled' => ['disabled'], 'pending' => ['pending'], 'suspended' => ['suspended']];
    }

    /**
     * @dataProvider nonActiveStatuses
     */
    public function testGetLibrariesRedactsPathsForInactiveAdmins(string $status): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getAllLibraries')->willReturn($this->libraryRows());

        $response = $this->router($this->inactiveAdminUser($status), $libraryManager)
            ->getLibraries($this->request('admin-1'), []);

        $this->assertSame(200, $response->statusCode);
        $decoded = $this->json($response);
        $this->assertArrayNotHasKey('paths', $decoded['libraries'][0]);
        // Redaction must not break the browse contract beside it.
        $this->assertSame('lib-1', $decoded['libraries'][0]['id']);
    }

    /**
     * @dataProvider nonActiveStatuses
     */
    public function testGetLibraryRedactsPathsForInactiveAdmins(string $status): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn($this->libraryRows()[0]);

        $response = $this->router($this->inactiveAdminUser($status), $libraryManager)
            ->getLibrary($this->request('admin-1'), ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $decoded = $this->json($response);
        $this->assertArrayNotHasKey('paths', $decoded['library']);
        $this->assertSame('lib-1', $decoded['library']['id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
