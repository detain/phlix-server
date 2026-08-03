<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S152 — real-schema proof that a database built by the migration chain ALONE
 * carries `UNIQUE KEY idx_media_items_library_path_hash (library_id, path_hash)`.
 *
 * CI builds its schema with `php scripts/run-migrations.php` and NOTHING else
 * (`.github/workflows/phpunit.yml:80`) — it never runs `migrations/cleanup_072.php`.
 * That is exactly the environment in which the index was missing before
 * migration 096: 072 deferred it to the manual finalizer and 087 dropped it, so
 * the path-dedupe constraint simply did not exist on any install nobody had
 * hand-finalized. This test therefore asserts against the schema the chain
 * actually produces.
 *
 * It deliberately does NOT create the index itself (unlike
 * {@see PathHashIndexUsageTest}, which pre-dates 096 and self-heals so it can
 * measure plans). If the index is absent here, the migration chain is broken —
 * that is the finding, not an environment gap. The only skips are "no MySQL"
 * and "migration 072 never applied".
 *
 */
final class PathHashUniqueIndexPresentTest extends TestCase
{
    use RequiresRealDatabase;

    private const INDEX_NAME = 'idx_media_items_library_path_hash';

    private ?Connection $db = null;

    /** @var list<string> media_items ids seeded by a test, removed in tearDown. */
    private array $seededItemIds = [];

    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the path_hash unique-index schema check. Runs in CI.');

        if (!$this->hasPathHashColumn()) {
            $this->markTestSkipped(
                'media_items.path_hash absent — migration 072 not applied against this database.',
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE takes the seeded media_items rows with it.
            try {
                $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
            } catch (Throwable) {
                // Best effort; a leftover fixture library is inert.
            }
        }

        $this->db = null;
        $this->seededItemIds = [];
        $this->libraryId = '';

        parent::tearDown();
    }

    /**
     * The index exists, is UNIQUE, and leads with `library_id` followed by
     * `path_hash`.
     *
     * Every one of those three properties is load-bearing: a non-unique index
     * constrains nothing (the data-integrity half), and any other column order
     * cannot serve `WHERE library_id = ? AND path_hash = ?` as a left prefix
     * (the performance half).
     */
    public function testMigrationChainLeavesTheUniqueIndexInPlace(): void
    {
        $rows = $this->db()->query(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            ['media_items', self::INDEX_NAME],
        );

        $this->assertIsArray($rows);
        $this->assertCount(
            2,
            $rows,
            self::INDEX_NAME . ' must exist on media_items with exactly 2 columns after '
            . '`php scripts/run-migrations.php` alone. Absent means migration 096 did not run, or a later '
            . 'migration dropped it again — the path-dedupe constraint (and the S151 `const` plan) is gone.',
        );

        $this->assertSame('0', (string) $rows[0]['NON_UNIQUE'], 'the index must be UNIQUE, not a plain key');
        $this->assertSame('library_id', (string) $rows[0]['COLUMN_NAME']);
        $this->assertSame('path_hash', (string) $rows[1]['COLUMN_NAME']);
    }

    /**
     * Functional half: the constraint actually rejects a second row for the
     * same `(library_id, path)`. `path_hash` is a STORED generated column, so
     * the duplicate is caught without the inserter ever writing the hash — which
     * is what {@see \Phlix\Media\Library\ItemRepository::upsertByPath()} relies
     * on to win a concurrent-insert race (it catches 1062 and reuses the
     * winner's row).
     */
    public function testASecondRowForTheSamePathIsRejected(): void
    {
        $db = $this->db();

        $this->libraryId = $this->uuid();
        $db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'S152 unique-index probe', 'movie', json_encode(['/tmp/phlix-s152'])],
        );

        // A non-ASCII path, so this also exercises the utf8mb4 bytes MySQL
        // hashes (the same equivalence S151's PHP-side sha1() depends on).
        $path = '/tmp/phlix-s152/Sigur Rós — Ágætis byrjun.mkv';

        $firstId = $this->uuid();
        $db->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$firstId, $this->libraryId, 'S152 keeper', 'movie', $path],
        );
        $this->seededItemIds[] = $firstId;

        $threw = false;
        $message = '';
        try {
            $db->query(
                'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
                [$this->uuid(), $this->libraryId, 'S152 duplicate', 'movie', $path],
            );
        } catch (Throwable $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        $this->assertTrue(
            $threw,
            'a duplicate (library_id, path) row was accepted — the unique index is not constraining anything',
        );
        $this->assertStringContainsString('1062', $message, 'expected MySQL 1062. Got: ' . $message);
        $this->assertStringContainsString(self::INDEX_NAME, $message, 'expected the violation to name the index');
    }

    /** The connection, guaranteed non-null (setUp skips the test otherwise). */
    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
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
            mt_rand(0, 0xffff),
        );
    }
}
