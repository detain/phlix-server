<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Stats;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Uuid;
use Phlix\Media\MediaItemType;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Phlix\Stats\StorageSnapshotHelper;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
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
 *
 * @covers \Phlix\Stats\StatsCollector
 */
final class PlaybackEventMediaTypeEnumTest extends TestCase
{
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

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(
                sprintf('No MySQL on %s:%d — skipping media_type ENUM integration test. Runs in CI.', $host, $port),
            );
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 3) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

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

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }
}
