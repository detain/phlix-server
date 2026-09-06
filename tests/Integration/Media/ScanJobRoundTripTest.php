<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Uuid;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
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
 */
final class ScanJobRoundTripTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    /** @var string UUID of the parent library row created for the FK. */
    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping scan-job round-trip. Runs in CI / docker-compose.');

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
     * The shape this reproduces is the real one: a `rescan` streams a LOWER BOUND on
     * "media_items rows this job created" (music streams new TRACK rows, not the
     * artist/album containers) and `markCompleted()` then stamps the exact all-types
     * row-count delta. Before the clamp, an operator saw `items_added` drop from 20 to 5
     * at the instant the job completed.
     *
     * Review r2 F5 narrowed the clamp to `items_added` + `items_failed`, so this test also
     * pins the EXCLUSION of `items_removed` — deliberately, because that behaviour is
     * only safe given a fact about the callers rather than about this method: no code path
     * ever writes `items_removed` live and then stamps a different value at completion
     * (`prune`/`delete_all` write it through `updateProgress()` and reach
     * `markCompleted()` with an empty `$finalCounts`; `rescan`'s live sink never writes
     * the key). If that ever changes, this assertion is the one that has to be revisited.
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

        // Every value here is LOWER than what the sink observed.
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
        $this->assertSame(
            2,
            $done['items_removed'],
            'items_removed is NOT clamped (r2 F5): it is written verbatim. Reaching this state needs a '
            . 'live write followed by a lower final stamp, which no job type does — prune/delete_all '
            . 'stamp no final counters at all and rescan never streams this key',
        );

        // And a HIGHER final value still wins — the clamp raises, it does not freeze.
        $repo->markCompleted($jobId, ['items_added' => 99]);
        $raised = $repo->findById($jobId);
        $this->assertIsArray($raised);
        $this->assertSame(99, $raised['items_added']);
    }

    /**
     * S443 — delegates to the CSPRNG-backed {@see Uuid::v4()}. This copy used
     * to re-implement the old `sprintf(mt_rand(...))` format LOCALLY, which
     * kept its minted CHAR(36) primary keys steerable by PHPUnit's
     * `mt_srand(randomOrderSeed)` (and by mid-run re-pins): two same-seed runs
     * replayed identical ids and collided on `Duplicate entry ... for key
     * 'PRIMARY'` — the Music 1062 class S111/S334 removed from the fixture
     * side and S443 removes from the source. The local copy is now the single
     * shared generator, which no order seed can steer.
     */
    private function uuid(): string
    {
        return Uuid::v4();
    }
}
