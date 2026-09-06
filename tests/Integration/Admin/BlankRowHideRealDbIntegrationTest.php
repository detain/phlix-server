<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Admin;

use DateTime;
use Phlix\Admin\DashboardService;
use Phlix\Admin\NewsletterGenerator;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Streaming\StreamManager;
use Phlix\Plugins\Util\RecursiveDelete;
use Phlix\Session\SessionManager;
use Phlix\Stats\StatsCollector;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S220 — the S14 HIDE decision, applied to the two blank-row surfaces S14 missed.
 *
 * S14 fixed the dashboard's Top Media / Top Users cards with one recorded product
 * decision: a row whose item or user no longer exists is HIDDEN (INNER JOIN /
 * null-skip) — never rendered with a blank identity and never papered over with a
 * placeholder. The worklog put the rejected placeholder on the record; this file
 * closes the two surfaces that still emitted it:
 *
 *  1. `DashboardService::getRecentPlaybackEvents()` (reached via the public
 *     `getRecentActivity()`) emitted events with `username => null` after a user
 *     deletion and `details.media_title => null` after a media-item deletion.
 *  2. `NewsletterGenerator::getTopMedia()` (reached via the public
 *     `generateForUser()`) LEFT JOINed media and COALESCEd the name to the literal
 *     `'Unknown'` — the exact placeholder S14 rejected — plus a second `'Unknown'`
 *     fallback in the row mapper.
 *
 * ## Why MySQL is real here
 *
 * The defects live in SQL shape (LEFT JOIN + COALESCE) and in hydrate-to-null
 * behaviour across two tables. A mocked `Connection` replays a canned row set and
 * cannot express "the item row was deleted after the event was recorded" — the
 * INNER JOIN rewrite is literally unobservable under mocks. So every test seeds
 * real `users` / `media_items` / `stats_playback_events` rows, performs a REAL
 * DELETE, and then runs the production reader. Harness mirrors
 * {@see \Phlix\Tests\Integration\Stats\PlaybackEventMediaTypeEnumTest} (same
 * {@see RequiresRealDatabase} gate, same owned-id purge discipline); the minted
 * /tmp/phlix_* template dir carries its own S439 tearDown sweep.
 *
 * Each surface is pinned by its own test, so reverting either fix reddens a named
 * test: dropping the null-skip reappears as extra playback events with null
 * identity; restoring the LEFT JOIN + COALESCE reappears as an 'Unknown' row.
 */
final class BlankRowHideRealDbIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Code-resident lane token (S220). */
    private const LANE_TOKEN = 'S220HIDESURFACESX8D2';

    /** Far-future window: sorts above any ambient rows, never collides with real play data. */
    private const WINDOW_START = '2099-01-01 00:00:00';

    private ?Connection $db = null;

    /** @var list<string> Owned stats_playback_events ids. */
    private array $eventIds = [];

    /** @var list<string> Owned media_items ids (including the deleted orphan). */
    private array $mediaIds = [];

    /** @var list<string> Owned users ids (including the deleted orphan). */
    private array $userIds = [];

    private string $libraryId = '';

    private string $templateDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S14-HIDE blank-row integration test. Runs in CI.');
        $this->assertNotNull($this->db);
    }

    protected function tearDown(): void
    {
        // S439 zero-residue law: this class mints a /tmp/phlix_* template dir —
        // the minter owns the sweep.
        if ($this->templateDir !== '') {
            RecursiveDelete::remove($this->templateDir);
            $this->templateDir = '';
        }

        $this->purgeFixtures();
        parent::tearDown();
    }

    /**
     * SURFACE 1 — `getRecentActivity()` must not carry a blank-identity playback
     * row after the event's media item or user account has really been deleted:
     * the surviving neighbour passes through, both orphans are hidden.
     */
    public function test_recent_activity_hides_playback_rows_with_deleted_item_or_user(): void
    {
        $aliveUser = $this->seedUser('s220-alive');
        $doomedUser = $this->seedUser('s220-doomed');
        $aliveItem = $this->seedMediaItem('S220 Alive Movie');
        $doomedItem = $this->seedMediaItem('S220 Doomed Movie');

        $kept = $this->seedPlaybackEvent($aliveUser, $aliveItem, '2099-01-01 10:00:00');
        $orphanedByItemDelete = $this->seedPlaybackEvent($aliveUser, $doomedItem, '2099-01-01 10:01:00');
        $orphanedByUserDelete = $this->seedPlaybackEvent($doomedUser, $aliveItem, '2099-01-01 10:02:00');

        // The real deletions — this is the state after an account/item removal.
        $this->db()->query('DELETE FROM media_items WHERE id = ?', [$doomedItem]);
        $this->db()->query('DELETE FROM users WHERE id = ?', [$doomedUser]);

        $events = $this->dashboard()->getRecentActivity(50);
        $playback = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['category'] === 'playback'
        ));

        $seenIds = array_column($playback, 'id');

        $this->assertContains($kept, $seenIds, self::LANE_TOKEN);
        $this->assertNotContains(
            $orphanedByItemDelete,
            $seenIds,
            'A playback event whose media item was deleted must be HIDDEN (S14 decision), '
            . 'not surfaced with a null details.media_title.',
        );
        $this->assertNotContains(
            $orphanedByUserDelete,
            $seenIds,
            'A playback event whose user account was deleted must be HIDDEN (S14 decision), '
            . 'not surfaced with a null username.',
        );

        // Global invariant over the whole feed: no blank identity anywhere.
        foreach ($playback as $event) {
            $this->assertNotNull($event['username'], 'No playback row may surface a null username.');
            $this->assertNotSame('', $event['username']);
            $this->assertNotNull(
                $event['details']['media_title'],
                'No playback row may surface a null media title.'
            );
        }

        // Regression: the survivor's fields are intact, not perturbed by the skip.
        $keptEvent = $playback[array_search($kept, array_column($playback, 'id'), true)];
        $this->assertSame('s220-alive-' . $aliveUser, $keptEvent['username']);
        $this->assertSame('S220 Alive Movie', $keptEvent['details']['media_title']);
        $this->assertSame('2099-01-01 10:00:00', $keptEvent['occurred_at']);
    }

    /**
     * SURFACE 2 — the newsletter's Top Media must not render the literal
     * 'Unknown' row S14 rejected: after a real item deletion the orphaned play
     * count vanishes, the surviving ranking passes through untouched, and neither
     * payload key nor any rendered body contains the placeholder.
     */
    public function test_newsletter_top_media_hides_orphaned_item_instead_of_unknown_row(): void
    {
        $user = $this->seedUser('s220-reader');
        $topItem = $this->seedMediaItem('S220 Binge Show');
        $secondItem = $this->seedMediaItem('S220 Second Watch');
        $doomedItem = $this->seedMediaItem('S220 Doomed Movie');

        $this->seedPlaybackEvent($user, $doomedItem, '2099-01-01 09:00:00');
        $this->seedPlaybackEvent($user, $topItem, '2099-01-01 10:00:00');
        $this->seedPlaybackEvent($user, $topItem, '2099-01-01 11:00:00');
        $this->seedPlaybackEvent($user, $secondItem, '2099-01-01 12:00:00');

        $this->db()->query('DELETE FROM media_items WHERE id = ?', [$doomedItem]);

        $result = $this->newsletter()->generateForUser($user, new DateTime(self::WINDOW_START));

        $ids = array_column($result['top_media'], 'media_item_id');

        $this->assertSame([$topItem, $secondItem], $ids, self::LANE_TOKEN);
        $this->assertNotContains(
            $doomedItem,
            $ids,
            'An event whose item was deleted must vanish from the newsletter — not render as a row.',
        );

        foreach ($result['top_media'] as $row) {
            $this->assertNotSame('Unknown', $row['name'], 'S14 rejected the "Unknown" placeholder.');
            $this->assertNotSame('', $row['name']);
        }

        $this->assertSame([2, 1], array_column($result['top_media'], 'play_count'));
        $this->assertStringNotContainsString('Unknown', $result['plain_text']);
        $this->assertStringNotContainsString('Unknown', $result['html_body']);
    }

    /**
     * @return Connection
     */
    private function db(): Connection
    {
        $db = $this->db;
        $this->assertNotNull($db);

        return $db;
    }

    /**
     * A real DashboardService over the real connection; only the collaborators the
     * activity feed never touches are mocked — the repository is REAL so
     * findById() hydrates against the same MySQL the event rows reference.
     */
    private function dashboard(): DashboardService
    {
        $db = $this->db();

        return new DashboardService(
            new StatsCollector($db),
            $this->createMock(SessionManager::class),
            $this->createMock(StreamManager::class),
            new ItemRepository($db),
            $db,
        );
    }

    /**
     * A real NewsletterGenerator over the real connection. LibraryManager is mocked
     * because generateForUser()'s read path (watch time, new items, top media,
     * render) never touches it; the template dir is minted under /tmp/phlix_* and
     * swept in tearDown().
     */
    private function newsletter(): NewsletterGenerator
    {
        $db = $this->db();

        $this->templateDir = sys_get_temp_dir() . '/phlix_s220_newsletter_templates';
        if (!is_dir($this->templateDir . '/emails')) {
            mkdir($this->templateDir . '/emails', 0755, true);
        }
        copy(
            dirname(__DIR__, 3) . '/public/templates/emails/newsletter.tpl',
            $this->templateDir . '/emails/newsletter.tpl',
        );

        return new NewsletterGenerator(
            new StatsCollector($db),
            $this->createMock(LibraryManager::class),
            $db,
            $this->templateDir,
        );
    }

    private function seedUser(string $prefix): string
    {
        $db = $this->db();

        $id = Uuid::v4();
        $db->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
            [$id, $prefix . '-' . $id, $id . '@s220.test', 'x'],
        );
        $this->userIds[] = $id;

        return $id;
    }

    private function seedMediaItem(string $name): string
    {
        $db = $this->db();

        if ($this->libraryId === '') {
            $this->libraryId = Uuid::v4();
            $db->query(
                "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'movie', '[]')",
                [$this->libraryId, 'S220 Library'],
            );
        }

        $id = Uuid::v4();
        $db->query(
            "INSERT INTO media_items (id, library_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, 'movie', ?, '{}')",
            [$id, $this->libraryId, $name, '/s220/' . $id . '.mkv'],
        );
        $this->mediaIds[] = $id;

        return $id;
    }

    private function seedPlaybackEvent(string $userId, string $mediaItemId, string $at): string
    {
        $db = $this->db();

        $id = Uuid::v4();
        $db->query(
            'INSERT INTO stats_playback_events
                (id, user_id, media_item_id, media_type, started_at, ended_at, duration_seconds, completed)
             VALUES (?, ?, ?, ?, ?, ?, 600, 1)',
            [$id, $userId, $mediaItemId, 'movie', $at, $at],
        );
        $this->eventIds[] = $id;

        return $id;
    }

    private function purgeFixtures(): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        if ($this->eventIds !== []) {
            $db->query(
                'DELETE FROM stats_playback_events WHERE id IN ('
                . implode(',', array_fill(0, count($this->eventIds), '?')) . ')',
                $this->eventIds,
            );
        }
        foreach ($this->mediaIds as $id) {
            $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
        }
        foreach ($this->userIds as $id) {
            $db->query('DELETE FROM users WHERE id = ?', [$id]);
        }
        if ($this->libraryId !== '') {
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }

        $this->eventIds = [];
        $this->mediaIds = [];
        $this->userIds = [];
        $this->libraryId = '';
    }
}
