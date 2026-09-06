<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Music;

use Phlix\Common\Uuid;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S443 — the Music 1062 dup-PK, reproduced against a REAL MySQL PRIMARY key.
 *
 * ## What this file adds over the unit fuzz
 *
 * {@see \Phlix\Tests\Unit\Common\UuidOrderSeedFuzzTest} proves the entropy
 * defect (a re-pinned `mt_srand()` replays `Uuid::v4()` byte-for-byte). This
 * test closes the last inference step — replayed ids actually collide on the
 * `media_items.PRIMARY` key, the exact `Duplicate entry … for key 'PRIMARY'`
 * (MySQL error 1062) the s252 suite run-1 hit on the Music scan path.
 * `MusicLibraryScanner::createMediaItem()` (`src/Media/Music/MusicLibraryScanner.php:3667`)
 * mints every music `media_items` PK from `Uuid::v4()`, so the replayed stream
 * is replayed INSERTs.
 *
 * ## The replay, stated exactly
 *
 *  1. `mt_srand(4242)` — the pin `BrowseIndexUsageTest`/`FixtureIdGeneratorTest`
 *     leave the global stream at — then mint and INSERT id A.
 *  2. `mt_srand(4242)` again — a same-seed rerun (PHPUnit seeds from the
 *     `--random-order-seed`, `TestRunner.php:36`) or a seeding test between two
 *     minting consumers — then mint and INSERT id B.
 *
 * Under the old `mt_rand()` implementation B === A, and INSERT #2 dies with
 * 1062: this test REDS deterministically. Under the CSPRNG implementation the
 * ids differ and both rows persist: GREEN, with the surviving rows read back
 * from the server rather than assumed. No skip-on-this-box loophole: MySQL
 * absence skips through the shared S126 guard; CI (and any migrated dev box)
 * runs it for real — and the S443 AC is judged on that real run.
 *
 * @see \Phlix\Tests\Unit\Common\UuidOrderSeedFuzzTest
 */
final class UuidSeedReplayPkCollisionIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** Code-resident lane token (S443). */
    public const ENTROPY_GUARD_TOKEN = 'S443ENTROPYGATEX6W1';

    /** The pin BrowseIndexUsageTest / FixtureIdGeneratorTest leave behind. */
    private const ADVERSARIAL_SEED = 4242;

    private ?Connection $db = null;

    /** @var list<string> owned media_items ids, purged in tearDown */
    private array $mediaIds = [];

    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase(
            'skipping S443 seed-replay PK-collision integration test. Runs in CI.'
        );

        $this->libraryId = Uuid::v4();
        $this->db()->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'music', '[]')",
            [$this->libraryId, 's443-entropy-' . $this->libraryId],
        );
    }

    protected function tearDown(): void
    {
        $db = $this->db;

        if ($db !== null) {
            foreach ($this->mediaIds as $id) {
                $db->query('DELETE FROM media_items WHERE id = ?', [$id]);
            }

            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }

        $this->mediaIds = [];
        $this->db = null;
        $this->libraryId = '';

        parent::tearDown();
    }

    /**
     * Two ids minted across a re-pinned seed must INSERT as two DISTINCT
     * primary keys. Under the mt_rand() implementation the second INSERT
     * raises MySQL 1062 for key 'PRIMARY' and this test reddens by name.
     */
    public function test_seed_replay_cannot_reinsert_a_minted_primary_key(): void
    {
        mt_srand(self::ADVERSARIAL_SEED);
        $first = Uuid::v4();
        $this->insertMediaItem($first);

        // Re-pin exactly the way a same-seed PHPUnit rerun (or a mid-run
        // seeding test) resets the global stream.
        mt_srand(self::ADVERSARIAL_SEED);
        $second = Uuid::v4();

        $this->assertNotSame(
            $first,
            $second,
            'Uuid::v4() replayed its first id after mt_srand(' . self::ADVERSARIAL_SEED
            . ') — the entropy source is steerable by the order seed (S443).',
        );

        $this->insertMediaItem($second); // must NOT raise 1062

        $rows = $this->db()->query(
            'SELECT id FROM media_items WHERE id IN (?, ?)',
            [$first, $second],
        );
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows, 'both replay-minted rows must exist — read back from MySQL.');
    }

    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
    }

    private function insertMediaItem(string $id): void
    {
        $this->mediaIds[] = $id;

        try {
            $this->db()->query(
                "INSERT INTO media_items (id, library_id, name, type, path, metadata_json)
                 VALUES (?, ?, ?, 'audio', ?, '{}')",
                [$id, $this->libraryId, 's443 replay ' . $id, '/s443/' . $id . '.flac'],
            );
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $this->fail(sprintf(
                    'MySQL 1062 Duplicate entry for key %s on id %s minted after re-pinning '
                    . 'mt_srand(%d) — the s252 run-1 Music red, reproduced (S443): %s',
                    "'media_items.PRIMARY'",
                    $id,
                    self::ADVERSARIAL_SEED,
                    $e->getMessage(),
                ));
            }

            throw $e;
        }
    }
}
