<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;

/**
 * L-4 (security scan) — the daemon `GET /api/v1/libraries` and
 * `GET /api/v1/libraries/{id}` responses must NOT carry the library `paths`
 * (absolute filesystem roots) to authenticated NON-admin users.
 *
 * ## The defect this pins shut
 *
 * `LibraryRow::toArray()` returns the raw DB row, `paths` included, and both
 * handlers echoed it verbatim — while the sibling media-item surface has always
 * admin-gated its raw-file `files` block (WebPortalRouter::getMediaItem →
 * `MediaItemShaper::shapeDetail(..., $isAdmin)`). The estate consumer sweep
 * shows `paths` is read ONLY by admin surfaces (phlix-ui admin LibrariesPage,
 * console AdminLibrariesScreen; mobile types declare it optional), so the
 * non-admin payload simply stops carrying it — no `has_custom_path` substitute
 * is minted because nothing asks for presence without values.
 *
 * The soft gate is {@see AdminMiddleware::isAdmin()} — deliberately NOT
 * `checkAccess()`, which audits every non-admin browse as a permission
 * denial and publishes RequestContext state as side effects.
 */
final class LibraryControllerPathsRedactionTest extends TestCase
{
    /**
     * Real AdminMiddleware over a UserRepository whose findAdminById() answers
     * an admin row for 'admin-1' only; the audit double must never fire (the
     * soft gate does not audit).
     */
    private function controller(
        LibraryManager $libraryManager,
        ?ItemRepository $itemRepository = null
    ): LibraryController {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->never())->method('logPermissionDenied');

        return new LibraryController(
            $libraryManager,
            $this->createMock(ScanJobRepository::class),
            new AdminMiddleware($users, $audit),
            $itemRepository
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
        return [
            [
                'id' => 'lib-1',
                'name' => 'Movies',
                'type' => 'movie',
                'paths' => ['/mnt/media/movies'],
            ],
        ];
    }

    public function testIndexKeepsPathsForAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getAllLibraries')->willReturn($this->libraryRows());

        $response = $this->controller($libraryManager)->index($this->request('admin-1'), []);

        $this->assertSame(200, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('paths', $decoded['libraries'][0]);
        $this->assertSame(['/mnt/media/movies'], $decoded['libraries'][0]['paths']);
    }

    public function testIndexRedactsPathsForAuthenticatedNonAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getAllLibraries')->willReturn($this->libraryRows());

        $response = $this->controller($libraryManager)->index($this->request('user-1'), []);

        $this->assertSame(200, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('paths', $decoded['libraries'][0]);
        // The rest of the browse contract is untouched.
        $this->assertSame('lib-1', $decoded['libraries'][0]['id']);
        $this->assertSame('Movies', $decoded['libraries'][0]['name']);
    }

    public function testShowKeepsPathsForAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn($this->libraryRows()[0]);

        $response = $this->controller($libraryManager)->show($this->request('admin-1'), ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('paths', $decoded['library']);
    }

    public function testShowRedactsPathsForAuthenticatedNonAdmins(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn($this->libraryRows()[0]);

        $response = $this->controller($libraryManager)->show($this->request('user-1'), ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('paths', $decoded['library']);
        $this->assertSame('lib-1', $decoded['library']['id']);
    }

    /**
     * getAllLibraries() is served from a process-static cache — the redaction
     * must not mutate the cached rows for a later admin request.
     */
    public function testRedactionDoesNotPoisonTheLibraryManagerCacheRow(): void
    {
        $rows = $this->libraryRows();
        $libraryManager = $this->createMock(LibraryManager::class);
        // Return the SAME array instance twice, like the static cache does.
        $libraryManager->method('getAllLibraries')->willReturn($rows);

        $controller = $this->controller($libraryManager);

        $nonAdmin = json_decode((string) $controller->index($this->request('user-1'), [])->body, true);
        $admin = json_decode((string) $controller->index($this->request('admin-1'), [])->body, true);

        $this->assertIsArray($nonAdmin);
        $this->assertIsArray($admin);
        $this->assertArrayNotHasKey('paths', $nonAdmin['libraries'][0]);
        $this->assertArrayHasKey('paths', $admin['libraries'][0]);
    }
}
