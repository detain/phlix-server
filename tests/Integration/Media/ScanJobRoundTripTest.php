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

    /**
     * S96(f): `items_failed` round-trips against the REAL migration-095 column.
     *
     * A mock cannot prove this — the column has to exist, be `INT UNSIGNED NOT NULL
     * DEFAULT 0` (so an untouched row reads 0 rather than NULL), and accept both the
     * throttled progress write and the authoritative final write. Mock-DB tests are
     * exactly what hid this repo's earlier real-SQL defects.
     *
     * It also pins the reason the column exists: before it, a scan that skipped files
     * had NOWHERE to say so. `ScanResult` had no failure field, the job row had no
     * counter, and the scanner's `logger->error` went into the unit's `PrivateTmp`.
     */
    public function testItemsFailedRoundTripsThroughProgressAndCompletion(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ScanJobRepository($this->db);

        $jobId = $repo->enqueue($this->libraryId, 'scan');
        $fresh = $repo->findById($jobId);
        $this->assertIsArray($fresh);
        $this->assertSame(0, $fresh['items_failed'], 'a brand-new job row must read 0, never NULL');

        // Mid-scan: the throttled sink writes the live counters.
        $repo->updateProgress($jobId, [
            'items_found'   => 80,
            'items_updated' => 25,
            'items_added'   => 20,
            'items_failed'  => 1,
        ], '/tmp/phlix-scanjob-test/track.flac');

        $mid = $repo->findById($jobId);
        $this->assertIsArray($mid);
        $this->assertSame(20, $mid['items_added'], 'items_added must be truthful DURING the scan');
        $this->assertSame(1, $mid['items_failed']);

        // Completion: the authoritative final values via markCompleted()'s
        // $finalCounts — a parameter that had no caller at all before S96.
        $repo->markCompleted($jobId, ['items_added' => 77, 'items_failed' => 3, 'items_removed' => 2]);

        $done = $repo->findById($jobId);
        $this->assertIsArray($done);
        $this->assertSame('completed', $done['status']);
        $this->assertSame(77, $done['items_added']);
        $this->assertSame(3, $done['items_failed']);
        $this->assertSame(2, $done['items_removed']);
        $this->assertSame(
            25,
            $done['items_updated'],
            'items_updated is the PROGRESS numerator and must be left exactly as the sink wrote it — '
            . 'writing a semantic "updated items" count here would collapse the UI percentage at completion',
        );
    }

    /**
     * Review r1 LOW-4: a completion stamp must not LOWER a counter the live sink has
     * already written — verified against real MySQL, because `GREATEST()` is SQL a mock
     * cannot evaluate (a `willReturnCallback` fake would happily "pass" with the
     * function deleted).
     *
     * The shape this reproduces is the real one: a `rescan` streams the music scanner's
     * new-TRACK count while it runs, then `markCompleted()` stamps
     * `rescanLibrary()`'s all-types row-count delta — which can be smaller (e.g. a
     * `music_tracks` row added against a pre-existing `media_items` row). Before the
     * clamp, an operator saw `items_added` drop from 20 to 5 at the instant the job
     * completed.
     */
    public function testMarkCompletedNeverLowersACounterTheSinkAlreadyWrote(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ScanJobRepository($this->db);

        $jobId = $repo->enqueue($this->libraryId, 'rescan');
        $repo->updateProgress($jobId, [
            'items_found'   => 40,
            'items_updated' => 40,
            'items_added'   => 20,
            'items_failed'  => 4,
            'items_removed' => 7,
        ]);

        // Every cumulative value here is LOWER than what the sink observed.
        $repo->markCompleted($jobId, [
            'items_added'   => 5,
            'items_failed'  => 1,
            'items_removed' => 2,
        ]);

        $done = $repo->findById($jobId);
        $this->assertIsArray($done);
        $this->assertSame('completed', $done['status']);
        $this->assertSame(20, $done['items_added'], 'items_added must not go 20 -> 5 at completion');
        $this->assertSame(4, $done['items_failed'], 'a file that was observed lost stays lost');
        $this->assertSame(7, $done['items_removed']);

        // And a HIGHER final value still wins — the clamp raises, it does not freeze.
        $repo->markCompleted($jobId, ['items_added' => 99]);
        $raised = $repo->findById($jobId);
        $this->assertIsArray($raised);
        $this->assertSame(99, $raised['items_added']);
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
