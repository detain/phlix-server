<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Common\Database;

use Phlix\Common\Uuid;
use Phlix\Session\PlaybackStateDeduper;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S161 — real-MySQL proof that migrations 096/097 detect their UNIQUE index by
 * SHAPE (NON_UNIQUE = 0 + the expected column set), never by name alone.
 *
 * THE MEASURED DEFECT (S156 review finding 1). Both chain-owned index
 * migrations used to answer "is the index already here?" with a bare
 * `INDEX_NAME` match. That made each file blind to the only property it exists
 * to guarantee:
 *
 *   * a NON-UNIQUE index carrying the exact expected name made the file no-op
 *     AND record itself applied — duplicates kept surviving, the upsert could
 *     never fire, and the ledger said "done", so the chain could never retry;
 *   * an equivalent UNIQUE under a DIFFERENT name was not recognised, so the
 *     file added a second, redundant unique index.
 *
 * This file pins BOTH DIRECTIONS against a real MySQL for BOTH migrations:
 * the imposter gets replaced (never a vacuous pass), and the differently-named
 * equivalent is recognised (never a second index). The static counterparts
 * ({@see \Phlix\Tests\Unit\Common\Database\PlaybackStateUniqueKeyMigrationTest})
 * only prove the probes mention `INDEX_NAME`; only a live server proves what
 * the probes DECIDE.
 *
 * SAFETY: every scenario mutates indexes on the shared `phlix_test` tables and
 * restores the canonical shape in tearDown, after deleting its own id-scoped
 * fixture rows. The suite runs serially; {@see
 * \Phlix\Tests\Integration\Session\PlaybackStateDeduperIntegrationTest}
 * establishes the precedent for drop-and-restore on `playback_state`.
 */
final class UniqueIndexShapeGuardTest extends TestCase
{
    use RequiresRealDatabase;

    /** Survival token — also asserted to be present in migration 097 below. */
    public const SURVIVAL_TOKEN = 'S161INDEXSHAPEX7R4';

    private const PLAYBACK_TABLE = 'playback_state';
    private const PLAYBACK_NAME = 'uq_playback_state_session_media';
    private const PLAYBACK_SET = 'media_item_id,session_id';

    private const PATHHASH_TABLE = 'media_items';
    private const PATHHASH_NAME = 'idx_media_items_library_path_hash';
    private const PATHHASH_SET = 'library_id,path_hash';

    /** Distinct name, same shape — the "recognise, don't duplicate" probe. */
    private const ALIAS = 'zz_s161_shape_alias';

    private ?Connection $db = null;

    /** @var list<string> */
    private array $playbackRowIds = [];

    /** @var list<string> */
    private array $mediaItemIds = [];

    private string $libraryId = '';
    private string $userId = '';
    private string $sessionId = '';

    /** Whether each table's index was mutated and must be restored. */
    private bool $playbackMutated = false;

    private bool $pathHashMutated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S161 index-shape proofs. Runs in CI.');
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            // Own rows first — the canonical UNIQUE re-add in the restore must
            // not trip over leftovers from a scenario that failed mid-run.
            try {
                foreach ($this->playbackRowIds as $id) {
                    $db->query('DELETE FROM playback_state WHERE id = ?', [$id]);
                }
                foreach ($this->mediaItemIds as $id) {
                    $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
                }
                if ($this->sessionId !== '') {
                    $db->query('DELETE FROM sessions WHERE id = ?', [$this->sessionId]);
                }
                if ($this->userId !== '') {
                    $db->query('DELETE FROM users WHERE id = ?', [$this->userId]);
                }
                if ($this->libraryId !== '') {
                    $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
                }
            } catch (Throwable) {
                // Best effort below.
            }

            if ($this->playbackMutated) {
                $this->restoreCanonicalIndex(self::PLAYBACK_TABLE, self::PLAYBACK_NAME);
            }
            if ($this->pathHashMutated) {
                $this->restoreCanonicalIndex(self::PATHHASH_TABLE, self::PATHHASH_NAME);
            }
        }

        $this->db = null;
        $this->playbackRowIds = [];
        $this->mediaItemIds = [];
        $this->sessionId = '';
        $this->userId = '';
        $this->libraryId = '';
        $this->playbackMutated = false;
        $this->pathHashMutated = false;

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Migration 097 — playback_state
    // ------------------------------------------------------------------

    /**
     * (a) A NON-UNIQUE index carrying the exact contractual name must NOT let
     * the migration pass vacuously any more: it replaces the imposter with the
     * real UNIQUE KEY, in one atomic ALTER, and the constraint bites.
     */
    public function testMigration097ReplacesWrongShapedSameNamedIndexInsteadOfSkippingIt(): void
    {
        $this->seedPlaybackFixture();

        // Pre-condition on the mutated shape: the name exists, NON_UNIQUE = 1.
        $this->dropIndexesCovering(self::PLAYBACK_TABLE, self::PLAYBACK_SET, self::ALIAS);
        $this->playbackMutated = true;
        $this->db()->query(
            'CREATE INDEX ' . self::PLAYBACK_NAME . ' ON ' . self::PLAYBACK_TABLE
            . ' (session_id, media_item_id)'
        );
        $this->assertSame('1', $this->nonUniqueOf(self::PLAYBACK_TABLE, self::PLAYBACK_NAME));

        // The OLD name-only probe is exactly what returned true here and let the
        // file record success. The shape-aware one must see through it.
        $this->assertFalse(
            (new PlaybackStateDeduper($this->db()))->hasUniqueKey(),
            'hasUniqueKey() must report FALSE for a same-named NON-UNIQUE imposter — '
            . 'the S156 defect, in the manual finalizer',
        );

        $this->runMigration('097_playback_state_unique_key.sql');

        $this->assertSame(
            '0',
            $this->nonUniqueOf(self::PLAYBACK_TABLE, self::PLAYBACK_NAME),
            'migration 097 left the contractual name NON-UNIQUE — it skipped on the imposter instead '
            . 'of replacing it, which is the measured S156 review finding reproduced verbatim',
        );

        // Functional payoff: the constraint now actually fires. One base row,
        // then the same (session_id, media_item_id) pair on a fresh UUID row —
        // exactly what the progress upsert generates per tick.
        $item = (string) ($this->mediaItemIds[0] ?? '');
        $this->assertNotSame('', $item);
        $this->insertPlaybackRow(Uuid::v4(), $this->sessionId, $item);
        $threw = false;
        $dupId = Uuid::v4();
        try {
            $this->insertPlaybackRow($dupId, $this->sessionId, $item);
        } catch (Throwable) {
            $threw = true;
        }
        $this->arrayRemove($this->playbackRowIds, $dupId);
        $this->assertTrue(
            $threw,
            'after the replacement, a duplicate (session_id, media_item_id) INSERT must be rejected',
        );
    }

    /**
     * (b) An equivalent UNIQUE under a DIFFERENT name enforces the same
     * constraint (order-independently). The migration must recognise it and add
     * NO second, redundant index — the converse direction measured in the S156
     * review.
     */
    public function testMigration097RecognizesEquivalentUniqueUnderAnotherName(): void
    {
        $this->seedPlaybackFixture();

        $this->dropIndexesCovering(self::PLAYBACK_TABLE, self::PLAYBACK_SET, self::ALIAS);
        $this->playbackMutated = true;
        // Reversed column order on purpose: uniqueness does not depend on order.
        $this->db()->query(
            'CREATE UNIQUE INDEX ' . self::ALIAS . ' ON ' . self::PLAYBACK_TABLE
            . ' (media_item_id, session_id)'
        );

        $this->runMigration('097_playback_state_unique_key.sql');

        $this->assertSame('0', $this->nonUniqueOf(self::PLAYBACK_TABLE, self::ALIAS));
        $this->assertSame(
            1,
            $this->countUniqueIndexesCovering(self::PLAYBACK_TABLE, self::PLAYBACK_SET),
            'migration 097 added a second unique index over the same column set instead of '
            . 'recognising the existing equivalent one',
        );
    }

    /**
     * The guard branch keeps its teeth under the new probe: imposter + duplicate
     * rows → one actionable 1054 naming the finalizer, imposter untouched, no
     * half-replacement. This is the state in which the file must stay UNRECORDED
     * and retry next deploy.
     */
    public function testMigration097OnDirtyTableWithImpostorRefusesWithTheRemedyError(): void
    {
        $this->seedPlaybackFixture();

        $this->dropIndexesCovering(self::PLAYBACK_TABLE, self::PLAYBACK_SET, self::ALIAS);
        $this->playbackMutated = true;
        $this->db()->query(
            'CREATE INDEX ' . self::PLAYBACK_NAME . ' ON ' . self::PLAYBACK_TABLE . ' (session_id)'
        );
        // Two rows in one (session_id, media_item_id) group — the dirty state.
        $item = $this->seedSecondMediaItemForDup();
        $this->insertPlaybackRow(Uuid::v4(), $this->sessionId, $item);
        $this->insertPlaybackRow(Uuid::v4(), $this->sessionId, $item);

        $error = '';
        try {
            $this->runMigration('097_playback_state_unique_key.sql');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertNotSame('', $error, 'a dirty table must make 097 fail loudly, not pass vacuously');
        $this->assertStringContainsString('cleanup_090.php', $error);
        $this->assertSame(
            '1',
            $this->nonUniqueOf(self::PLAYBACK_TABLE, self::PLAYBACK_NAME),
            'the refusal must alter nothing: the imposter survives and the operator sees the remedy',
        );
    }

    /**
     * The finalizer side of S161 against a live table: addUniqueKey() on an
     * imposter REPLACES it (the old code caught 'Duplicate key name' and
     * returned false — declaring the install healthy with duplicates present).
     */
    public function testDeduperAddUniqueKeyReplacesSameNamedImpostorOnRealMysql(): void
    {
        $this->seedPlaybackFixture();

        $this->dropIndexesCovering(self::PLAYBACK_TABLE, self::PLAYBACK_SET, self::ALIAS);
        $this->playbackMutated = true;
        $this->db()->query(
            'CREATE INDEX ' . self::PLAYBACK_NAME . ' ON ' . self::PLAYBACK_TABLE
            . ' (session_id, media_item_id)'
        );

        $deduper = new PlaybackStateDeduper($this->db());
        $this->assertTrue(
            $deduper->addUniqueKey(),
            'addUniqueKey() must replace the imposter and report creation, not swallow 1061',
        );
        $this->assertTrue($deduper->hasUniqueKey());
        $this->assertSame('0', $this->nonUniqueOf(self::PLAYBACK_TABLE, self::PLAYBACK_NAME));

        // Replay: idempotent no-op.
        $this->assertFalse($deduper->addUniqueKey());
    }

    // ------------------------------------------------------------------
    // Migration 096 — media_items (the precedent 097 was modelled on)
    // ------------------------------------------------------------------

    public function testMigration096ReplacesWrongShapedSameNamedIndexInsteadOfSkippingIt(): void
    {
        $this->seedLibraryFixture();
        $this->seedMediaItem('/tmp/phlix-s161/shape-a.mkv');

        $this->dropIndexesCovering(self::PATHHASH_TABLE, self::PATHHASH_SET, self::ALIAS);
        $this->pathHashMutated = true;
        $this->db()->query(
            'CREATE INDEX ' . self::PATHHASH_NAME . ' ON ' . self::PATHHASH_TABLE
            . ' (library_id, path_hash)'
        );
        $this->assertSame('1', $this->nonUniqueOf(self::PATHHASH_TABLE, self::PATHHASH_NAME));

        $this->runMigration('096_path_hash_unique_index.sql');

        $this->assertSame(
            '0',
            $this->nonUniqueOf(self::PATHHASH_TABLE, self::PATHHASH_NAME),
            'migration 096 left the contractual name NON-UNIQUE — same S156-review blindness, same fix',
        );
    }

    public function testMigration096RecognizesEquivalentUniqueUnderAnotherName(): void
    {
        $this->seedLibraryFixture();
        $this->seedMediaItem('/tmp/phlix-s161/shape-b.mkv');

        $this->dropIndexesCovering(self::PATHHASH_TABLE, self::PATHHASH_SET, self::ALIAS);
        $this->pathHashMutated = true;
        $this->db()->query(
            'CREATE UNIQUE INDEX ' . self::ALIAS . ' ON ' . self::PATHHASH_TABLE
            . ' (path_hash, library_id)'
        );

        $this->runMigration('096_path_hash_unique_index.sql');

        $this->assertSame('0', $this->nonUniqueOf(self::PATHHASH_TABLE, self::ALIAS));
        $this->assertSame(
            1,
            $this->countUniqueIndexesCovering(self::PATHHASH_TABLE, self::PATHHASH_SET),
            'migration 096 added a second unique index over the same column set instead of '
            . 'recognising the existing equivalent one',
        );
    }

    // ------------------------------------------------------------------
    // Token survival
    // ------------------------------------------------------------------

    public function testMigration097CarriesTheSurvivalToken(): void
    {
        $sql = file_get_contents(self::migrationsDir() . '/097_playback_state_unique_key.sql');
        $this->assertIsString($sql);
        $this->assertStringContainsString(
            self::SURVIVAL_TOKEN,
            $sql,
            'the 097 header must keep carrying ' . self::SURVIVAL_TOKEN . ' — it is what proves this '
            . 'shape-awareness fix survived into the tree the migration actually ships from',
        );
    }

    // ------------------------------------------------------------------
    // Migration execution + schema helpers
    // ------------------------------------------------------------------

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 4) . '/migrations';
    }

    /**
     * Execute a migration the way MigrationRunner does: strip full-line
     * comments, run the remaining statements in order on THIS connection (the
     * pool hands back a cached singleton, so the @phlix_* session variables and
     * the reused `stmt` name behave exactly as under the runner).
     *
     * No statement in 096/097 contains a `;` inside a string literal, so the
     * plain split below reproduces the runner's quote-aware splitter for these
     * two files — and the files are under the two sibling static guards'
     * checksum discipline, not this test's.
     */
    private function runMigration(string $basename): void
    {
        $sql = file_get_contents(self::migrationsDir() . '/' . $basename);
        $this->assertIsString($sql);

        $executable = preg_replace('/^\s*(--|#).*$/m', '', $sql) ?? $sql;

        foreach (explode(';', $executable) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->db()->query($statement);
            }
        }
    }

    /** @return array<int, array<string, mixed>> STATISTICS rows for one index. */
    private function indexRows(string $table, string $name): array
    {
        $rows = $this->db()->query(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [$table, $name],
        );
        $this->assertIsArray($rows);

        return $rows;
    }

    private function nonUniqueOf(string $table, string $name): string
    {
        $rows = $this->indexRows($table, $name);
        $this->assertNotSame([], $rows, $name . ' is absent from ' . $table);

        return (string) $rows[0]['NON_UNIQUE'];
    }

    /** How many NON-UNIQUE indexes cover exactly the alphabetical column set. */
    private function countUniqueIndexesCovering(string $table, string $set): int
    {
        $rows = $this->db()->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
              GROUP BY INDEX_NAME
              HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = ?",
            [$table, $set],
        );
        $this->assertIsArray($rows);

        return count($rows);
    }

    /**
     * Drop every index whose name is the contractual one or whose columns
     * (alphabetically) equal `$set`, so the scenario starts from a known state.
     * FK-backing indexes (idx_library etc.) are untouched by construction.
     */
    private function dropIndexesCovering(string $table, string $set, string $extraName): void
    {
        $rows = $this->db()->query(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME <> \'PRIMARY\'',
            [$table],
        );
        $this->assertIsArray($rows);

        foreach ($rows as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            if ($name === '') {
                continue;
            }
            $columns = $this->indexRows($table, $name);
            $alphabetical = array_map(static fn (array $c): string => (string) $c['COLUMN_NAME'], $columns);
            sort($alphabetical);
            $covers = implode(',', $alphabetical) === $set;
            $named = $name === self::PLAYBACK_NAME
                || $name === self::PATHHASH_NAME
                || $name === $extraName;
            if ($covers || $named) {
                try {
                    $this->db()->query('ALTER TABLE ' . $table . ' DROP INDEX `' . $name . '`');
                } catch (Throwable) {
                    // Already gone, or FK-backed and exempt — the scenario's own
                    // CREATE will then hit 1061 and fail visibly, which is honest.
                }
            }
        }
    }

    /**
     * Re-establish the post-migration canonical state: exactly the contractual
     * UNIQUE index exists over the target set, under its contractual name.
     */
    private function restoreCanonicalIndex(string $table, string $name): void
    {
        $set = $table === self::PLAYBACK_TABLE ? self::PLAYBACK_SET : self::PATHHASH_SET;
        $kind = $table === self::PLAYBACK_TABLE ? 'UNIQUE KEY' : 'UNIQUE INDEX';
        $columns = $table === self::PLAYBACK_TABLE
            ? '(session_id, media_item_id)'
            : '(library_id, path_hash)';

        try {
            $this->dropIndexesCovering($table, $set, self::ALIAS);
            $this->db()->query('ALTER TABLE ' . $table . ' ADD ' . $kind . ' `' . $name . '` ' . $columns);
        } catch (Throwable) {
            // Restore is best effort; a leftover broken shape fails the NEXT
            // migration-chain health assertion in CI, not this tearDown blind.
        }
    }

    // ------------------------------------------------------------------
    // Fixtures (ids tracked for tearDown)
    // ------------------------------------------------------------------

    private function seedLibraryFixture(): void
    {
        $db = $this->db();

        $this->libraryId = Uuid::v4();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'S161 shape probe', 'movie', json_encode(['/tmp/phlix-s161'])],
        );
    }

    private function seedMediaItem(string $path): string
    {
        $id = Uuid::v4();
        $this->db()->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$id, $this->libraryId, basename($path), 'movie', $path],
        );
        $this->mediaItemIds[] = $id;

        return $id;
    }

    private function seedPlaybackFixture(): void
    {
        $db = $this->db();

        $this->seedLibraryFixture();

        $this->userId = Uuid::v4();
        $this->sessionId = Uuid::v4();
        $db->query(
            'INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)',
            [$this->userId, 's161-' . substr($this->userId, 0, 8), 's161-' . $this->userId . '@example.test', 'x'],
        );
        $db->query(
            'INSERT INTO sessions (id, user_id, device_id) VALUES (?, ?, ?)',
            [$this->sessionId, $this->userId, 's161-device-1'],
        );
        $this->seedMediaItem('/tmp/phlix-s161/probe.mkv');
    }

    private function seedSecondMediaItemForDup(): string
    {
        return $this->seedMediaItem('/tmp/phlix-s161/dup-probe.mkv');
    }

    private function insertPlaybackRow(string $rowId, string $sessionId, string $mediaItemId): void
    {
        $this->playbackRowIds[] = $rowId;
        $this->db()->query(
            "INSERT INTO playback_state
                (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, 1000, 100000, 'playing')",
            [$rowId, $sessionId, $mediaItemId],
        );
    }

    /** @param list<string> $haystack */
    private function arrayRemove(array &$haystack, string $needle): void
    {
        $key = array_search($needle, $haystack, true);
        if ($key !== false) {
            unset($haystack[$key]);
        }
    }

    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
    }
}
