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

/**
 * M-SERVER-41 r2 (review server__dba major, riding server__webux major 2):
 * the UPDATE-PUT leg gets the SAME path validation as create — and no more,
 * no less.
 *
 * The r1 fix gated only {@see LibraryController::create()} and claimed
 * "update() never writes `paths`". False: {@see LibraryController::update()}
 * forwards its raw body to {@see LibraryManager::updateLibrary()}, which
 * JSON-persists the `paths` key whenever it is present — a probe PUT
 * `{"paths":["/definitely/not/a/real/dir"]}` at 63ac756b answered 200
 * "Library updated successfully" and forwarded the bogus list VERBATIM into
 * the column. `update()` is the sole production caller of `updateLibrary()`,
 * so these two controller methods are the two HTTP entry points into
 * `libraries.paths`, and both now route their `paths` field through ONE
 * shared helper (`LibraryController::validateLibraryPaths()`), so the
 * validation and the canonical reject envelope exist in exactly one place.
 *
 * Pinned here (mirroring LibraryCreatePathValidationTest's teeth): every
 * bogus entry — nonexistent path, file-not-directory, non-string entry,
 * empty list (the column-zeroing sibling of the silent-drop hole the create
 * cure closed), non-array value — rejects the WHOLE request with the
 * canonical `{error, code: 'invalid_request', invalid_paths: [...]}`
 * envelope (`invalid_request` is a member of the vendored @phlix/contracts
 * registry — zero new wire codes) BEFORE `updateLibrary()` is reached.
 * A valid list still forwards (200, verified content), and a body WITHOUT
 * `paths` is untouched by the gate — the same behavior as before the cure,
 * green both ways as a regression guard.
 *
 * The `not_readable` reason arm is not exercised here either: under root
 * every directory reads (CI), so a pin would be venue-dependent — same
 * honest disclosure as the create file carries.
 *
 * Pure filesystem unit test: real throwaway dirs under the system temp path,
 * mocked manager (never() = "no DB write" proof), no database.
 */
class LibraryUpdatePathValidationTest extends TestCase
{
    /** @var list<string> temp dirs/files created by a single test */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->scratch) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->scratch = [];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_m41b_' . bin2hex(random_bytes(6));
        $this->scratch[] = $dir;
        if (!mkdir($dir, 0o755, true)) {
            $this->fail('could not create scratch directory ' . $dir);
        }

        return $dir;
    }

    private function makeTempFile(): string
    {
        $file = $this->makeTempDir() . '/media.mkv';
        $this->scratch[] = $file;
        if (!file_put_contents($file, 'x')) {
            $this->fail('could not create scratch file ' . $file);
        }

        return $file;
    }

    /** Build a controller whose admin gate ALLOWS, with a real-filesystem world. */
    private function makeController(LibraryManager $libraryManager): LibraryController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): array => ['id' => $id, 'is_admin' => 1, 'status' => 'active']
        );

        return new LibraryController(
            $libraryManager,
            $this->createMock(ScanJobRepository::class),
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );
    }

    /** Manager mock whose getLibrary answers and whose updateLibrary must NEVER run. */
    private function makeNeverWritingManager(): LibraryManager
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/srv/media/movies'],
            'options' => [],
        ]);
        $libraryManager->expects($this->never())->method('updateLibrary');

        return $libraryManager;
    }

    /** @param array{error: mixed, code: mixed, invalid_paths: mixed} $body */
    private function assertEnvelope(array $body, string $expectedPath, string $expectedReason): void
    {
        $this->assertSame(
            'invalid_request',
            $body['code'],
            'the rejection must carry the already-registered invalid_request wire code'
        );
        $this->assertIsString($body['error']);
        $this->assertNotSame('', $body['error'], 'the envelope must be human-readable too');
        $this->assertIsArray($body['invalid_paths']);
        $match = null;
        foreach ($body['invalid_paths'] as $entry) {
            $this->assertIsArray($entry);
            if (($entry['path'] ?? null) === $expectedPath) {
                $match = $entry;
            }
        }
        $this->assertNotNull($match, "invalid_paths must name the offending entry '{$expectedPath}'");
        $this->assertSame($expectedReason, $match['reason'] ?? null);
    }

    /** Reason-only envelope assertion for null-path arms (non-list shapes). */
    private function assertReasonNamed(array $body, string $expectedReason): void
    {
        $this->assertSame('invalid_request', $body['code']);
        $this->assertIsArray($body['invalid_paths']);
        $found = false;
        foreach ($body['invalid_paths'] as $entry) {
            if (($entry['reason'] ?? null) === $expectedReason) {
                $found = true;
            }
        }
        $this->assertTrue($found, "invalid_paths must name the '{$expectedReason}' arm");
    }

    public function testUpdateRejectsNonexistentPathBeforeAnyDbWrite(): void
    {
        $controller = $this->makeController($this->makeNeverWritingManager());

        $bogus = '/phlix-m41b-definitely-not-here-' . bin2hex(random_bytes(4));
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['paths' => [$bogus]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertEnvelope($body, $bogus, 'does_not_exist');
    }

    public function testUpdateRejectsPathThatIsAFileNotADirectory(): void
    {
        $controller = $this->makeController($this->makeNeverWritingManager());

        $file = $this->makeTempFile();
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['paths' => [$file]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertEnvelope($body, $file, 'not_a_directory');
    }

    public function testUpdateRejectsNonStringPathEntryInsteadOfPersistingIt(): void
    {
        // The update-leg sibling of the create silent-drop hole: `{"paths":[123]}`
        // used to sail into json_encode() and persist a garbage entry.
        $controller = $this->makeController($this->makeNeverWritingManager());

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['paths' => [123]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertReasonNamed($body, 'not_a_string');
    }

    public function testUpdateRejectsTheWholeRequestWhenAnyPathIsValidButAnotherIsBogus(): void
    {
        // Partial acceptance would silently shorten the library; the admin
        // must see every offender in one round-trip — same law as create.
        $controller = $this->makeController($this->makeNeverWritingManager());

        $good = $this->makeTempDir();
        $bogus = '/phlix-m41b-absent-' . bin2hex(random_bytes(4));
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['paths' => [$good, $bogus]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertEnvelope($body, $bogus, 'does_not_exist');
    }

    public function testUpdateRejectsEmptyPathListInsteadOfZeroingTheColumn(): void
    {
        // `{"paths":[]}` would json_encode '[]' over a populated column — the
        // zero-path library the create cure refused to persist.
        $controller = $this->makeController($this->makeNeverWritingManager());

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['paths' => []];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertReasonNamed($body, 'empty_list');
    }

    public function testUpdateRejectsNonArrayPathListInsteadOfPersistingAScalar(): void
    {
        // `{"paths":"/srv/media"}` (a bare string, not a list) would persist a
        // JSON SCALAR where every reader expects a list.
        $controller = $this->makeController($this->makeNeverWritingManager());

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['paths' => '/srv/media'];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertReasonNamed($body, 'not_a_list');
    }

    public function testUpdateAcceptsValidPathsAndForwardsTheValidatedList(): void
    {
        $dirA = $this->makeTempDir();
        $dirB = $this->makeTempDir();

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/old/path'],
            'options' => [],
        ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', ['paths' => [$dirA, $dirB]]);
        $controller = $this->makeController($libraryManager);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['paths' => [$dirA, $dirB]];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->body, true);
        $this->assertSame('Library updated successfully', $body['message'] ?? null);
    }

    public function testUpdateWithoutPathsKeyIsUntouchedByTheGate(): void
    {
        // A name-only PUT must behave EXACTLY as before the cure — green both
        // sides, the regression guard for "paths: null/absent keeps the column
        // untouched" (isset() mirrors the manager's own persist condition).
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->method('getLibrary')->willReturn([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => ['/srv/media/movies'],
            'options' => [],
        ]);
        $libraryManager->expects($this->once())
            ->method('updateLibrary')
            ->with('lib-1', ['name' => 'Renamed']);
        $controller = $this->makeController($libraryManager);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['name' => 'Renamed'];

        $response = $controller->update($request, ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->body, true);
        $this->assertSame('Library updated successfully', $body['message'] ?? null);
    }
}
