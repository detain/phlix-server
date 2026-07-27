<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Music;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S151 — real-MySQL proof that {@see MusicLibraryScanner}'s per-file existence
 * lookup resolves through `idx_media_items_library_path_hash` as a `const`,
 * single-row index lookup instead of hand-filtering the `library_id` partition.
 *
 * ## Why this test cannot be a unit test
 *
 * The whole defect lives in the QUERY PLANNER. Every in-memory double in this
 * repo (`SkipSchemaConnection`, `MusicSchemaConnection`, `statefulDbMock()`)
 * answers a `SELECT` by scanning a PHP array, so the pre-S151 statement and the
 * post-S151 statement are indistinguishable to them by construction — which is
 * exactly how mutation **M10** survived the entire unit suite during S145. The
 * claim being made here ("this uses the index") is only meaningful against a real
 * optimizer, so this test EXPLAINs the statement the production method ACTUALLY
 * emits (captured through a recording connection, never re-typed here — a copy of
 * the SQL would drift silently the moment the method changed).
 *
 * ## Measured on the production library before this change
 *
 * | form                         | EXPLAIN type | key_len | rows examined | duration          |
 * |------------------------------|--------------|---------|---------------|-------------------|
 * | `path = ?` alone             | `ref`        | 144     | **48,512**    | 0.714–0.864 s     |
 * | `path_hash = ? AND path = ?` | **`const`**  | 305     | **1**         | 0.00022–0.00052 s |
 *
 * `path` is unindexed and CANNOT be indexed (`varchar(1000)` utf8mb4 = 4,000
 * bytes, over InnoDB's 3,072-byte key limit on its own), so the planner used only
 * the composite index's leading column — `library_id`, cardinality 3 on that
 * server. At 61,122 files per music scan that was ≈13 h of pure query time.
 *
 * ## ⚠ An ASCII-only fixture would prove NOTHING here
 *
 * PHP's `sha1()` hashes the raw string bytes; MySQL's `SHA1()` hashes the
 * `path` column's utf8mb4 bytes. Those agree only while PHP hands over UTF-8 —
 * and for any pure-ASCII path they agree under either behaviour, so an ASCII
 * fixture cannot distinguish a correct implementation from a broken one. The
 * fixture below is therefore deliberately non-ASCII (`Sigur Rós`, `Björk`, and a
 * CJK path), and {@see self::assertFixtureIsGenuinelyNonAscii()} fails if a future
 * edit quietly ASCII-fies it.
 *
 * ## ⚠ `path_hash` is NULL for 7 of the 13 `type` ENUM members
 *
 * Migration 087's generating expression covers only
 * `episode, movie, audio, book, track, audiobook`. Adding the hash predicate to a
 * lookup for `series/season/album/artist/music/video/photo` returns a FAST WRONG
 * answer (it matches nothing) rather than a slow right one.
 * {@see self::testAnUncoveredTypeStillHashesToNullSoTheLandmineIsPinned()} pins
 * both halves of that so the rewrite can never be copy-pasted onto an unsafe site
 * without a red test.
 *
 * With no reachable MySQL the test self-skips, like {@see \Phlix\Tests\Integration\Media\PathHashIndexUsageTest}.
 *
 * @covers \Phlix\Media\Music\MusicLibraryScanner
 */
final class MusicTrackPathHashLookupTest extends TestCase
{
    private const PATH_HASH_INDEX = 'idx_media_items_library_path_hash';

    /** Filler rows, so a plan that hand-filters the partition reports rows > 1. */
    private const FILLER_ROWS = 200;

    private const PATH_PREFIX = '/tmp/phlix-s151-music/';

    /**
     * Non-ASCII track paths. Latin-1-range diacritics AND a CJK path, because the
     * two exercise different UTF-8 byte lengths (2-byte vs 3-byte sequences).
     *
     * @var array<string, string> label => absolute path
     */
    private const NON_ASCII_PATHS = [
        'sigur-ros' => self::PATH_PREFIX . 'Sigur Rós/Ágætis byrjun/01 Svefn-g-englar.flac',
        'bjork'     => self::PATH_PREFIX . 'Björk/Homogénic/02 Jóga.flac',
        'cjk'       => self::PATH_PREFIX . '坂本龍一/音楽図鑑/03 ぼくのかけら.flac',
    ];

    private ?Connection $db = null;

    private string $libraryId = '';

    private string $otherLibraryId = '';

    /** True only when THIS test created the unique index (so tearDown drops it). */
    private bool $createdIndex = false;

    /** @var array<string, string> label => seeded media_items UUID */
    private array $nonAsciiIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);

        if (!$this->isMysqlReachable($host, $port)) {
            $this->markTestSkipped(sprintf(
                'No MySQL on %s:%d — skipping the S151 path_hash lookup test. Runs in CI / docker-compose.',
                $host,
                $port,
            ));
        }

        try {
            ConnectionPool::init(dirname(__DIR__, 4) . '/config/database.php');
            $this->db = ConnectionPool::getConnection('mysql');
        } catch (Throwable $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        if (!$this->hasPathHashColumn()) {
            $this->markTestSkipped(
                'media_items.path_hash absent — migrations 072/087 not applied; skipping the S151 lookup test.',
            );
        }

        $db = $this->db();
        $this->libraryId = $this->uuid();
        $this->otherLibraryId = $this->uuid();
        foreach ([$this->libraryId => 'S151 Music', $this->otherLibraryId => 'S151 Music (other)'] as $id => $name) {
            $db->query(
                'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
                [$id, $name, 'music', json_encode([rtrim(self::PATH_PREFIX, '/')])],
            );
        }

        $this->seedTracks();
        $this->ensurePathHashIndex();

        // Deterministic index statistics for the cost-based optimizer.
        $db->query('ANALYZE TABLE media_items');
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->createdIndex) {
                try {
                    $this->db->query('ALTER TABLE media_items DROP INDEX ' . self::PATH_HASH_INDEX);
                } catch (Throwable) {
                    // Best-effort: a leftover index is exactly what production carries.
                }
            }
            foreach ([$this->libraryId, $this->otherLibraryId] as $id) {
                if ($id !== '') {
                    // ON DELETE CASCADE removes the seeded media_items rows.
                    $this->db->query('DELETE FROM libraries WHERE id = ?', [$id]);
                }
            }
        }
        parent::tearDown();
    }

    /**
     * ⚠ **LANDMINE 3.** PHP `sha1()` hashes raw string bytes; MySQL `SHA1()` hashes
     * the column's utf8mb4 bytes. They agree only while PHP sends UTF-8 — and for a
     * pure-ASCII path they agree either way, so this assertion is only meaningful on
     * a genuinely non-ASCII fixture. Both halves are asserted: that the fixture IS
     * non-ASCII, and that the two hashes agree on it.
     */
    public function testPhpSha1AgreesWithTheMysqlGeneratedPathHashOnNonAsciiPaths(): void
    {
        $this->assertFixtureIsGenuinelyNonAscii();

        foreach (self::NON_ASCII_PATHS as $label => $path) {
            $row = $this->db()->row(
                'SELECT path_hash FROM media_items WHERE id = ?',
                [$this->nonAsciiIds[$label]],
            );
            $this->assertIsArray($row, 'seeded non-ASCII row missing: ' . $label);

            $stored = $row['path_hash'] ?? null;
            $this->assertIsString(
                $stored,
                sprintf('path_hash must be non-NULL for a `track` row (%s) — migration 087 covers `track`', $label),
            );
            $this->assertSame(
                sha1($path),
                $stored,
                sprintf(
                    'PHP sha1() must equal the MySQL-generated path_hash for the non-ASCII path %s (%s). '
                    . 'A mismatch means PHP and MySQL are hashing different bytes, and every scanner lookup '
                    . 'for a non-ASCII path would silently miss and fork a duplicate row.',
                    $label,
                    $path,
                ),
            );
        }
    }

    /**
     * The STATEMENT THE PRODUCTION METHOD EMITS (captured, not re-typed) must
     * EXPLAIN as a `const`, single-row lookup on the composite index.
     *
     * This is the whole point of S151. Reverting
     * {@see MusicLibraryScanner::findExistingTrackMediaItemId()} to its pre-S151
     * `path = ?` form makes this assertion report `ref` / key_len 144 / rows > 1.
     */
    public function testTheScannerLookupExplainsAsAConstSingleRowIndexLookup(): void
    {
        $captured = $this->captureScannerLookup(self::NON_ASCII_PATHS['bjork'], $this->libraryId);

        $this->assertStringContainsString(
            'path_hash',
            $captured['sql'],
            'the scanner\'s per-file existence lookup must bind path_hash — without it the planner can only '
            . 'use the composite index\'s leading library_id column. Captured SQL: ' . $captured['sql'],
        );
        $this->assertStringContainsString(
            'path = ?',
            $captured['sql'],
            'the raw `path = ?` predicate must be KEPT alongside the hash: it costs nothing (the row is '
            . 'already fetched) and it makes a SHA-1 collision unable to return a foreign row. '
            . 'Captured SQL: ' . $captured['sql'],
        );

        $plan = $this->explain($captured['sql'], $captured['params']);

        $this->assertSame(
            'const',
            $this->planStr($plan, 'type'),
            'the per-file track lookup must be a `const` access path (unique index, all key parts bound), '
            . 'not a `ref` scan of the library_id partition. Plan: ' . $this->planJson($plan),
        );
        $this->assertSame(
            self::PATH_HASH_INDEX,
            $this->planStr($plan, 'key'),
            'the chosen key must be the (library_id, path_hash) index. Plan: ' . $this->planJson($plan),
        );
        $this->assertSame(
            '305',
            $this->planStr($plan, 'key_len'),
            'key_len must cover BOTH index columns (144 for library_id CHAR(36) + 161 for the nullable '
            . 'path_hash CHAR(40)). A key_len of 144 means only library_id was usable — the S151 defect. '
            . 'Plan: ' . $this->planJson($plan),
        );
        $this->assertSame(
            1,
            (int) $this->planStr($plan, 'rows'),
            'the lookup must examine exactly ONE row. Plan: ' . $this->planJson($plan),
        );
    }

    /**
     * The pre-S151 predicate, EXPLAINed side by side, so the improvement is
     * demonstrated rather than asserted — and so a future reader can see why
     * MySQL does not perform the generated-column substitution itself.
     *
     * ⚠ Generated-column index substitution fires ONLY when the WHERE clause
     * contains the column's exact generating expression. `path = '…'` never
     * triggers it, which is how a column that exists, is indexed and is ~3,500×
     * faster sat unused indefinitely.
     */
    public function testTheOldRawPathPredicateCannotUseTheHashHalfOfTheIndex(): void
    {
        $path = self::NON_ASCII_PATHS['bjork'];
        $plan = $this->explain(
            "SELECT id FROM media_items WHERE type = 'track' AND path = ? AND library_id <=> ? LIMIT 1",
            [$path, $this->libraryId],
        );

        $this->assertNotSame(
            'const',
            $this->planStr($plan, 'type'),
            'if the raw-path form were already `const`, S151 would be pointless — the fixture or the schema '
            . 'has drifted. Plan: ' . $this->planJson($plan),
        );
        $this->assertSame(
            '144',
            $this->planStr($plan, 'key_len'),
            'the raw-path form can bind only library_id (CHAR(36) utf8mb4 = 144 bytes) out of the composite '
            . 'index. Plan: ' . $this->planJson($plan),
        );
        $this->assertGreaterThan(
            1,
            (int) $this->planStr($plan, 'rows'),
            'the raw-path form hand-filters the whole library_id partition; on production that was 48,512 '
            . 'rows per file. Plan: ' . $this->planJson($plan),
        );
    }

    /**
     * Correctness, not just plan shape: the real private method must resolve every
     * non-ASCII path to the row it seeded, return null for an unknown path, and
     * stay scoped to its own library.
     *
     * The library-scoping case is the one that a hash-only rewrite would break: the
     * SAME absolute path is seeded in a second library, so a lookup that dropped
     * `library_id` would return the foreign row.
     */
    public function testFindExistingTrackMediaItemIdResolvesNonAsciiPathsAgainstRealMysql(): void
    {
        $method = new ReflectionMethod(MusicLibraryScanner::class, 'findExistingTrackMediaItemId');
        $scanner = new MusicLibraryScanner($this->db(), $this->createMock(FfmpegRunner::class));

        foreach (self::NON_ASCII_PATHS as $label => $path) {
            $this->assertSame(
                $this->nonAsciiIds[$label],
                $method->invoke($scanner, $path, $this->libraryId),
                'the scanner must resolve the seeded non-ASCII track path: ' . $label,
            );
        }

        $this->assertNull(
            $method->invoke($scanner, self::PATH_PREFIX . 'never/seeded/Björk — nope.flac', $this->libraryId),
            'an unseeded path must resolve to null, not to some other row',
        );

        // The shared path lives in the OTHER library only. A hash-only lookup would
        // return it; the library scoping must keep it invisible here.
        $shared = self::PATH_PREFIX . 'Shared/Ólafur Arnalds/shared.flac';
        $sharedId = $this->uuid();
        $this->db()->query(
            'INSERT INTO media_items (id, library_id, type, name, path) VALUES (?, ?, ?, ?, ?)',
            [$sharedId, $this->otherLibraryId, 'track', 'Shared', $shared],
        );

        $this->assertNull(
            $method->invoke($scanner, $shared, $this->libraryId),
            'a path that exists only in ANOTHER library must not be resolved for this one',
        );
        $this->assertSame(
            $sharedId,
            $method->invoke($scanner, $shared, $this->otherLibraryId),
            'the same path IS resolved for the library that owns it',
        );
    }

    /**
     * ⚠ **LANDMINE 2, pinned from both sides.**
     *
     * Every `track` row must have a non-NULL `path_hash` (otherwise the S151
     * rewrite would silently match nothing), and a type OUTSIDE migration 087's
     * covered set must still hash to NULL — which is why the rewrite must NOT be
     * copy-pasted onto lookups for `series/season/album/artist/music/video/photo`.
     *
     * If a future migration widens the generating expression, the second assertion
     * goes red and forces a deliberate re-read of every call site.
     */
    public function testAnUncoveredTypeStillHashesToNullSoTheLandmineIsPinned(): void
    {
        $nullHashTracks = $this->db()->row(
            "SELECT COUNT(*) AS n FROM media_items WHERE library_id = ? AND type = 'track' AND path_hash IS NULL",
            [$this->libraryId],
        );
        $this->assertIsArray($nullHashTracks);
        $this->assertSame(
            0,
            (int) ($nullHashTracks['n'] ?? -1),
            'no `track` row may have a NULL path_hash — the S151 lookup would match nothing for it',
        );

        // `album` is one of the 7 uncovered ENUM members.
        $albumId = $this->uuid();
        $this->db()->query(
            'INSERT INTO media_items (id, library_id, type, name, path) VALUES (?, ?, ?, ?, ?)',
            [$albumId, $this->libraryId, 'album', 'Ágætis byrjun', self::PATH_PREFIX . 'Sigur Rós/Ágætis byrjun'],
        );

        $row = $this->db()->row('SELECT path_hash FROM media_items WHERE id = ?', [$albumId]);
        $this->assertIsArray($row);
        // ⚠ NOT `$row['path_hash'] ?? …` — `??` cannot distinguish "key absent" from
        // "key present and NULL", which is the exact value under test here.
        $this->assertArrayHasKey('path_hash', $row);
        $this->assertNull(
            $row['path_hash'],
            'type `album` is OUTSIDE migration 087\'s generating expression, so its path_hash is NULL. '
            . 'Any lookup that adds `path_hash = ?` for such a type returns a FAST WRONG answer (matches '
            . 'nothing) instead of a slow right one. If this goes red, the covered-type set changed and '
            . 'every path_hash call site must be re-read.',
        );
    }

    /**
     * Run `findExistingTrackMediaItemId()` against a recording connection that
     * forwards to the real one, and hand back the SQL + params it actually issued.
     *
     * Capturing beats re-typing the statement: a copy in this file would keep
     * passing after the production method changed, which is precisely the class of
     * false green that let mutation M10 survive S145's unit suite.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function captureScannerLookup(string $path, string $libraryId): array
    {
        $real = $this->db();
        /** @var list<array{sql: string, params: list<mixed>}> $seen */
        $seen = [];

        $recorder = $this->createMock(Connection::class);
        $recorder->method('query')->willReturnCallback(
            /**
             * @param list<mixed> $params
             */
            static function (string $sql, array $params = []) use ($real, &$seen): mixed {
                $seen[] = ['sql' => $sql, 'params' => $params];

                return $real->query($sql, $params);
            }
        );

        $scanner = new MusicLibraryScanner($recorder, $this->createMock(FfmpegRunner::class));
        $method = new ReflectionMethod(MusicLibraryScanner::class, 'findExistingTrackMediaItemId');
        $method->invoke($scanner, $path, $libraryId);

        $this->assertCount(
            1,
            $seen,
            'findExistingTrackMediaItemId() must issue exactly ONE statement — it runs once per audio file, '
            . '61,122 times on the production library.',
        );

        return $seen[0];
    }

    /**
     * Guard for LANDMINE 3: the fixture must contain multi-byte characters. A path
     * whose byte length equals its character length is pure ASCII and cannot tell a
     * correct hash implementation from a broken one.
     */
    private function assertFixtureIsGenuinelyNonAscii(): void
    {
        foreach (self::NON_ASCII_PATHS as $label => $path) {
            $this->assertGreaterThan(
                mb_strlen($path, 'UTF-8'),
                strlen($path),
                sprintf(
                    'fixture path "%s" (%s) must be genuinely non-ASCII: byte length must exceed character '
                    . 'length. An ASCII path passes whether PHP and MySQL hash the same bytes or not, so it '
                    . 'proves nothing about this change.',
                    $label,
                    $path,
                ),
            );
        }
    }

    /** Seed the filler + non-ASCII track rows in as few statements as possible. */
    private function seedTracks(): void
    {
        $db = $this->db();

        $values = [];
        $params = [];
        for ($i = 0; $i < self::FILLER_ROWS; $i++) {
            $values[] = '(?, ?, ?, ?, ?)';
            array_push(
                $params,
                $this->uuid(),
                $this->libraryId,
                'track',
                sprintf('S151 Filler %03d', $i),
                self::PATH_PREFIX . sprintf('filler/%03d.flac', $i),
            );
        }
        $db->query(
            'INSERT INTO media_items (id, library_id, type, name, path) VALUES ' . implode(', ', $values),
            $params,
        );

        foreach (self::NON_ASCII_PATHS as $label => $path) {
            $id = $this->uuid();
            $this->nonAsciiIds[$label] = $id;
            $db->query(
                'INSERT INTO media_items (id, library_id, type, name, path) VALUES (?, ?, ?, ?, ?)',
                [$id, $this->libraryId, 'track', 'S151 ' . $label, $path],
            );
        }
    }

    /**
     * Ensure the `(library_id, path_hash)` unique index exists for this test.
     * Mirrors `cleanup_072.php`; the base migration deliberately does not add it.
     */
    private function ensurePathHashIndex(): void
    {
        if ($this->hasPathHashIndex()) {
            return;
        }

        try {
            $this->db()->query(
                'ALTER TABLE media_items ADD UNIQUE INDEX ' . self::PATH_HASH_INDEX . ' (library_id, path_hash)',
            );
            $this->createdIndex = true;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name')) {
                return;
            }
            $this->markTestSkipped(
                'Could not create the (library_id, path_hash) unique index (pre-existing duplicate paths?) — '
                . 'the S151 plan claim is unprovable without it: ' . $e->getMessage(),
            );
        }
    }

    private function hasPathHashIndex(): bool
    {
        try {
            $rows = $this->db()->query('SHOW INDEX FROM media_items WHERE Key_name = ?', [self::PATH_HASH_INDEX]);

            return is_array($rows) && $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function hasPathHashColumn(): bool
    {
        try {
            $rows = $this->db()->query("SHOW COLUMNS FROM media_items LIKE 'path_hash'");

            return is_array($rows) && $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run EXPLAIN and return the driving-table plan row.
     *
     * `Connection::query()` only returns rows when the statement's leading keyword
     * is `select`/`show`, so `EXPLAIN` must go through `Connection::row()`.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    private function explain(string $sql, array $params): array
    {
        $row = $this->db()->row('EXPLAIN ' . $sql, $params);
        $this->assertIsArray($row, 'EXPLAIN returned no plan row for: ' . $sql);

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function planStr(array $plan, string $key): string
    {
        $value = $plan[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function planJson(array $plan): string
    {
        $json = json_encode($plan, JSON_UNESCAPED_UNICODE);

        return $json === false ? '<unencodable plan>' : $json;
    }

    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
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
