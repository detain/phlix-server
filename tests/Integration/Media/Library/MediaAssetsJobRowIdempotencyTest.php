<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Library;

use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S284 — "running it a second time does not duplicate queue rows", asserted as a
 * ROW COUNT against a real MySQL.
 *
 * ## Why this needs a real database
 *
 * The de-duplication is a single `INSERT ... SELECT ... WHERE NOT EXISTS`
 * statement. A mocked {@see Connection} can only report the SQL the test itself
 * expected, so it cannot tell a working guard from a broken one — the predicate
 * has to be evaluated by MySQL. This also proves migration 101 applied: an
 * install without it rejects `type = 'media_assets'` outright, which is a failure
 * mode a mock would never surface.
 *
 * ## The controls
 *
 * Three of them, because "the count was 1" is easy to fake:
 *
 *  - a `scan` job enqueued twice through the PLAIN `enqueue()` really does leave
 *    TWO rows, so the counter is demonstrably able to count to two and the
 *    de-duplication is not an artefact of a table that refuses inserts;
 *  - a `media_assets` job for a DIFFERENT library is not swallowed, so the guard
 *    is not "refuse everything after the first";
 *  - once the first job reaches a terminal state a new one IS created, so the
 *    endpoint does not become permanently dead after one use.
 */
final class MediaAssetsJobRowIdempotencyTest extends TestCase
{
    use RequiresRealDatabase;

    private Connection $db;

    /** @var list<string> Library ids to clean up. */
    private array $libraryIds = [];

    protected function setUp(): void
    {
        $this->db = $this->requireRealDatabase('skipping the media-asset job-row tests. Runs in CI.');

        try {
            $this->db->query('SELECT 1 FROM library_scan_jobs LIMIT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('migration 027 (library_scan_jobs) not applied on this box');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->libraryIds as $libraryId) {
            // ON DELETE CASCADE takes the jobs with it.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$libraryId]);
        }
        $this->libraryIds = [];
    }

    private function makeLibrary(string $suffix): string
    {
        $id = sprintf('s284-%s-%s', substr(md5(uniqid('', true)), 0, 12), $suffix);
        $id = substr($id, 0, 36);
        $this->db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$id, 'S284 fixture ' . $suffix, 'movie', json_encode(['/tmp/s284'])],
        );
        $this->libraryIds[] = $id;

        return $id;
    }

    private function countJobs(string $libraryId, string $type): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM library_scan_jobs WHERE library_id = ? AND type = ?',
            [$libraryId, $type],
        );
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return -1;
        }
        $n = $rows[0]['n'] ?? null;

        return is_numeric($n) ? (int) $n : -1;
    }

    /**
     * A LibraryController wired the way `Application::getLibraryController()`
     * wires it — real {@see AdminMiddleware}, real {@see ScanJobRepository} over
     * the real connection — so the request path under test is the served one.
     */
    private function makeAdminController(string $libraryId): LibraryController
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getLibrary')->willReturn(['id' => $libraryId, 'name' => 'S284']);

        // S282: the middleware moved from an optional setter to a REQUIRED
        // constructor parameter; the wiring proved here is unchanged.
        return new LibraryController(
            $libraries,
            new ScanJobRepository($this->db),
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );
    }

    private function adminRequest(): Request
    {
        $request = new Request();
        $request->userId = 'admin-1';

        return $request;
    }

    public function testTwoRegenerateRequestsLeaveExactlyOneMediaAssetsRow(): void
    {
        $libraryId = $this->makeLibrary('dedupe');
        $controller = $this->makeAdminController($libraryId);

        $this->assertSame(0, $this->countJobs($libraryId, 'media_assets'), 'must start with no rows');

        $first = $controller->regenerateAssets($this->adminRequest(), ['id' => $libraryId]);
        $this->assertSame(202, $first->statusCode);
        $this->assertSame(1, $this->countJobs($libraryId, 'media_assets'));

        $second = $controller->regenerateAssets($this->adminRequest(), ['id' => $libraryId]);
        $this->assertSame(202, $second->statusCode);

        $this->assertSame(
            1,
            $this->countJobs($libraryId, 'media_assets'),
            'a second re-enqueue must not duplicate the queue row'
        );

        /** @var array<string, mixed> $body */
        $body = json_decode($second->body, true);
        $this->assertSame('already_queued', $body['status'] ?? null);

        // And it names the job that IS doing the work, not an id nothing holds.
        $rows = $this->db->query(
            'SELECT id FROM library_scan_jobs WHERE library_id = ? AND type = ?',
            [$libraryId, 'media_assets'],
        );
        $this->assertIsArray($rows);
        $this->assertSame($rows[0]['id'] ?? null, $body['job_id'] ?? null);
    }

    /**
     * CONTROL 1 — the counter can count to two.
     *
     * The plain `enqueue()` every sibling action uses inserts unconditionally, so
     * two `scan` requests really do leave two rows. Without this beside the test
     * above, "the count stayed 1" would also be the reading on a table that
     * silently rejected the second insert.
     */
    public function testThePlainEnqueueStillCreatesASecondRowForTheSameLibrary(): void
    {
        $libraryId = $this->makeLibrary('control');
        $jobs = new ScanJobRepository($this->db);

        $jobs->enqueue($libraryId, 'scan');
        $jobs->enqueue($libraryId, 'scan');

        $this->assertSame(2, $this->countJobs($libraryId, 'scan'));
        $this->assertSame(0, $this->countJobs($libraryId, 'media_assets'));
    }

    /**
     * CONTROL 2 — the guard is scoped to ONE library, not global.
     */
    public function testADifferentLibraryIsNotSwallowedByTheGuard(): void
    {
        $libraryA = $this->makeLibrary('a');
        $libraryB = $this->makeLibrary('b');
        $jobs = new ScanJobRepository($this->db);

        $this->assertTrue($jobs->enqueueIfNoneActiveOfType($libraryA, 'media_assets')['created']);
        $this->assertTrue($jobs->enqueueIfNoneActiveOfType($libraryB, 'media_assets')['created']);

        $this->assertSame(1, $this->countJobs($libraryA, 'media_assets'));
        $this->assertSame(1, $this->countJobs($libraryB, 'media_assets'));
    }

    /**
     * CONTROL 3 — the guard is scoped to ACTIVE jobs, so the endpoint does not
     * become permanently unusable after its first successful run.
     */
    public function testAFinishedJobDoesNotBlockTheNextOne(): void
    {
        $libraryId = $this->makeLibrary('rerun');
        $jobs = new ScanJobRepository($this->db);

        $first = $jobs->enqueueIfNoneActiveOfType($libraryId, 'media_assets');
        $this->assertTrue($first['created']);
        $jobs->markCompleted($first['job_id']);

        $second = $jobs->enqueueIfNoneActiveOfType($libraryId, 'media_assets');

        $this->assertTrue($second['created'], 'a completed job must not block the next request');
        $this->assertNotSame($first['job_id'], $second['job_id']);
        $this->assertSame(2, $this->countJobs($libraryId, 'media_assets'));
    }

    /**
     * A `running` job DOES block, which is the case that matters operationally —
     * the first request's job is claimed almost immediately by the worker.
     */
    public function testARunningJobBlocksASecondRequest(): void
    {
        $libraryId = $this->makeLibrary('running');
        $jobs = new ScanJobRepository($this->db);

        $first = $jobs->enqueueIfNoneActiveOfType($libraryId, 'media_assets');
        $this->db->query(
            "UPDATE library_scan_jobs SET status = 'running', started_at = NOW() WHERE id = ?",
            [$first['job_id']],
        );

        $second = $jobs->enqueueIfNoneActiveOfType($libraryId, 'media_assets');

        $this->assertFalse($second['created']);
        $this->assertSame($first['job_id'], $second['job_id']);
        $this->assertSame(1, $this->countJobs($libraryId, 'media_assets'));
    }

    /**
     * An unrelated ACTIVE job of a different type must not swallow the request —
     * a type-blind guard would refuse a backfill for hours while a metadata match
     * ran.
     */
    public function testAnActiveScanDoesNotBlockAMediaAssetsRequest(): void
    {
        $libraryId = $this->makeLibrary('mixed');
        $jobs = new ScanJobRepository($this->db);

        $jobs->enqueue($libraryId, 'scan');

        $outcome = $jobs->enqueueIfNoneActiveOfType($libraryId, 'media_assets');

        $this->assertTrue($outcome['created']);
        $this->assertSame(1, $this->countJobs($libraryId, 'media_assets'));
        $this->assertSame(1, $this->countJobs($libraryId, 'scan'));
    }

    /**
     * Migration 101 applied: the ENUM admits the new value. Asserted by reading
     * the stored value back, so a column that silently coerced to `''` (MySQL's
     * non-strict ENUM behaviour) reds instead of passing.
     */
    public function testTheStoredTypeIsReallyMediaAssets(): void
    {
        $libraryId = $this->makeLibrary('enum');
        $jobs = new ScanJobRepository($this->db);

        $outcome = $jobs->enqueueIfNoneActiveOfType($libraryId, 'media_assets');
        $row = $jobs->findById($outcome['job_id']);

        $this->assertIsArray($row);
        $this->assertSame('media_assets', $row['type'] ?? null);
        $this->assertSame('queued', $row['status'] ?? null);
    }
}
