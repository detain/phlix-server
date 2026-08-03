<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Stats;

use Phlix\Admin\DashboardService;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaItemType;
use Phlix\Media\Streaming\StreamManager;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Phlix\Stats\StorageSnapshotHelper;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof for the S102 `media_type` ENUM defect.
 *
 * ## What went wrong
 *
 * `PlaybackController::dispatchPlaybackStarted()` hands the RAW `media_items.type`
 * value to `StatsCollector::recordPlaybackStart()`, but migration 019 declared
 * `stats_playback_events.media_type` as `ENUM('movie','series','music','photo')` —
 * four of the thirteen members. Under `STRICT_TRANS_TABLES` every other value is
 * MySQL error **1265 "Data truncated for column 'media_type'"**, raised by the
 * driver as a `PDOException` that nothing on the path caught, so it escaped into
 * the HTTP worker. Production: all 235 stored rows `movie`; every episode play
 * since S31 threw instead of recording.
 *
 * ## Why this test MUST use a real database
 *
 * A mocked `Connection` accepts any string for any column — it cannot reproduce
 * an ENUM rejection, a strict-mode truncation, or error 1265 at all. That is
 * precisely how this shipped past a mock-only suite, and it is the same class of
 * miss as the LiveTv `RowQuery`/`ResultSet` and metrics `ONLY_FULL_GROUP_BY`
 * defects. So this test executes the real INSERT against real MySQL and reads the
 * row back.
 *
 * {@see testStrictTransTablesIsActive} guards the whole class: without strict mode
 * MySQL silently truncates instead of erroring, and every assertion below would
 * pass vacuously against the very schema that was broken.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite;
 * locally, with no reachable MySQL, it self-skips — the same guard
 * {@see \Phlix\Tests\Integration\Media\MusicTracksQueryIntegrationTest} uses. The
 * schema side of the same defect is additionally pinned with no database at all by
 * {@see \Phlix\Tests\Unit\Media\MediaItemTypeDriftTest}, which parses the
 * migration SQL.
 */
final class PlaybackEventMediaTypeEnumTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    /** @var list<string> Seeded stats_playback_events ids. */
    private array $eventIds = [];

    /** @var list<string> Seeded stats_storage ids. */
    private array $storageIds = [];

    /** @var list<string> Seeded media_items ids. */
    private array $mediaIds = [];

    private string $libraryId = '';

    private string $userId = '';

    private string $sessionId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping media_type ENUM integration test. Runs in CI.');

        $this->assertNotNull($this->db);
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * The premise of this whole class. `STRICT_TRANS_TABLES` is what turns an
     * out-of-range ENUM value into error 1265 rather than a silent truncation to
     * `''`, so without it none of the assertions below mean anything.
     */
    public function testStrictTransTablesIsActive(): void
    {
        $rows = $this->rows('SELECT @@session.sql_mode AS sql_mode');

        $this->assertArrayHasKey(0, $rows);
        $mode = (string) ($rows[0]['sql_mode'] ?? '');

        $this->assertStringContainsString(
            'STRICT_TRANS_TABLES',
            $mode,
            'Without STRICT_TRANS_TABLES MySQL silently truncates an out-of-range ENUM value, '
            . 'so this test class would pass against the broken schema. Mode was: ' . $mode,
        );
    }

    /**
     * The migration is only correct if the column really accepts every member.
     * Read the live column definition rather than trusting the SQL file.
     */
    public function testTheLiveColumnAcceptsTheFullVocabulary(): void
    {
        $rows = $this->rows(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stats_playback_events'
               AND COLUMN_NAME = 'media_type'",
        );

        $this->assertArrayHasKey(0, $rows);
        $columnType = (string) ($rows[0]['t'] ?? '');

        foreach (MediaItemType::ALL as $type) {
            $this->assertStringContainsString(
                "'" . $type . "'",
                $columnType,
                sprintf('stats_playback_events.media_type cannot store "%s": %s', $type, $columnType),
            );
        }
    }

    /**
     * THE REGRESSION. Every `media_items.type` member must survive the round trip
     * VERBATIM — no fold, no coercion. Against migration 019's four-member ENUM
     * this test throws
     * `PDOException … SQLSTATE[01000]: Warning: 1265 Data truncated for column 'media_type'`
     * on `season` (the third member) and would do so for nine of the thirteen.
     */
    public function testEveryMediaItemTypeRoundTripsThroughRecordPlaybackStart(): void
    {
        $collector = $this->collector();

        foreach (MediaItemType::ALL as $type) {
            $eventId = $collector->recordPlaybackStart($this->userId(), 'media-' . $type, $type, 'device-1');
            $this->eventIds[] = $eventId;

            $stored = $this->storedMediaType($eventId);

            $this->assertSame(
                $type,
                $stored,
                sprintf(
                    'media_type "%s" must be stored verbatim; got %s. Pre-migration-094 this INSERT '
                    . 'raised MySQL error 1265 and threw out of the HTTP worker.',
                    $type,
                    var_export($stored, true),
                ),
            );
        }
    }

    /**
     * The value that actually broke production, called out on its own so the
     * reason this file exists cannot be refactored away.
     */
    public function testAnEpisodePlayIsRecordedAsEpisode(): void
    {
        $eventId = $this->collector()->recordPlaybackStart($this->userId(), 'media-ep', 'episode', 'device-1');
        $this->eventIds[] = $eventId;

        $this->assertSame('episode', $this->storedMediaType($eventId));
    }

    /**
     * A value outside the column ENUM must NOT be lost and must NOT throw: it is
     * coerced to the shared fallback and logged. This is what protects the write
     * if `media_items.type` ever grows a 14th member before this column does.
     */
    public function testAnUnknownTypeIsCoercedRatherThanRejected(): void
    {
        // `image` is the classic wrong value in this repo — a scanner label that
        // has never been a column member.
        $eventId = $this->collector()->recordPlaybackStart($this->userId(), 'media-x', 'image', 'device-1');
        $this->eventIds[] = $eventId;

        $this->assertSame(MediaItemType::FALLBACK, $this->storedMediaType($eventId));
    }

    /**
     * The exception boundary, proven with a failure that has nothing to do with
     * the ENUM: a 100-character `user_id` cannot fit `CHAR(36)`, so strict mode
     * rejects the INSERT. `recordPlaybackStart()` must still return a well-formed
     * event id instead of throwing — telemetry failing can never break the action
     * that triggered it.
     */
    public function testAFailingWriteIsContainedInsteadOfThrown(): void
    {
        $collector = $this->collector();

        $eventId = $collector->recordPlaybackStart(str_repeat('x', 100), 'media-1', 'movie', 'device-1');

        $this->assertNotSame('', $eventId, 'A contained write must still hand back a usable event id');
        $this->assertNull($this->storedMediaType($eventId), 'The rejected row must not exist');

        // recordPlaybackEnd() takes the same id and must also be a no-op rather
        // than a throw, so the caller's contract holds end to end.
        $collector->recordPlaybackEnd($eventId, 120, true);
        $this->assertNull($this->storedMediaType($eventId));
    }

    /**
     * `stats_storage.media_type` deliberately stays COARSE (it has a real reader
     * in `DashboardService::getStorageSummary()`), so a raw 13-member type handed
     * to the snapshot writer must be FOLDED to a bucket rather than rejected.
     */
    public function testStorageSnapshotFoldsRawTypesToCoarseBuckets(): void
    {
        $collector = $this->collector();
        $before = $this->storageRowIds();

        foreach (['episode' => 'series', 'track' => 'music', 'audiobook' => 'book', 'movie' => 'movie'] as $t => $b) {
            $collector->recordStorageSnapshot($t, 1, 1024);

            $new = array_values(array_diff($this->storageRowIds(), $before, $this->storageIds));
            $this->assertCount(1, $new, sprintf('recordStorageSnapshot("%s") must insert exactly one row', $t));
            $this->storageIds[] = $new[0];

            $rows = $this->rows('SELECT media_type FROM stats_storage WHERE id = ?', [$new[0]]);
            $this->assertArrayHasKey(0, $rows);
            $this->assertSame(
                $b,
                (string) ($rows[0]['media_type'] ?? ''),
                sprintf('Type "%s" must fold to bucket "%s"', $t, $b),
            );
            $this->assertContains($b, StorageSnapshotHelper::BUCKETS);
        }
    }

    /**
     * S102 review r1 MED-3 — an unmapped type must be DROPPED, not misfiled.
     *
     * Pre-fix `recordStorageSnapshot('totally-unknown', 3, 999)` wrote
     * `media_type=movie, total_bytes=999`: bytes attributed to a real bucket, with
     * only an app.log warning. `migrations/086_stats_storage_book_bucket.sql:11-14`
     * writes the rule down — a wrong number that looks right is worse than a
     * visibly missing one.
     */
    public function testAnUnmappedStorageTypeWritesNoRowAtAll(): void
    {
        $before = $this->storageRowIds();

        $this->collector()->recordStorageSnapshot('totally-unknown', 3, 999);

        $this->assertSame(
            [],
            array_values(array_diff($this->storageRowIds(), $before)),
            'An unmapped media type must not produce a stats_storage row at all — least of all a '
            . '`movie` one carrying its bytes.',
        );
    }

    /**
     * S102 review r1 MED-2, READER half — colliding rows must SUM.
     *
     * Seeded with one explicit `recorded_at` shared by all 13 rows, which is
     * exactly what a snapshot run used to produce: `stats_storage.recorded_at` is
     * `DATETIME` (second precision) written with `NOW()`, and several raw types
     * fold onto the same bucket. `getStorageSummary()` ASSIGNED (`=`) while
     * ordering `BY total_bytes DESC`, so the SMALLEST colliding row won each
     * bucket and the rest vanished: 91,000 bytes written became 31,000 in the five
     * headline totals.
     */
    public function testTheDashboardRollUpsSumRowsThatShareARecordedAtSecond(): void
    {
        $recordedAt = '2031-02-03 04:05:06';
        $written = 0;

        foreach (MediaItemType::ALL as $index => $type) {
            $bytes = 1_000 * ($index + 1);
            $written += $bytes;
            $this->seedStorageRow(StorageSnapshotHelper::TYPE_TO_BUCKET[$type], $bytes, $recordedAt);
        }

        $this->assertSame(91_000, $written, 'The review r1 fixture: 13 types, 91,000 bytes');

        $summary = $this->dashboard()->getStorageSummary();

        $this->assertSame(91_000, $this->rollUpTotal($summary), $this->rollUpMessage($summary));
        // movie 1,000 + video 9,000
        $this->assertSame(10_000, $summary['movie_bytes']);
        // series 2,000 + season 3,000 + episode 4,000
        $this->assertSame(9_000, $summary['series_bytes']);
        // track 5,000 + music 6,000 + album 7,000 + artist 8,000 + audio 10,000
        $this->assertSame(36_000, $summary['music_bytes']);
        $this->assertSame(12_000, $summary['photo_bytes']);
        // book 11,000 + audiobook 13,000
        $this->assertSame(24_000, $summary['book_bytes']);
    }

    /**
     * S102 review r1 MED-2, WRITER half — one batch call, one row per bucket, and
     * the dashboard roll-ups equal the bytes handed in.
     *
     * This is the natural caller the docblock's promise was about: iterate the
     * vocabulary and record it. Pre-fix that produced 13 rows sharing one
     * `recorded_at` and lost 60,000 of 91,000 bytes.
     */
    public function testTheBatchWriterRoundTripsEveryByteToTheDashboard(): void
    {
        $before = $this->storageRowIds();

        $totals = [];
        $written = 0;
        foreach (MediaItemType::ALL as $index => $type) {
            $bytes = 1_000 * ($index + 1);
            $written += $bytes;
            $totals[$type] = ['count' => 1, 'bytes' => $bytes];
        }
        $this->assertSame(91_000, $written);

        $this->collector()->recordStorageSnapshots($totals);

        $new = array_values(array_diff($this->storageRowIds(), $before, $this->storageIds));
        foreach ($new as $id) {
            $this->storageIds[] = $id;
        }
        $this->assertCount(
            5,
            $new,
            'One batch call must write exactly one row per stats_storage bucket, whatever the '
            . 'number of raw types folded into it.',
        );

        $summary = $this->dashboard()->getStorageSummary();

        $this->assertSame(91_000, $this->rollUpTotal($summary), $this->rollUpMessage($summary));
        $this->assertSame(13, $this->latestItemCountTotal(), 'All 13 item counts must survive the fold');
    }

    /**
     * S102 review r2 MED-1, SQL half — the QUERY must collapse duplicate rows.
     *
     * The reader aggregates twice (`SUM(…) GROUP BY media_type` in SQL, `+=` in
     * PHP) and the two halves hide each other, so the whole suite stayed green with
     * either one reverted. This test sees only the SQL half: TWO rows per bucket in
     * one `recorded_at` second must come back as FIVE `items` rows whose
     * `total_bytes` are the pair's sum. Drop the `SUM`/`GROUP BY` and it is ten rows
     * carrying half the bytes each; the PHP half cannot rescue that, because
     * `items[]` is what the dashboard's per-type table renders. The `+=` half has
     * its own database-free pin in
     * {@see \Phlix\Tests\Unit\Admin\DashboardServiceTest::test_get_storage_summary_sums_two_rows_for_one_bucket}.
     */
    public function testTheQueryItselfCollapsesDuplicateRowsIntoOneItemPerBucket(): void
    {
        $recordedAt = '2031-02-03 04:05:06';

        /** @var array<string, int> $expected */
        $expected = [];
        $written = 0;
        foreach (StorageSnapshotHelper::BUCKETS as $index => $bucket) {
            $first = 1_000 * ($index + 1);
            $second = 10_000 * ($index + 1);
            $this->seedStorageRow($bucket, $first, $recordedAt);
            $this->seedStorageRow($bucket, $second, $recordedAt);
            $expected[$bucket] = $first + $second;
            $written += $first + $second;
        }

        $summary = $this->dashboard()->getStorageSummary();

        $this->assertCount(
            5,
            $summary['items'],
            'The query must return ONE row per bucket. Ten rows here means the SUM/GROUP BY was '
            . 'dropped and every per-type figure the dashboard renders is a fragment.',
        );

        /** @var array<string, int> $actual */
        $actual = [];
        foreach ($summary['items'] as $item) {
            $actual[$item['media_type']] = $item['total_bytes'];
            $this->assertSame(2, $item['item_count'], 'item_count must be SUMmed too, not sampled');
        }
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);

        $this->assertSame($written, $this->rollUpTotal($summary), $this->rollUpMessage($summary));
    }

    /**
     * The reviewer's EXACT fixture, through the public single-row API: 13 separate
     * `recordStorageSnapshot()` calls, i.e. the ad-hoc pattern that API still
     * allows. Each call writes its own row, several of them into the same bucket,
     * which is the collision the roll-ups used to lose 60,000 of 91,000 bytes to.
     *
     * ## The loop deliberately STRADDLES a wall-clock second (S102 review r2, MED-2)
     *
     * This test used to force all 13 rows onto one `recorded_at` before reading, and
     * that stamp was doing the work its name claimed the code did: `recorded_at` is
     * second-precision, each INSERT took its own `NOW()`, and
     * `getStorageSummary()`'s join is `MAX(recorded_at)` **per `media_type`** — so
     * on a loaded box the run spread over three seconds and the dashboard reported
     * 44,000 of the 91,000 bytes handed in. 47,000 lost, silently, by wall-clock
     * luck. `StatsCollector::snapshotRunSecond()` now stamps a whole run once, so
     * the property is real: this test crosses a second boundary ON PURPOSE, asserts
     * that it really did, stamps NOTHING, and still gets every byte back.
     */
    public function testThirteenIndividualCallsRollUpToEveryByteAcrossASecondBoundary(): void
    {
        $before = $this->storageRowIds();
        $collector = $this->collector();

        $startedAtSecond = time();
        $written = 0;
        foreach (MediaItemType::ALL as $index => $type) {
            $bytes = 1_000 * ($index + 1);
            $written += $bytes;
            $collector->recordStorageSnapshot($type, 1, $bytes);

            if ($index === 5) {
                $this->waitForTheClockSecondToChange();
            }
        }

        $this->assertGreaterThan(
            $startedAtSecond,
            time(),
            'PRECONDITION: the loop must really have crossed a wall-clock second, otherwise this '
            . 'test proves nothing beyond the single-second case.',
        );

        $ids = array_values(array_diff($this->storageRowIds(), $before, $this->storageIds));
        foreach ($ids as $id) {
            $this->storageIds[] = $id;
        }

        $this->assertSame(91_000, $written, 'The review r1 fixture: 13 types, 91,000 bytes');
        $this->assertCount(13, $ids, 'One row per call — the writer must not drop a single type');
        $this->assertSame(
            1,
            $this->distinctRecordedAt($ids),
            'One snapshot run is ONE recorded_at, however many calls and however many seconds it '
            . 'spans — nothing in this test touches recorded_at.',
        );

        $summary = $this->dashboard()->getStorageSummary();

        $this->assertSame(91_000, $this->rollUpTotal($summary), $this->rollUpMessage($summary));
        $this->assertSame(13, $this->latestItemCountTotal(), 'All 13 item counts must survive');
    }

    /**
     * End to end at the seam that actually 500'd: a first progress report for an
     * `episode` drives `dispatchPlaybackStarted()`, which looks the type up out of
     * `media_items` and records it. Pre-fix this call threw a `PDOException`
     * straight through `reportProgress()`; now it returns and the event carries
     * `episode`.
     */
    public function testReportProgressOnAnEpisodeRecordsTheEventWithoutThrowing(): void
    {
        $mediaItemId = $this->seedEpisode();
        $sessionId = $this->seedSession();

        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'user_id' => $this->userId(),
            'device_id' => 'device-it',
        ]);

        $db = $this->db;
        $this->assertNotNull($db);

        $controller = new PlaybackController(
            $db,
            $sessionManager,
            null,
            $this->createMock(EventDispatcherInterface::class),
            new StatsCollector($db),
        );

        // No expectException(): the whole point is that this does not throw.
        $controller->reportProgress($sessionId, $mediaItemId, 12_000_000_000, 36_000_000_000, false);

        $rows = $this->rows(
            'SELECT id, media_type FROM stats_playback_events WHERE media_item_id = ? ORDER BY started_at DESC LIMIT 1',
            [$mediaItemId],
        );

        $this->assertArrayHasKey(0, $rows, 'reportProgress() must have recorded a playback event');
        $this->eventIds[] = (string) ($rows[0]['id'] ?? '');
        $this->assertSame(
            'episode',
            (string) ($rows[0]['media_type'] ?? ''),
            'The event must carry the REAL media_items.type, not a fold and not a hardcoded "movie"',
        );
    }

    private function collector(): StatsCollector
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new StatsCollector($db);
    }

    /**
     * A real `DashboardService` over the real connection — only the collaborators
     * `getStorageSummary()` never touches are mocked, so the SQL under test is the
     * production SQL.
     */
    private function dashboard(): DashboardService
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return new DashboardService(
            new StatsCollector($db),
            $this->createMock(SessionManager::class),
            $this->createMock(StreamManager::class),
            $this->createMock(ItemRepository::class),
            $db,
        );
    }

    /**
     * Insert one `stats_storage` row with an explicit `recorded_at`, bypassing the
     * writer so the READER can be tested in isolation.
     */
    private function seedStorageRow(string $bucket, int $totalBytes, string $recordedAt): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $id = Uuid::v4();
        $db->query(
            'INSERT INTO stats_storage (id, recorded_at, library_id, media_type, item_count, total_bytes,
                                        transcode_cache_bytes)
             VALUES (?, ?, NULL, ?, 1, ?, 0)',
            [$id, $recordedAt, $bucket, $totalBytes],
        );
        $this->storageIds[] = $id;
    }

    /**
     * Sum of the five headline byte totals the dashboard's Storage card renders.
     *
     * @param array{movie_bytes: int, series_bytes: int, music_bytes: int, photo_bytes: int, book_bytes: int, ...} $summary
     */
    private function rollUpTotal(array $summary): int
    {
        return $summary['movie_bytes']
            + $summary['series_bytes']
            + $summary['music_bytes']
            + $summary['photo_bytes']
            + $summary['book_bytes'];
    }

    /**
     * @param array{movie_bytes: int, series_bytes: int, music_bytes: int, photo_bytes: int, book_bytes: int, ...} $summary
     */
    private function rollUpMessage(array $summary): string
    {
        return sprintf(
            'The five dashboard roll-ups must equal the bytes written. Got movie=%d series=%d music=%d '
            . 'photo=%d book=%d',
            $summary['movie_bytes'],
            $summary['series_bytes'],
            $summary['music_bytes'],
            $summary['photo_bytes'],
            $summary['book_bytes'],
        );
    }

    /**
     * Total `item_count` across the rows the dashboard query actually reads.
     */
    private function latestItemCountTotal(): int
    {
        $total = 0;
        foreach ($this->dashboard()->getStorageSummary()['items'] as $item) {
            $total += $item['item_count'];
        }

        return $total;
    }

    /**
     * How many distinct `recorded_at` values the given rows carry.
     *
     * @param list<string> $ids
     */
    private function distinctRecordedAt(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $seen = [];
        foreach ($ids as $id) {
            $rows = $this->rows('SELECT recorded_at FROM stats_storage WHERE id = ?', [$id]);
            $seen[(string) ($rows[0]['recorded_at'] ?? '')] = true;
        }

        return count($seen);
    }

    /**
     * Block until the wall clock's SECOND changes, so a run can be made to straddle
     * a `recorded_at` boundary deterministically instead of hoping the box is busy.
     *
     * A plain `usleep()` is correct here and only here: this is a test process, not
     * a Workerman handler — nothing resident is being stalled — and the wait is
     * bounded by definition at one second.
     */
    private function waitForTheClockSecondToChange(): void
    {
        $second = time();
        while (time() === $second) {
            usleep(20_000);
        }
    }

    /**
     * Stored `media_type` for one event id, or null when no row exists.
     */
    private function storedMediaType(string $eventId): ?string
    {
        $rows = $this->rows('SELECT media_type FROM stats_playback_events WHERE id = ?', [$eventId]);
        if (!isset($rows[0])) {
            return null;
        }

        return (string) ($rows[0]['media_type'] ?? '');
    }

    /**
     * @return list<string>
     */
    private function storageRowIds(): array
    {
        $out = [];
        foreach ($this->rows('SELECT id FROM stats_storage') as $row) {
            $out[] = (string) ($row['id'] ?? '');
        }

        return $out;
    }

    /**
     * A `users` row this fixture owns — `stats_playback_events.user_id` has no FK,
     * but `sessions.user_id` does.
     */
    private function userId(): string
    {
        if ($this->userId !== '') {
            return $this->userId;
        }

        $db = $this->db;
        $this->assertNotNull($db);

        $this->userId = Uuid::v4();
        $db->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
            [$this->userId, 's102-' . $this->userId, $this->userId . '@s102.test', 'x'],
        );

        return $this->userId;
    }

    private function seedEpisode(): string
    {
        $db = $this->db;
        $this->assertNotNull($db);

        if ($this->libraryId === '') {
            $this->libraryId = Uuid::v4();
            $db->query(
                "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'series', '[]')",
                [$this->libraryId, 'S102 IT Library'],
            );
        }

        $mediaItemId = Uuid::v4();
        $db->query(
            "INSERT INTO media_items (id, library_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, 'episode', ?, '{}')",
            [$mediaItemId, $this->libraryId, 'S102 Episode', '/s102-it/' . $mediaItemId . '.mkv'],
        );
        $this->mediaIds[] = $mediaItemId;

        return $mediaItemId;
    }

    private function seedSession(): string
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $this->sessionId = Uuid::v4();
        $db->query(
            'INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)',
            [$this->sessionId, $this->userId(), 'device-it'],
        );

        return $this->sessionId;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $db = $this->db;
        $this->assertNotNull($db);

        /** @var mixed $result */
        $result = $db->query($sql, $params);
        if (!is_array($result)) {
            return [];
        }

        $out = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        foreach ($this->eventIds as $id) {
            $db->query('DELETE FROM stats_playback_events WHERE id = ?', [$id]);
        }
        foreach ($this->storageIds as $id) {
            $db->query('DELETE FROM stats_storage WHERE id = ?', [$id]);
        }
        // playback_state + sessions cascade off their parents.
        if ($this->sessionId !== '') {
            $db->query('DELETE FROM sessions WHERE id = ?', [$this->sessionId]);
        }
        foreach ($this->mediaIds as $id) {
            $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        if ($this->userId !== '') {
            $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
        }

        $this->eventIds = [];
        $this->storageIds = [];
        $this->mediaIds = [];
        $this->libraryId = '';
        $this->sessionId = '';
        $this->userId = '';
    }
}
