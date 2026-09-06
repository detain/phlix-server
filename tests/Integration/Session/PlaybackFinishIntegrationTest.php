<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Session;

use Phlix\Auth\UserProfileManager;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\UserItemDataRepository;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Server\Http\Controllers\SessionController;
use Phlix\Server\Http\Request;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S438 (finish-S30) — the REAL-MySQL proof of the playback finish path, replacing the
 * mock-only coverage the audit30 deep-audit scored AC3 unmet with.
 *
 * Every prior test of this path mocked `PlaybackController` outright
 * (`SessionControllerTest` asserts "the mock's method was called"), so no test had ever
 * seeded a row, driven the finish signal, and watched the Continue Watching rail shrink.
 * This file does exactly that against live MySQL — the venue the deep-audit verdict
 * prescribes verbatim:
 *
 *  1. report progress → `completePlayback(reached_end: true)`  → `getContinueWatching()`
 *     no longer returns the item (row survives, finalized to `stopped`/`0`);
 *  2. report progress → `completePlayback(reached_end: false)` → the `playback_state`
 *     row is DELETED;
 *  3. the S438 ruling pin: `MediaUserDataController::markWatched()` (detail-page
 *     "Mark watched") drives the FINALIZE path — every `playback_state` row of that
 *     user for that item converges to `stopped`/`0` and the item leaves the rail.
 *
 * Why finalize and not the CW-SQL JOIN (the recorded decision): the estate rule quoted
 * in the S30 block — "The watched / in-progress signal is `playback_state` (never
 * `user_item_data.watched`)" — makes the rail single-sourced by design; a
 * `LEFT JOIN user_item_data … watched = 0` would introduce a second source of truth the
 * rail was explicitly built not to consult, would leave the marked-watched item's row
 * sitting `playing` with a stale position (stats never finalize), and would change read
 * cost on every rail render. Finalize reuses the mechanism `POST /sessions/{id}/complete`
 * already ships and makes the two rails agree without a schema change (no migration 103).
 *
 * Planted-drift contract: deleting the `finalizeWatchedForUser()` call from
 * `markWatched()` reddens test 3 by name; deleting the `markAsWatched()`/`clearProgress()`
 * branches from `completePlayback()` reddens tests 1 and 2 by name. A mock cannot catch
 * any of these — the assertions run on real rows.
 *
 * CI applies all migrations to the `phlix_test` MySQL service before the suite; locally,
 * with no reachable MySQL, the guard skips — the same venue contract as
 * {@see ContinueWatchingIntegrationTest}, whose fixture idioms this file mirrors.
 */
final class PlaybackFinishIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Lane marker for S438; kept code-resident (never in markdown) per lane contract. */
    private const LANE_TOKEN = 'S438WATCHEDFINISHX2C5';

    private ?Connection $db = null;

    private string $libraryId = '';
    private string $userId = '';
    private string $sessionId = '';
    /** Second device of the SAME user — the S156 shape: migration 097's unique key is
     * per-(session, item), so a title watched on two devices is two live rows and the
     * S438 ruling demands BOTH converge when the user marks the item watched anywhere. */
    private string $otherSessionId = '';
    private string $movieId = '';
    /** Item with NO playback history — the "finalize is a no-op without rows" leg. */
    private string $otherMovieId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping playback-finish integration test. Runs in CI.');

        $this->assertNotNull($this->db);

        $this->libraryId = Uuid::v4();
        $this->userId = Uuid::v4();
        $this->sessionId = Uuid::v4();
        $this->otherSessionId = Uuid::v4();
        $this->movieId = Uuid::v4();
        $this->otherMovieId = Uuid::v4();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * AC3 leg 1 (verbatim from the verdict): report progress → completePlayback →
     * getContinueWatching() no longer returns the item. The row SURVIVES, finalized —
     * this is `markAsWatched` semantics: stopped + position 0.
     */
    public function testCompleteWithReachedEndRemovesItemFromContinueWatchingAgainstRealDb(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $this->insertPlayback($this->sessionId, $this->movieId, 1000, 100000);

        $playback = new PlaybackController($db, new SessionManager($db));
        $this->assertNotNull(
            $this->rowFor($playback->getContinueWatching($this->userId, 10), $this->movieId),
            'Precondition: the in-progress row must surface on the rail first [' . self::LANE_TOKEN . '].'
        );

        $controller = new SessionController(
            new SessionManager($db),
            $playback,
            $this->createMock(MarkerService::class)
        );

        $response = $controller->completePlayback(
            $this->postRequest($this->userId, ['media_item_id' => $this->movieId, 'reached_end' => true]),
            ['id' => $this->sessionId]
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertNull(
            $this->rowFor($playback->getContinueWatching($this->userId, 10), $this->movieId),
            'S30 AC1: a completed episode must leave Continue Watching without manual intervention.'
        );

        $progress = $playback->getUserProgress($this->userId, $this->movieId);
        $this->assertNotNull($progress, 'markAsWatched finalizes in place — the row is kept, not deleted.');
        $this->assertSame('stopped', $progress['playback_status'] ?? null);
        $this->assertSame(0, (int) ($progress['position_ticks'] ?? -1));
    }

    /**
     * AC3 leg 2 (verbatim from the verdict): reached_end:false → the playback_state row
     * is DELETED (clearProgress semantics) and the rail loses the item.
     */
    public function testCompleteWithoutReachedEndDeletesPlaybackRowAgainstRealDb(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $this->insertPlayback($this->sessionId, $this->movieId, 1000, 100000);

        $playback = new PlaybackController($db, new SessionManager($db));
        $controller = new SessionController(
            new SessionManager($db),
            $playback,
            $this->createMock(MarkerService::class)
        );

        $response = $controller->completePlayback(
            $this->postRequest($this->userId, ['media_item_id' => $this->movieId, 'reached_end' => false]),
            ['id' => $this->sessionId]
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertNull(
            $playback->getUserProgress($this->userId, $this->movieId),
            'reached_end:false must DELETE the playback_state row.'
        );
        $this->assertSame(
            0,
            $this->playbackRowCount($this->sessionId, $this->movieId),
            'No playback_state row may survive the clearProgress finish signal.'
        );
        $this->assertNull(
            $this->rowFor($playback->getContinueWatching($this->userId, 10), $this->movieId),
            'S30 AC1: a stopped-early finish must also leave the rail.'
        );
    }

    /**
     * The S438 ruling pin: `markWatched` (the detail-page button) drives the finalize
     * path — user_item_data.watched is set AND every playback_state row of the user for
     * the item converges to stopped/0 on ALL sessions, so the item leaves Continue
     * Watching. Remove the finalize call from the controller and this test goes red here.
     */
    public function testMarkWatchedDrivesFinalizeAcrossAllUserSessionsAgainstRealDb(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        // Same title, two devices, both mid-stream — the exact shape the old
        // session-scoped finalize left half-done on the user-scoped rail.
        $this->insertPlayback($this->sessionId, $this->movieId, 1000, 100000);
        $this->insertPlayback($this->otherSessionId, $this->movieId, 2000, 100000);

        $playback = new PlaybackController($db, new SessionManager($db));
        $this->assertNotNull(
            $this->rowFor($playback->getContinueWatching($this->userId, 10), $this->movieId),
            'Precondition: both rows collapse to ONE rail entry and the item is on it.'
        );

        $controller = new MediaUserDataController(
            new ItemRepository($db),
            new UserItemDataRepository($db, new UserProfileManager($db)),
            null,
            $playback
        );

        $response = $controller->markWatched($this->postRequest($this->userId, []), ['id' => $this->movieId]);

        $this->assertSame(200, $response->statusCode);

        $watchedFlag = $this->watchedFlag();
        $this->assertSame(1, $watchedFlag, 'The user_item_data.watched write must still happen.');

        $this->assertSame(
            0,
            $this->unfinishedPlaybackCount($this->movieId),
            'S438 ruling (finalize, not JOIN): markWatched must drive the finalize path on '
            . 'EVERY playback_state row of this user for this item.'
        );
        $this->assertNull(
            $this->rowFor($playback->getContinueWatching($this->userId, 10), $this->movieId),
            'A marked-watched item must leave Continue Watching without manual intervention.'
        );

        // Idempotence leg: marking an item with NO playback history is still a 200 and
        // finalizes zero rows — the ruling never turns "no history" into an error.
        $again = $controller->markWatched($this->postRequest($this->userId, []), ['id' => $this->otherMovieId]);
        $this->assertSame(200, $again->statusCode);
        $this->assertSame(0, $this->playbackRowCount($this->sessionId, $this->otherMovieId));
    }

    /**
     * A POST Request carrying the authenticated user id and a JSON-shaped body.
     *
     * @param array<string, mixed> $body
     */
    private function postRequest(string $userId, array $body): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/sessions/finish-it';
        $request->userId = $userId;
        $request->body = $body;

        return $request;
    }

    private function seedFixtures(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'movie', '[]')",
            [$this->libraryId, 'S438 Finish Library'],
        );
        $db->query(
            "INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)",
            [
                $this->userId,
                's438-finish-' . substr($this->userId, 0, 8),
                's438-finish-' . substr($this->userId, 0, 8) . '@example.test',
                'x',
            ],
        );

        $this->insertMediaItem($this->movieId, 'S438 Finish Movie', 120);
        $this->insertMediaItem($this->otherMovieId, 'S438 Quiet Movie', 90);

        $db->query(
            "INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)",
            [$this->sessionId, $this->userId, 's438-device-1'],
        );
        $db->query(
            "INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)",
            [$this->otherSessionId, $this->userId, 's438-device-2'],
        );
    }

    private function insertMediaItem(string $id, string $name, int $runtime): void
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $db->query(
            "INSERT INTO media_items (id, library_id, parent_id, name, type, path, metadata_json)
             VALUES (?, ?, NULL, ?, 'movie', ?, ?)",
            [
                $id,
                $this->libraryId,
                $name,
                '/s438-finish/' . $id . '.mkv',
                (string) json_encode(['poster_url' => '/s438/poster.jpg', 'runtime' => $runtime]),
            ],
        );
    }

    private function insertPlayback(string $sessionId, string $mediaItemId, int $position, int $duration): void
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $db->query(
            "INSERT INTO playback_state
                (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, 'playing')",
            [Uuid::v4(), $sessionId, $mediaItemId, $position, $duration],
        );
    }

    private function playbackRowCount(string $sessionId, string $mediaItemId): int
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM playback_state WHERE session_id = ? AND media_item_id = ?",
            [$sessionId, $mediaItemId],
        );
        $this->assertIsArray($rows);

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Rows of this user's playback_state for this item that finalize must have removed
     * from the rail's predicate space (anything not `stopped`/position-0).
     */
    private function unfinishedPlaybackCount(string $mediaItemId): int
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $rows = $db->query(
            "SELECT COUNT(*) AS c
             FROM playback_state ps
             INNER JOIN sessions s ON ps.session_id = s.id
             WHERE s.user_id = ? AND ps.media_item_id = ?
               AND (ps.playback_status <> 'stopped' OR ps.position_ticks <> 0)",
            [$this->userId, $mediaItemId],
        );
        $this->assertIsArray($rows);

        return (int) ($rows[0]['c'] ?? 0);
    }

    private function watchedFlag(): int
    {
        $db = $this->db;
        $this->assertNotNull($db);
        $rows = $db->query(
            'SELECT watched FROM user_item_data WHERE user_id = ? AND item_id = ? LIMIT 1',
            [$this->userId, $this->movieId],
        );
        $this->assertIsArray($rows);

        return (int) ($rows[0]['watched'] ?? -1);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function rowFor(array $rows, string $mediaItemId): ?array
    {
        foreach ($rows as $row) {
            if (($row['media_item_id'] ?? null) === $mediaItemId) {
                return $row;
            }
        }

        return null;
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }
        // Child-first, id-scoped — a shared test DB keeps every other row intact.
        $db->query('DELETE FROM user_item_data WHERE user_id = ?', [$this->userId]);
        foreach ([$this->sessionId, $this->otherSessionId] as $sessionId) {
            if ($sessionId !== '') {
                $db->query('DELETE FROM playback_state WHERE session_id = ?', [$sessionId]);
            }
        }
        foreach ([$this->sessionId, $this->otherSessionId] as $sessionId) {
            if ($sessionId !== '') {
                $db->query('DELETE FROM sessions WHERE id = ?', [$sessionId]);
            }
        }
        foreach ([$this->movieId, $this->otherMovieId] as $itemId) {
            if ($itemId !== '') {
                $db->query('DELETE FROM media_items WHERE id = ?', [$itemId]);
            }
        }
        if ($this->userId !== '') {
            $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
    }
}
