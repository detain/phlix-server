<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Library\ScanJobRepository;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB round-trip for the library scan-job store (Step 1.1a).
 *
 * Drives a live {@see Connection} through the full job lifecycle —
 * enqueue → claimNext → updateProgress → markCompleted → history — asserting
 * the row transitions at each step. This exercises the REAL migration 027
 * schema (the `library_scan_jobs` table, its FK to `libraries`, and the
 * `LIMIT ?` bound history query) rather than a hand-rolled simulation.
 *
 * The CI PHPUnit job applies all migrations to the `phlix_test` MySQL service
 * before the suite runs (see `.github/workflows/phpunit.yml`), so the schema
 * exists here. Locally — where no MySQL is reachable — the test self-skips (the
 * Workerman {@see Connection} connects in its constructor, so there is nothing
 * to test without a server); the unit test
 * {@see \Phlix\Tests\Unit\Media\Library\ScanJobRepositoryTest} covers every
 * method with a mocked connection regardless.
 *
 * @covers \Phlix\Media\Library\ScanJobRepository
 */
final class ScanJobRoundTripTest extends TestCase
{
    private ?Connection $db = null;

    /** @var string UUID of the parent library row created for the FK. */
    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping scan-job round-trip. Runs in CI / docker-compose.', $host, $port),
            );
        }

        try {
            // Resolve the SAME patched connection production uses
            // (PhlixMySQLConnection re-keys positional params 1-indexed for
            // PDO::bindParam — the raw workerman/mysql Connection trips
            // "bindParam(): Argument #1 must be >= 1" on PHP 8.x). Creds come
            // from config/database.php, which reads the DB_* env phpunit.xml sets.
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        // A scan job FK-references libraries(id); create a disposable parent
        // row in the migration-created (empty) `libraries` table.
        $this->libraryId = $this->uuid();
        $this->db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'ScanJob RoundTrip Lib', 'movie', json_encode(['/tmp/phlix-scanjob-test'])],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE removes the job rows with the parent library.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    public function testEnqueueClaimUpdateCompleteRoundTrip(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ScanJobRepository($this->db);

        // enqueue -> a queued row.
        $jobId = $repo->enqueue($this->libraryId, 'scan');
        $this->assertNotSame('', $jobId);

        $queued = $repo->findById($jobId);
        $this->assertIsArray($queued);
        $this->assertSame('queued', $queued['status']);
        $this->assertSame('scan', $queued['type']);
        $this->assertNull($queued['started_at']);

        // It is the latest job for the library.
        $latest = $repo->getLatestForLibrary($this->libraryId);
        $this->assertIsArray($latest);
        $this->assertSame($jobId, $latest['id']);

        // claimNext -> moves it to running and stamps started_at.
        $claimed = $repo->claimNext();
        $this->assertIsArray($claimed);
        $this->assertSame($jobId, $claimed['id']);
        $this->assertSame('running', $claimed['status']);
        $this->assertNotNull($claimed['started_at']);

        // A second claim finds nothing queued (the only job is running now).
        $this->assertNull($repo->claimNext());

        // updateProgress -> counters + current_path written.
        $repo->updateProgress($jobId, [
            'items_found'   => 5,
            'items_added'   => 3,
            'items_updated' => 1,
        ], '/tmp/phlix-scanjob-test/movie.mkv');

        $progressed = $repo->findById($jobId);
        $this->assertIsArray($progressed);
        $this->assertSame(5, $progressed['items_found']);
        $this->assertSame(3, $progressed['items_added']);
        $this->assertSame(1, $progressed['items_updated']);
        $this->assertSame('/tmp/phlix-scanjob-test/movie.mkv', $progressed['current_path']);
        $this->assertSame('running', $progressed['status']);

        // markCompleted -> completed + completed_at + final counters.
        $repo->markCompleted($jobId, ['items_found' => 6]);

        $completed = $repo->findById($jobId);
        $this->assertIsArray($completed);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame(6, $completed['items_found']);
        $this->assertNotNull($completed['completed_at']);

        // History returns the job for the library (LIMIT ? bound query).
        $history = $repo->getHistoryForLibrary($this->libraryId, 10);
        $this->assertNotEmpty($history);
        $this->assertSame($jobId, $history[0]['id']);
    }

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
