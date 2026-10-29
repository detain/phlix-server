<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Collections;

use Phlix\Collections\CollectionItemRepository;
use Phlix\Collections\CollectionManager;
use Phlix\Collections\CollectionRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Playlists\SmartPlaylistEngine;
use Phlix\Playlists\SmartPlaylistRepository;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * M-SERVER-07 — real-MySQL proof that re-adding an item to a collection/playlist
 * is an IDEMPOTENT NO-OP, not an uncaught MySQL 1062 → HTTP 500.
 *
 * ## The defect (measured from red at the parent commit)
 *
 * `collection_items` is keyed by `PRIMARY KEY (collection_id, media_item_id)`
 * (migrations/005_collections.sql), while `CollectionItemRepository::insert()`
 * and `batchInsert()` issued plain INSERTs and `CollectionManager::addItem()`
 * pre-checked nothing. Adding the same media item twice — double-click, retry
 * after a network blip, two tabs — deterministically raised
 * `PDOException ... Duplicate entry ... for key 'collection_items.PRIMARY'`
 * (MySQL 1062) and the route 500s. `batchInsert()` was worse: one duplicate
 * tuple inside a bulk add failed the ENTIRE multi-row statement, so even the
 * brand-new items in the same batch were lost.
 *
 * ## The cure proven here
 *
 * `INSERT ... ON DUPLICATE KEY UPDATE media_item_id = VALUES(media_item_id)`
 * — the self-assignment no-op form of the estate's house idiom (the one
 * `WatchHistory::record()` narrates and `PlaybackController::reportProgress()`
 * ships): the duplicate becomes structurally impossible on the write itself,
 * so there is no read-probe TOCTOU window and nothing is swallowed — any
 * non-duplicate SQL failure still propagates (proved below).
 *
 * The ratified duplicate semantics: the EXISTING row is left untouched —
 * neither sort_order nor added_at is re-touched. A re-add must never reorder
 * a curated collection or refresh membership age (least-surprise law, pinned
 * by testReAddingAnExistingItemKeepsItsPositionAndAddedAtUnchanged).
 *
 * SAFETY: destructive proofs run in a throwaway `phlix_s7_*` DATABASE built
 * from the REAL 005 file (S79/S114 pattern), never against the shared
 * `phlix_test` tables.
 */
final class CollectionItemReAddRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    /** Lane survival token (M-SERVER-07). Code-resident by design; never prose. */
    private const LANE_TOKEN = 'S7READDUPLICATEITEMK9T4';

    private const MIGRATION_005 = '005_collections.sql';

    /** The distinctive past stamp: a duplicate add that re-touched added_at would move it to now(). */
    private const SEADED_ADDED_AT = '2001-02-03 04:05:06';

    private const COLLECTION_ID = 's7-col-0000-0000-0000-000000000001';

    private const EXISTING_ITEM = 's7-item-0000-0000-0000-000000000002';

    private const FRESH_ITEM = 's7-item-0000-0000-0000-000000000003';

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    protected function setUp(): void
    {
        parent::setUp();

        // S126 gate: skip on genuine absence, LOUD on reachable-but-unusable.
        $this->requireHealthyDatabase('skipping the M-SERVER-07 collection re-add proof. Runs in CI with MySQL.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $this->scratchDb = 'phlix_s7_' . bin2hex(random_bytes(6));
        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->applyMigration005();
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE IF EXISTS `' . $this->scratchDb . '`');
        }

        $this->db = null;
        $this->admin = null;
        $this->scratchDb = '';

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Real-DB proofs
    // ------------------------------------------------------------------

    /**
     * The headline AC: re-adding the existing (collection, item) pair raises
     * NOTHING. On the parent commit this dies with
     * PDOException "Duplicate entry ... for key 'collection_items.PRIMARY'".
     */
    public function testReAddingAnExistingItemRaisesNoDuplicateKeyError(): void
    {
        $repo = new CollectionItemRepository($this->db);

        $repo->insert(self::COLLECTION_ID, self::EXISTING_ITEM, 42);
        $repo->insert(self::COLLECTION_ID, self::EXISTING_ITEM, 43);

        $this->assertSame(
            1,
            $this->scalar('SELECT COUNT(*) FROM collection_items'),
            self::LANE_TOKEN . ': the second add must be a no-op, never a second row'
        );
    }

    /**
     * Duplicate semantics (ratified): the EXISTING row is untouched — neither
     * sort_order nor added_at is re-written by a duplicate add. The seeded row
     * carries a distinctive 2001 stamp and sort_order 42; the re-add candidates
     * are sort 99/100 with now() timestamps. Any survivor value proves the no-op.
     */
    public function testReAddingAnExistingItemKeepsItsPositionAndAddedAtUnchanged(): void
    {
        $this->seedSealedMember();

        $repo = new CollectionItemRepository($this->db);
        $repo->insert(self::COLLECTION_ID, self::EXISTING_ITEM, 99);

        $rows = $this->rows(
            'SELECT sort_order, added_at FROM collection_items WHERE media_item_id = ?',
            [self::EXISTING_ITEM]
        );
        $this->assertCount(1, $rows);
        $this->assertSame(42, (int) $rows[0]['sort_order'], 'a duplicate add must NOT re-order the existing member');
        $this->assertSame(
            self::SEADED_ADDED_AT,
            (string) $rows[0]['added_at'],
            'a duplicate add must NOT refresh added_at — membership age is preserved'
        );
    }

    /**
     * The exact user path from the finding: CollectionManager::addItem() twice
     * for the same item (double-click / retry / two tabs) lands one row and
     * keeps the FIRST sort position (no move-to-end).
     */
    public function testManagerAddItemTwiceLandsOneRowAtTheFirstPosition(): void
    {
        $manager = $this->buildManager();

        $manager->addItem(self::COLLECTION_ID, self::FRESH_ITEM);
        $manager->addItem(self::COLLECTION_ID, self::FRESH_ITEM);

        $rows = $this->rows(
            'SELECT sort_order FROM collection_items WHERE media_item_id = ?',
            [self::FRESH_ITEM]
        );
        $this->assertCount(1, $rows, self::LANE_TOKEN . ': double addItem must not duplicate the membership row');
        $this->assertSame(
            1,
            (int) $rows[0]['sort_order'],
            'the duplicate add must not move the item to the end (max+1 candidate discarded by the no-op)'
        );
    }

    /**
     * batchInsert() with a mixed set (one existing + two fresh): on the parent
     * the WHOLE multi-row statement died on 1062 and even the fresh items were
     * lost; after the cure all three members exist and the duplicate keeps its
     * sealed position.
     */
    public function testBatchInsertWithOneDuplicateStillWritesTheFreshRows(): void
    {
        $this->seedSealedMember();

        $repo = new CollectionItemRepository($this->db);
        $repo->batchInsert(self::COLLECTION_ID, [
            ['mediaItemId' => self::EXISTING_ITEM, 'sortOrder' => 7],
            ['mediaItemId' => self::FRESH_ITEM, 'sortOrder' => 8],
            ['mediaItemId' => 's7-item-0000-0000-0000-000000000004', 'sortOrder' => 9],
        ]);

        $this->assertSame(3, $this->scalar('SELECT COUNT(*) FROM collection_items'));

        $existing = $this->rows(
            'SELECT sort_order, added_at FROM collection_items WHERE media_item_id = ?',
            [self::EXISTING_ITEM]
        );
        $this->assertCount(1, $existing);
        $this->assertSame(42, (int) $existing[0]['sort_order'], 'the duplicate tuple must not re-order the sealed row');
        $this->assertSame(
            self::SEADED_ADDED_AT,
            (string) $existing[0]['added_at'],
            'the duplicate tuple must not refresh the sealed row added_at'
        );
    }

    /**
     * Never-swallow-all law: the ODKU arm answers ONLY the duplicate case. A
     * different SQL failure (here 1406 Data too long under STRICT mode against
     * CHAR(36)) must still propagate — the route's 500 for genuine breakage
     * is preserved. Green before and after the fix; it pins the idiom choice.
     */
    public function testNonDuplicateWriteErrorsStillFailLoud(): void
    {
        $repo = new CollectionItemRepository($this->db);

        try {
            $repo->insert(str_repeat('x', 200), self::FRESH_ITEM, 1);
            $this->fail(self::LANE_TOKEN . ': a data-too-long INSERT must not pass silently');
        } catch (Throwable $e) {
            $this->assertMatchesRegularExpression(
                '/1406|Data too long/i',
                $e->getMessage(),
                'the surfaced error must be the genuine 1406, not a swallowed duplicate'
            );
        }
    }

    /**
     * Structural precondition of the whole cure: the constraint the ODKU arm
     * answers is the composite PRIMARY KEY (collection_id, media_item_id) of
     * the REAL 005 DDL. If a future migration changes that shape, this reddens
     * and the idiom must be re-derived — it must never silently stop answering
     * the intended key.
     */
    public function testTheDuplicateKeyTheCureAnswersIsTheCompositePrimaryKey(): void
    {
        $rows = $this->rows(
            'SELECT SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            ['collection_items', 'PRIMARY']
        );

        $this->assertCount(2, $rows);
        $this->assertSame('collection_id', (string) $rows[0]['COLUMN_NAME']);
        $this->assertSame('media_item_id', (string) $rows[1]['COLUMN_NAME']);
    }

    /**
     * The smart-refresh writer path (applyMemberDiff) rides insert() inside a
     * transaction; a duplicate add through it must also be a no-op rather than
     * a rolled-back diff (it pre-filters existing members, so reaching this is
     * the TOCTOU-loss case the diff loop previously 500ed on).
     */
    public function testApplyMemberDiffSurvivesALostRaceAdd(): void
    {
        $this->seedSealedMember();

        $repo = new CollectionItemRepository($this->db);
        $repo->applyMemberDiff(self::COLLECTION_ID, [self::EXISTING_ITEM, self::FRESH_ITEM], [], 100);

        $this->assertSame(
            2,
            $this->scalar('SELECT COUNT(*) FROM collection_items'),
            self::LANE_TOKEN . ': the diff must land the fresh member and no-op the duplicate, committing cleanly'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildManager(): CollectionManager
    {
        $itemRepo = new CollectionItemRepository($this->db);

        return new CollectionManager(
            new CollectionRepository($this->db),
            $itemRepo,
            new SmartPlaylistEngine($this->createMock(ItemRepository::class)),
            new SmartPlaylistRepository($this->db),
            $this->createMock(ItemRepository::class),
        );
    }

    /**
     * Seeds the member under test through raw SQL (not the repo) so its
     * sort_order/added_at are sealed values no re-add could accidentally
     * reproduce with now()-based stamps.
     */
    private function seedSealedMember(): void
    {
        $this->db->query(
            'INSERT INTO collection_items (collection_id, media_item_id, sort_order, added_at)
             VALUES (?, ?, ?, ?)',
            [self::COLLECTION_ID, self::EXISTING_ITEM, 42, self::SEADED_ADDED_AT]
        );
    }

    /** Applies the REAL migrations/005 file to the scratch database. */
    private function applyMigration005(): void
    {
        $path = dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION_005;
        $sql = file_get_contents($path);
        $this->assertIsString($sql, self::MIGRATION_005 . ' must be readable');

        foreach ($this->splitStatements($sql) as $statement) {
            $this->db->query($statement);
        }
    }

    /**
     * Splits the DDL file into executable statements. 005 is two CREATE TABLE
     * statements terminated by line-final semicolons; comments are full-line
     * `--` intros, so a line-oriented split is exact (the file grows no
     * stored-procedures — if it ever does, this helper must grow with it).
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $current .= $line . "\n";
            if (str_ends_with($trimmed, ';')) {
                $statements[] = $current;
                $current = '';
            }
        }
        $this->assertNotEmpty($statements, '005 must yield executable statements');

        return $statements;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $this->db instanceof Connection or $this->fail('scratch connection missing');
        $result = $this->db->query($sql, $params);
        $this->assertIsArray($result, 'expected a row set from: ' . $sql);

        return $result;
    }

    private function scalar(string $sql): int
    {
        $rows = $this->rows($sql);
        $this->assertCount(1, $rows);
        $first = $rows[0];

        return (int) reset($first);
    }
}
