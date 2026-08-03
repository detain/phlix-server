<?php

/**
 * Phlix media server tests: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\StreamProbeBackfill;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * The TWO stream-replacement call sites, end to end against a transaction-aware
 * connection double: the lazy probe backfill
 * ({@see StreamProbeBackfill::ensureFor()} / `ensureVideoCodecFor()`) and the
 * scanner ({@see MediaScanner::persistStreams()}, reached through the public
 * `backfillItemSourceMetadata()`).
 *
 * Two properties are pinned that the per-class mock-based tests structurally
 * cannot see, because they are about the transaction BOUNDARY:
 *
 *  - the blocking ffprobe stays OUTSIDE the transaction. It costs ~1s on the
 *    sshfs-mounted shares this server reads, and on the unpooled connection a
 *    transaction holds a whole-transaction coroutine mutex — so probing inside
 *    one would stall every other coroutine's DB work for that entire second.
 *    Asserted as a position in the SAME ordered op log as the SQL, which is the
 *    only way to state "not inside".
 *  - a mid-replacement write failure leaves the item's PREVIOUS rows in place
 *    (the scanner's `false` return now means "nothing changed", not
 *    "half-written and unrepairable").
 */
class StreamReplacementAtomicityTest extends TestCase
{
    /** @var list<string> Temp files unlinked in tearDown. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    /** Real (empty) file on disk so the backfill's is_file() gate passes. */
    private function makeTempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-atomic-');
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array<string, mixed> One video + one audio stream. */
    private function probe(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '5000000'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac',
                 'channels' => 2, 'bit_rate' => '128000'],
            ],
            'format' => ['duration' => '600.0', 'bit_rate' => '5200000'],
        ];
    }

    /**
     * An FfmpegRunner whose probe() records itself in the connection's op log, so
     * its position relative to `begin`/`commit` is assertable.
     *
     * @param array<string, mixed>|null $result Probe payload (defaults to the
     *                                          one video + one audio result).
     */
    private function recordingProbe(TransactionalStreamsConnection $db, ?array $result = null): FfmpegRunner
    {
        $payload = $result ?? $this->probe();
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(function () use ($db, $payload): array {
            $db->note('probe');
            return $payload;
        });
        return $ffmpeg;
    }

    /**
     * Op log with the generated row ids stripped, e.g.
     * `['probe', 'begin', 'delete', 'insert', 'insert', 'commit']`.
     *
     * @return list<string>
     */
    private function shape(TransactionalStreamsConnection $db): array
    {
        return array_map(
            static fn (string $op): string => explode(':', $op)[0],
            $db->ops
        );
    }

    /**
     * Criterion 3, backfill path: the ffprobe completes BEFORE `beginTrans()`,
     * and the transaction spans only the delete + inserts.
     */
    public function testBackfillProbesBeforeOpeningTheTransaction(): void
    {
        $db = new TransactionalStreamsConnection();
        $db->seed(['id' => 'old-v', 'media_item_id' => 'movie-1', 'stream_index' => 0,
                   'stream_type' => 'video', 'codec' => 'h264']);
        $db->seed(['id' => 'old-a', 'media_item_id' => 'movie-1', 'stream_index' => 1,
                   'stream_type' => 'audio', 'codec' => 'ac3']);
        $path = $this->makeTempFile();

        $repo = new ItemRepository($db);
        $backfill = new StreamProbeBackfill($repo, $this->recordingProbe($db), new NullLogger());

        $result = $backfill->ensureFor(
            ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null],
            [['id' => 'old-v', 'stream_type' => 'video', 'stream_index' => 0, 'codec' => 'h264'],
             ['id' => 'old-a', 'stream_type' => 'audio', 'stream_index' => 1, 'codec' => 'ac3']]
        );

        // probe FIRST, then one transaction around delete+inserts, then the
        // marker stamp and the post-write re-read (both outside it).
        $this->assertSame(
            ['probe', 'begin', 'delete', 'insert', 'insert', 'commit', 'mark', 'select'],
            $this->shape($db)
        );

        $probeAt = array_search('probe', $this->shape($db), true);
        $beginAt = array_search('begin', $this->shape($db), true);
        $this->assertIsInt($probeAt);
        $this->assertIsInt($beginAt);
        $this->assertLessThan($beginAt, $probeAt, 'the ~1s blocking ffprobe must not be held inside the transaction');

        // And the replacement really happened (fresh rows returned to the caller).
        $this->assertSame(
            ['hevc', 'aac'],
            array_map(fn (array $row) => $row['codec'], $result)
        );
    }

    /**
     * Backfill path, criterion 2: a mid-replacement insert failure leaves the
     * item's stored rows intact and the caller degrades to them — rather than
     * serving (and persisting) an empty or partial set.
     *
     * ALSO criterion 4: for a TRANSIENT failure the item is left UNSTAMPED. The
     * rollback is what makes this load-bearing — it guarantees the item still
     * holds exactly the rows that triggered the repair, so stamping
     * `streams_probed_at` here would make it permanently unrepairable (both entry
     * points skip a stamped item, and no rescan or backfill CLI rebuilds these
     * rows). Contrast BOTH
     * {@see testProbeFailureStillStampsSoABrokenFileCannotHotLoop()} and
     * {@see testBackfillDeterministicInsertFailureIsStampedToBoundTheProbe()}.
     *
     * The injected failure is a deadlock, i.e. the shape that really can succeed
     * on the next request; the default `PDOException` the double raises carries no
     * MySQL error at all and is therefore classified deterministic.
     */
    public function testBackfillTransientInsertFailureLeavesTheStoredRowsIntactAndUnstamped(): void
    {
        $db = new TransactionalStreamsConnection();
        $db->seed(['id' => 'old-v', 'media_item_id' => 'movie-1', 'stream_index' => 0,
                   'stream_type' => 'video', 'codec' => 'h264']);
        $db->seed(['id' => 'old-a', 'media_item_id' => 'movie-1', 'stream_index' => 1,
                   'stream_type' => 'audio', 'codec' => 'ac3']);
        $db->failOnInsert = 2; // audio insert fails after the video insert
        $db->insertFailure = new \PDOException(
            'SQL:INSERT INTO media_streams … SQLSTATE[40001]: Serialization failure: '
            . '1213 Deadlock found when trying to get lock; try restarting transaction',
            40001
        );
        $path = $this->makeTempFile();

        $stored = [
            ['id' => 'old-v', 'stream_type' => 'video', 'stream_index' => 0, 'codec' => 'h264'],
            ['id' => 'old-a', 'stream_type' => 'audio', 'stream_index' => 1, 'codec' => 'ac3'],
        ];

        $backfill = new StreamProbeBackfill(
            new ItemRepository($db),
            $this->recordingProbe($db),
            new NullLogger()
        );
        $result = $backfill->ensureFor(
            ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null],
            $stored
        );

        $this->assertSame($stored, $result, 'degrades to the stored rows, as before the fix');
        $this->assertSame(
            ['probe', 'begin', 'delete', 'insert', 'insert-failed', 'rollback'],
            $this->shape($db),
            'rolled back (no commit) and NOT stamped — a rolled-back write must stay retryable'
        );
        $this->assertNotContains('mark', $this->shape($db), 'streams_probed_at must not be stamped');
        $this->assertSame(
            ['old-v', 'old-a'],
            array_map(fn (array $row) => $row['id'], $db->rowsFor('movie-1')),
            'the item still has its two previous rows — not zero, not one'
        );
    }

    /**
     * The write failure that must NOT be retried: one whose MySQL error says the
     * same rows would fail identically for ever (here 1406, `title` too long for
     * the column). The transaction still rolls back and the item still keeps its
     * previous rows — but it IS stamped, because without the stamp the guard
     * chain is unchanged and EVERY later request re-runs the ~1s blocking ffprobe
     * inside a resident single-threaded worker, for a write that can never
     * succeed.
     *
     * Paired with
     * {@see testBackfillTransientInsertFailureLeavesTheStoredRowsIntactAndUnstamped()}:
     * the two differ ONLY in the MySQL error carried by the failure, so together
     * they pin that the classification — not the call site — decides the stamp.
     */
    public function testBackfillDeterministicInsertFailureIsStampedToBoundTheProbe(): void
    {
        $db = new TransactionalStreamsConnection();
        $db->seed(['id' => 'old-v', 'media_item_id' => 'movie-1', 'stream_index' => 0,
                   'stream_type' => 'video', 'codec' => 'h264']);
        $db->seed(['id' => 'old-a', 'media_item_id' => 'movie-1', 'stream_index' => 1,
                   'stream_type' => 'audio', 'codec' => 'ac3']);
        $db->failOnInsert = 2;
        $db->insertFailure = new \PDOException(
            "SQL:INSERT INTO media_streams … SQLSTATE[22001]: String data, right truncated: "
            . "1406 Data too long for column 'title' at row 1",
            22001
        );
        $path = $this->makeTempFile();

        $stored = [
            ['id' => 'old-v', 'stream_type' => 'video', 'stream_index' => 0, 'codec' => 'h264'],
            ['id' => 'old-a', 'stream_type' => 'audio', 'stream_index' => 1, 'codec' => 'ac3'],
        ];

        $backfill = new StreamProbeBackfill(
            new ItemRepository($db),
            $this->recordingProbe($db),
            new NullLogger()
        );
        $result = $backfill->ensureFor(
            ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null],
            $stored
        );

        $this->assertSame($stored, $result, 'still degrades to the stored rows');
        $this->assertSame(
            ['probe', 'begin', 'delete', 'insert', 'insert-failed', 'rollback', 'mark'],
            $this->shape($db),
            'rolled back AND stamped — the retry is bounded because it can never succeed'
        );
        $this->assertSame(
            ['old-v', 'old-a'],
            array_map(fn (array $row) => $row['id'], $db->rowsFor('movie-1')),
            'the rollback still restored both previous rows'
        );
    }

    /**
     * The OTHER failure mode, kept deliberately different: when the ffprobe itself
     * fails on a file that IS on disk, the item IS stamped, so an unreadable or
     * corrupt file cannot re-run the ~1s blocking probe on every single request.
     *
     * Paired with
     * {@see testBackfillTransientInsertFailureLeavesTheStoredRowsIntactAndUnstamped()}:
     * together they pin that `probeAndReplace()` distinguishes a failed PROBE from
     * a failed WRITE, which one `catch` around both cannot.
     */
    public function testProbeFailureStillStampsSoABrokenFileCannotHotLoop(): void
    {
        $db = new TransactionalStreamsConnection();
        $db->seed(['id' => 'old-v', 'media_item_id' => 'movie-1', 'stream_index' => 0,
                   'stream_type' => 'video', 'codec' => null]);
        $path = $this->makeTempFile();

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willReturnCallback(
            function () use ($db): ?array {
                $db->note('probe');
                return null; // ffprobe ran on a present file and failed
            }
        );

        $stored = [['id' => 'old-v', 'stream_type' => 'video', 'stream_index' => 0, 'codec' => null]];
        $backfill = new StreamProbeBackfill(new ItemRepository($db), $ffmpeg, new NullLogger());

        $result = $backfill->ensureVideoCodecFor(
            ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null],
            $stored
        );

        $this->assertSame($stored, $result, 'degrades to the stored rows');
        $this->assertSame(
            ['probe', 'mark'],
            $this->shape($db),
            'no transaction opened, and the item IS stamped so the probe cannot hot-loop'
        );
    }

    /**
     * The scanner's own empty-set guard, which this branch moved the neighbouring
     * writes around: a probe that yields NO stream rows must be a complete no-op —
     * no transaction, and crucially NO `streams_probed_at` stamp. Stamping there
     * would mask the item from {@see StreamProbeBackfill} forever on the strength
     * of a probe that produced nothing.
     *
     * `replaceStreams()` self-guards an empty set, so without the scanner's guard
     * the flow reaches `markStreamsProbed()` and the item is silently stamped —
     * which is exactly what this pins.
     */
    public function testScannerNeverStampsAnItemWhoseProbeYieldedNoStreams(): void
    {
        $db = new TransactionalStreamsConnection();
        $db->seed(['id' => 'old-v', 'media_item_id' => 'movie-1', 'stream_index' => 0,
                   'stream_type' => 'video', 'codec' => 'h264']);
        // Probe succeeds but exposes neither a video nor an audio stream, so
        // summarizeProbe() returns an EMPTY stream set (and a null `source`).
        $scanner = $this->makeScanner($db, ['streams' => [], 'format' => ['duration' => '600.0']]);
        $db->ops = [];

        $status = $scanner->backfillItemSourceMetadata([
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => ['duration_seconds' => 8880], // has duration, lacks source
        ]);

        $this->assertSame('skipped', $status);
        $this->assertSame(
            ['probe'],
            $this->shape($db),
            'the probe ran and NOTHING was written — no begin, no delete, and no mark'
        );
        $this->assertSame(
            ['old-v'],
            array_map(fn (array $row) => $row['id'], $db->rowsFor('movie-1')),
            'the item keeps its existing row: an empty probe result never wipes good rows'
        );
    }

    /**
     * Criterion 3, scanner path: `backfillItemSourceMetadata()` probes first and
     * only then opens the transaction around the replacement.
     */
    public function testScannerProbesBeforeOpeningTheTransaction(): void
    {
        $db = new TransactionalStreamsConnection();
        $scanner = $this->makeScanner($db);
        $db->ops = []; // ignore anything the constructor touched

        $status = $scanner->backfillItemSourceMetadata([
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            // Has a duration and a source blob already, so no metadata_json
            // UPDATE follows the stream replacement and the log stays focused.
            'metadata' => [
                'duration_seconds' => 8880,
                'source' => ['width' => 1920, 'height' => 1080, 'video_codec' => 'h264'],
            ],
        ]);

        $this->assertSame('skipped', $status, 'fully-populated items are skipped WITHOUT probing');
        $this->assertSame([], $db->ops, 'and without any write');

        // Now the same item lacking `source`: probe → transaction → commit.
        $status = $scanner->backfillItemSourceMetadata([
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => ['duration_seconds' => 8880], // has duration, lacks source
        ]);

        $this->assertSame('updated', $status);
        $shape = $this->shape($db);
        $probeAt = array_search('probe', $shape, true);
        $beginAt = array_search('begin', $shape, true);
        $commitAt = array_search('commit', $shape, true);
        $this->assertIsInt($probeAt);
        $this->assertIsInt($beginAt);
        $this->assertIsInt($commitAt);
        $this->assertLessThan($beginAt, $probeAt, 'the scan probe is outside the transaction too');
        $this->assertSame(
            ['probe', 'begin', 'delete', 'insert', 'insert', 'commit'],
            array_slice($shape, 0, 6),
            'one transaction around the delete and both inserts'
        );
    }

    /**
     * Scanner path, criterion 2 — the worst blast radius of the old code: a
     * mid-loop insert failure used to leave EVERY rescanned item permanently
     * partial. It now returns 'failed' (unchanged contract) with the item's
     * previous rows restored.
     */
    public function testScannerStreamFailureRollsBackAndKeepsThePreviousRows(): void
    {
        $db = new TransactionalStreamsConnection();
        $db->seed(['id' => 'old-v', 'media_item_id' => 'movie-1', 'stream_index' => 0,
                   'stream_type' => 'video', 'codec' => 'h264']);
        $db->seed(['id' => 'old-a', 'media_item_id' => 'movie-1', 'stream_index' => 1,
                   'stream_type' => 'audio', 'codec' => 'ac3']);
        $scanner = $this->makeScanner($db);
        $db->ops = [];
        $db->failOnInsert = 2;

        $status = $scanner->backfillItemSourceMetadata([
            'id' => 'movie-1',
            'type' => 'movie',
            'path' => '/x/Movie.mkv',
            'metadata' => ['duration_seconds' => 8880],
        ]);

        $this->assertSame('failed', $status, 'the repairable-failure contract is unchanged');
        $this->assertSame(
            ['probe', 'begin', 'delete', 'insert', 'insert-failed', 'rollback'],
            $this->shape($db),
            'no commit, and no metadata_json write after the failure'
        );
        $this->assertSame(
            ['old-v', 'old-a'],
            array_map(fn (array $row) => $row['id'], $db->rowsFor('movie-1')),
            'the item keeps both previous rows instead of being stranded partial'
        );
    }

    /**
     * Real scanner over the transaction-aware connection double. `db` and the
     * repository share the SAME connection, exactly as the container wires them.
     *
     * @param array<string, mixed>|null $probeResult Probe payload (defaults to the
     *                                              one video + one audio result).
     */
    private function makeScanner(TransactionalStreamsConnection $db, ?array $probeResult = null): MediaScanner
    {
        $ffmpeg = $this->recordingProbe($db, $probeResult);

        // S128: no @var round-trip needed — TransactionalStreamsConnection EXTENDS
        // Workerman\MySQL\Connection (see tests/Unit/Media/Library/…Connection.php:81),
        // so this already satisfies the constructor. The old `@var Connection $conn`
        // widened a subclass to its parent, which PHPStan rejects as a non-subtype.
        return new MediaScanner($db, new ItemRepository($db), null, null, null, $ffmpeg);
    }
}
