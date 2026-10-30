<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Library;

use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Media\Library\ItemRepository;
use Phlix\Tests\Support\Database\IntegrationDbGuard;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * minors M-SERVER-21 (W-min102) — real-MySQL proof that ItemRepository::create()
 * writes `media_items` + `media_item_genres` ATOMICALLY and that
 * upsertByPath()'s race catch accepts only the genuine duplicate-key wire
 * error. A mocked Connection accepts everything and models none of the
 * vendor's error re-wrap ("SQL:<sql> <message>", SQLSTATE code, errorInfo
 * dropped) — the exact shape the narrowed detector must recognise, so this
 * proof runs against a live server.
 *
 * SAFETY: every proof runs in a throwaway `phlix_srv21_*` DATABASE against
 * fixture tables carrying exactly the columns create()/findByPath() touch —
 * never the shared schema. tearDown drops the scratch DB.
 */
final class MediaItemCreateAtomicityRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const ITEMS_DDL =
        "CREATE TABLE media_items ("
        . " id CHAR(36) PRIMARY KEY,"
        . " library_id CHAR(36),"
        . " parent_id CHAR(36) NULL,"
        . " name VARCHAR(255),"
        . " type VARCHAR(20),"
        . " path TEXT,"
        . " path_hash CHAR(40)"
        . " GENERATED ALWAYS AS (CASE WHEN type IN ('episode','movie','audio','book','track','audiobook')"
        . " AND path IS NOT NULL AND path <> '' THEN SHA1(path) ELSE NULL END) STORED,"
        . " canonical_key VARCHAR(255) NULL,"
        . " sort_title VARCHAR(255) NULL,"
        . " content_rating VARCHAR(32) NULL,"
        . " metadata_json JSON"
        . " , UNIQUE KEY idx_media_items_library_path_hash (library_id, path_hash)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    private const GENRES_DDL =
        "CREATE TABLE media_item_genres ("
        . " media_item_id CHAR(36) NOT NULL,"
        . " genre VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
        . " PRIMARY KEY (media_item_id, genre)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    private ?Connection $db = null;

    private ?Connection $admin = null;

    private string $scratchDb = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireHealthyDatabase('skipping the create()-atomicity proof. Runs in CI.');

        $host = IntegrationDbGuard::host();
        $port = IntegrationDbGuard::port();
        $user = (string) (getenv('DB_USER') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $baseDb = (string) (getenv('DB_DATABASE') ?: 'phlix_test');

        $rand = bin2hex(random_bytes(6));
        $this->scratchDb = 'phlix_srv21_' . $rand;

        $this->admin = new PhlixMySQLConnection($host, $port, $user, $password, $baseDb);
        $this->admin->query('CREATE DATABASE `' . $this->scratchDb . '`');
        $this->db = new PhlixMySQLConnection($host, $port, $user, $password, $this->scratchDb);

        $this->db->query(self::ITEMS_DDL);
        $this->db->query(self::GENRES_DDL);
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null && $this->scratchDb !== '') {
            $this->admin->query('DROP DATABASE `' . $this->scratchDb . '`');
        }
        $this->db = null;
        $this->admin = null;

        parent::tearDown();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $result = $this->db->query($sql, $params);
        return is_array($result) ? $result : [];
    }

    public function testCreateWritesRowAndGenreRowsAtomically(): void
    {
        $repo = new ItemRepository($this->db);
        $id = $repo->create([
            'library_id'    => 'lib-21',
            'name'          => 'Atomic Movie',
            'type'          => 'movie',
            'path'          => '/m/atomic.mkv',
            'metadata_json' => ['genres' => ['Drama', 'Comedy']],
        ]);

        $this->assertCount(1, $this->rows('SELECT id FROM media_items WHERE id = ?', [$id]));
        $this->assertCount(
            2,
            $this->rows('SELECT genre FROM media_item_genres WHERE media_item_id = ?', [$id]),
            'the genre leg lands in the same commit as the item row'
        );
    }

    public function testGenreLegFailureRollsBackItemRowAndPropagates(): void
    {
        // The half-failed-create scenario the review described: leg one (the
        // media_items INSERT) would autocommit, leg two dies. Pre-fix the
        // surviving row made upsertByPath()'s catch-anything-and-re-check read
        // the whole operation as a SUCCESS. Post-fix the transaction unwinds
        // and the error surfaces. Dropping the genres table forces a genuine
        // server-side failure (ERROR 1146) on the second leg.
        $this->db->query('DROP TABLE media_item_genres');

        $repo = new ItemRepository($this->db);

        try {
            $id = $repo->upsertByPath([
                'library_id'    => 'lib-21',
                'name'          => 'Half Dead',
                'type'          => 'movie',
                'path'          => '/m/half-dead.mkv',
                'metadata_json' => ['genres' => ['Drama']],
            ], true);
            $this->fail('the 1146 genre-leg failure must propagate, got id ' . $id);
        } catch (\PDOException $e) {
            $this->assertStringContainsString('media_item_genres', $e->getMessage());
        }

        $this->assertSame(
            [],
            $this->rows('SELECT id FROM media_items WHERE path = ?', ['/m/half-dead.mkv']),
            'the rolled-back create leaves NO media_items row — the pre-fix'
            . ' shape left one behind and upsertByPath laundered it into a success'
        );
    }

    public function testRealDuplicatePathViolationIsRacedReuseNotFailure(): void
    {
        // Two "concurrent" upserts of the same path with callerConfirmedAbsent
        // forced: the second INSERT hits the REAL (library_id, path_hash)
        // unique index (MySQL 1062 through the vendor re-wrap) and must be
        // recognised as a lost race — returning the winner's id, no exception,
        // exactly one row and one genre set.
        $repo = new ItemRepository($this->db);

        $first = $repo->upsertByPath([
            'library_id'    => 'lib-21',
            'name'          => 'Raced',
            'type'          => 'movie',
            'path'          => '/m/raced.mkv',
            'metadata_json' => ['genres' => ['Drama']],
        ], true);

        $second = $repo->upsertByPath([
            'library_id'    => 'lib-21',
            'name'          => 'Raced',
            'type'          => 'movie',
            'path'          => '/m/raced.mkv',
            'metadata_json' => ['genres' => ['Drama']],
        ], true);

        $this->assertSame($first, $second, 'the loser reuses the winner\'s row id');
        $this->assertCount(1, $this->rows('SELECT id FROM media_items WHERE path = ?', ['/m/raced.mkv']));
        $this->assertCount(1, $this->rows('SELECT genre FROM media_item_genres WHERE media_item_id = ?', [$first]));
    }

    public function testForeignFailureThatIsNotADuplicateSurfacesDespiteExistingRow(): void
    {
        // The narrowing law on the live wire. Seeded: a `series` row on this
        // exact path — series is NOT in migration 087's covered-type list, so its
        // path_hash is NULL and the (library_id, path_hash) unique index is
        // exempt, letting a second row share the path. Now the movie INSERT
        // leg succeeds against a dropped genres table: the SECOND leg fails
        // with ERROR 1146 (a non-duplicate). The old catch-anything-and-re-check
        // resolved that failure by findByPath()-ing the seeded series row and
        // RETURNING ITS ID — a silent, wrong success. The narrowed detector
        // must surface the 1146 instead, and the rolled-back movie attempt
        // must leave the seeded row alone.
        $repo = new ItemRepository($this->db);
        $seeded = $repo->create([
            'library_id'    => 'lib-21',
            'name'          => 'Seeded Series',
            'type'          => 'series',
            'path'          => '/m/ambush.mkv',
            'metadata_json' => ['genres' => []],
        ]);

        $this->db->query('DROP TABLE media_item_genres');

        try {
            $id = $repo->upsertByPath([
                'library_id'    => 'lib-21',
                'name'          => 'Ambushing Movie',
                'type'          => 'movie',
                'path'          => '/m/ambush.mkv',
                'metadata_json' => ['genres' => ['Comedy']],
            ], true);
            $this->fail('a non-duplicate failure must surface even with a findable row, got ' . $id);
        } catch (\PDOException $e) {
            $this->assertStringContainsString('media_item_genres', $e->getMessage());
        }

        // The movie row rolled back; only the seeded series row survives.
        $this->assertSame(
            [$seeded],
            array_column(
                $this->rows('SELECT id FROM media_items WHERE path = ? ORDER BY id', ['/m/ambush.mkv']),
                'id'
            )
        );
    }
}
