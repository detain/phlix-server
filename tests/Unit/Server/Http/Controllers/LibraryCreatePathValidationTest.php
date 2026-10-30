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
 * M-SERVER-41 (review server__webux major 2): "Add library" must not accept a
 * path it cannot scan.
 *
 * Before this fix, {@see LibraryController::create()} validated presence and
 * type but never touched the filesystem: {@see LibraryManager::createLibrary()}
 * inserted the row and the scan job was enqueued blind. The bogus path surfaced
 * only later as a `logger->warning('Scan path does not exist')` inside the
 * worker plus a green-badge, zero-item "completed" scan — after the endpoint
 * had already answered 201 with "Library created; initial scan started".
 *
 * The cure is synchronous path validation at the create choke (the only
 * production caller of createLibrary(), and update() never writes `paths`):
 * every entry of the multi-path field must be a string that exists, is a
 * directory, and is readable — rejected with the canonical
 * `{error, code: 'invalid_request', invalid_paths: [...]}` envelope
 * (`invalid_request` is a member of the vendored @phlix/contracts registry
 * already REST-emitted by QuickConnectController — zero new wire codes), and
 * nothing reaches the database or the job queue until every path passes.
 *
 * This also closes the silent-plural drop hole the review names as the
 * second-order defect: `{"paths":[123]}` used to sail through the
 * `$paths !== []` presence guard and persist a zero-path library because the
 * collection loop skipped non-strings without complaint.
 *
 * The `not_readable` reason arm is not exercised here: `is_readable()` reports
 * true for a chmod-000 directory when the test process runs as root (CI), so a
 * pin on it would be venue-dependent. The arm stays as defense-in-depth for
 * non-root deployments; existence and directory-ness are venue-independent.
 *
 * Pure filesystem unit test: real throwaway dirs under the system temp path,
 * mocked manager (never() = "no DB write" proof), no database.
 */
class LibraryCreatePathValidationTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phlix_m41_' . bin2hex(random_bytes(6));
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
    private function makeController(
        LibraryManager $libraryManager,
        ScanJobRepository $scanJobs
    ): LibraryController {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): array => ['id' => $id, 'is_admin' => 1, 'status' => 'active']
        );

        return new LibraryController(
            $libraryManager,
            $scanJobs,
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );
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

    public function testCreateRejectsNonexistentPathBeforeAnyDbWrite(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');
        $controller = $this->makeController($libraryManager, $scanJobs);

        $bogus = '/phlix-m41-definitely-not-here-' . bin2hex(random_bytes(4));
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => [$bogus],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertEnvelope($body, $bogus, 'does_not_exist');
    }

    public function testCreateRejectsPathThatIsAFileNotADirectory(): void
    {
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');
        $controller = $this->makeController($libraryManager, $scanJobs);

        $file = $this->makeTempFile();
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => [$file],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertEnvelope($body, $file, 'not_a_directory');
    }

    public function testCreateRejectsNonStringPathEntryInsteadOfSilentlyDroppingIt(): void
    {
        // The second-order hole: `{"paths":[123]}` used to pass the presence
        // guard and create a zero-path library (the drop happened before
        // createLibrary saw the list).
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');
        $controller = $this->makeController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => [123],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertSame('invalid_request', $body['code']);
        $this->assertIsArray($body['invalid_paths']);
        $found = false;
        foreach ($body['invalid_paths'] as $entry) {
            if (($entry['reason'] ?? null) === 'not_a_string') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'a non-string path entry must be reported, not dropped');
    }

    public function testCreateRejectsTheWholeRequestWhenAnyPathIsValidButAnotherIsBogus(): void
    {
        // Partial acceptance would silently store a short library; the admin
        // must see every offender in one round-trip.
        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->never())->method('createLibrary');
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->never())->method('enqueue');
        $controller = $this->makeController($libraryManager, $scanJobs);

        $good = $this->makeTempDir();
        $bogus = '/phlix-m41-absent-' . bin2hex(random_bytes(4));
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => [$good, $bogus],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: mixed, code: mixed, invalid_paths: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertEnvelope($body, $bogus, 'does_not_exist');
    }

    public function testCreateStillSucceedsWhenEveryPathIsAnExistingDirectory(): void
    {
        $goodA = $this->makeTempDir();
        $goodB = $this->makeTempDir();

        $libraryManager = $this->createMock(LibraryManager::class);
        $libraryManager->expects($this->once())
            ->method('createLibrary')
            ->with('Movies', 'movie', [$goodA, $goodB], [])
            ->willReturn('new-lib-id');
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->expects($this->once())
            ->method('enqueue')
            ->with('new-lib-id', 'scan')
            ->willReturn('job-1');
        $controller = $this->makeController($libraryManager, $scanJobs);

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'name' => 'Movies',
            'type' => 'movie',
            'paths' => [$goodA, $goodB],
        ];

        $response = $controller->create($request, []);

        $this->assertSame(201, $response->statusCode);
        /** @var array{library_id: mixed, job_id: mixed, status: mixed} $body */
        $body = json_decode((string) $response->body, true);
        $this->assertSame('new-lib-id', $body['library_id']);
        $this->assertSame('job-1', $body['job_id']);
        $this->assertSame('scanning', $body['status']);
    }
}
