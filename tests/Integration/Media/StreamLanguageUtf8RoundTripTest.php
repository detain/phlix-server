<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaScanner;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that a multi-byte ffprobe `tags.language` survives the write to
 * `media_streams.language` — and that the byte-wise truncation it replaced is
 * genuinely rejected by MySQL rather than merely suspected to be.
 *
 * ## Why a real server is required
 *
 * The unit test
 * {@see \Phlix\Tests\Unit\Media\Library\MediaScannerTest::testStreamLanguageTruncatesOnCharacterBoundariesNotBytes}
 * proves `MediaScanner::streamLanguage()` cuts on character boundaries. It
 * cannot prove the two things that only a utf8mb4 server decides:
 *
 *  1. that MySQL really rejects the byte-wise cut with error 1366 — the failure
 *     mode this fix exists for. {@see testByteWiseTruncationIsRejectedByMysql}
 *     asserts the rejection, so if a future schema/charset change made the bad
 *     value acceptable, this test goes red and tells us the guard is now moot.
 *  2. that the character-wise cut FITS. `VARCHAR(10)` under utf8mb4 is a
 *     CHARACTER budget, so 10 characters is exactly full — but a 10-character
 *     CJK value is 30 bytes, and only a real server can confirm that is accepted
 *     rather than truncated or rejected on length.
 *
 * ## Read-back is byte-exact
 *
 * The assertions compare with `assertSame()` against the expected string and
 * additionally compare `bin2hex()`, because a collation-driven or charset-driven
 * mangling (e.g. a latin1 client connection silently transcoding) can produce a
 * value that still looks right in a diff while differing in bytes.
 *
 * ## Both pool modes
 *
 * `DB_POOL_ENABLED` selects `PhlixMySQLConnection` (0) or
 * `PooledMySQLConnection` (1); the two open sockets at different moments and
 * lease them differently, so the connection charset is established differently.
 * This test is therefore run under BOTH values — see the S-note in the PR body.
 * Nothing here branches on the mode; {@see IntegrationDbGuard} already makes the
 * two behave identically at acquisition time.
 */
final class StreamLanguageUtf8RoundTripTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $libraryId = '';

    private string $itemId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase(
            'skipping media_streams.language UTF-8 round-trip. Runs in CI / docker.'
        );

        $this->libraryId = $this->uuid();
        $this->db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'Stream Language UTF-8 Lib', 'movie', json_encode(['/tmp/phlix-lang-utf8'])],
        );

        $this->itemId = $this->uuid();
        $this->db->query(
            'INSERT INTO media_items (id, library_id, name, type, path) VALUES (?, ?, ?, ?, ?)',
            [$this->itemId, $this->libraryId, 'Stream Language UTF-8 Item', 'movie', '/tmp/phlix-lang-utf8/x.mkv'],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE clears media_items and their media_streams rows.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    /**
     * The full scanner path: a probe carrying a multi-byte language tag is
     * summarised, persisted through the real ItemRepository, and read back
     * byte-identical.
     *
     * @dataProvider multiByteLanguageCases
     */
    public function testMultiByteLanguageSurvivesTheRoundTrip(string $tag, string $expected): void
    {
        $this->assertNotNull($this->db);
        $repo = new ItemRepository($this->db);

        $summary = MediaScanner::summarizeProbe([
            'streams' => [
                ['index' => 0, 'codec_type' => 'audio', 'codec_name' => 'aac',
                 'tags' => ['language' => $tag]],
            ],
            'format' => [],
        ]);

        $row = $summary['streams'][0];
        $this->assertSame($expected, $row['language'], 'summarizeProbe truncated on a character boundary');

        // The write that used to fail with 1366.
        $repo->addStream($this->itemId, $row);

        $stored = $this->db->query(
            'SELECT language FROM media_streams WHERE media_item_id = ?',
            [$this->itemId],
        );
        $this->assertIsArray($stored);
        $this->assertCount(1, $stored, 'exactly one stream row was written');

        $readBack = $stored[0]['language'];
        $this->assertIsString($readBack);
        $this->assertSame($expected, $readBack, 'value read back unchanged');
        $this->assertSame(
            bin2hex($expected),
            bin2hex($readBack),
            'value read back BYTE-identical (catches charset transcoding on the wire)',
        );
        $this->assertTrue(mb_check_encoding($readBack, 'UTF-8'), 'stored value is valid UTF-8');
    }

    /**
     * The negative half: the byte-wise cut this fix replaced is REJECTED by the
     * real server. Without this, the round-trip test above would still pass if
     * MySQL had quietly started accepting invalid UTF-8, and the fix would be
     * guarding nothing.
     *
     * ⚠ The try/catch here wraps only the deliberately-bad INSERT, never the
     * guard acquisition (which happens in setUp()) — see RequiresRealDatabase.
     *
     * @dataProvider multiByteLanguageCases
     */
    public function testByteWiseTruncationIsRejectedByMysql(string $tag): void
    {
        $this->assertNotNull($this->db);

        $byteCut = substr($tag, 0, 10);
        $this->assertFalse(
            mb_check_encoding($byteCut, 'UTF-8'),
            'fixture must be invalid UTF-8 for this test to mean anything',
        );

        $threw = false;
        $message = '';
        try {
            $this->db->query(
                'INSERT INTO media_streams (id, media_item_id, stream_index, stream_type, language)
                 VALUES (?, ?, ?, ?, ?)',
                [$this->uuid(), $this->itemId, 0, 'audio', $byteCut],
            );
        } catch (Throwable $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        $this->assertTrue(
            $threw,
            'MySQL must reject a byte-wise-truncated multi-byte language value; '
            . 'if this stops throwing, streamLanguage()\'s mb_substr is no longer load-bearing',
        );
        $this->assertStringContainsString(
            'Incorrect string value',
            $message,
            'rejection is the expected 1366, not some unrelated error',
        );

        // And nothing was persisted by the rejected write.
        $rows = $this->db->query(
            'SELECT COUNT(*) AS c FROM media_streams WHERE media_item_id = ?',
            [$this->itemId],
        );
        $this->assertIsArray($rows);
        $this->assertSame(0, (int) $rows[0]['c'], 'rejected INSERT left no row behind');
    }

    /**
     * Language tags whose 10-BYTE cut lands mid-character, one per UTF-8
     * sequence width. Mirrors the unit test's provider.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function multiByteLanguageCases(): array
    {
        return [
            // "Deutsch (" = 9 bytes, then Ö (C3 96) straddles bytes 10-11.
            'latin-1 supplement (2-byte)' => ['Deutsch (Österreich)', 'Deutsch (Ö'],
            // "Audio: " = 7 bytes, Р = bytes 8-9, у (D1 83) straddles 10-11.
            'cyrillic after ascii (2-byte)' => ['Audio: Русский', 'Audio: Рус'],
            // 3 CJK chars = 9 bytes, then オ (E3 82 AA) straddles bytes 10-12.
            // 10 characters here is 30 BYTES — the case that proves VARCHAR(10)
            // is a character budget, not a byte budget.
            'cjk (3-byte)' => ['日本語オーディオトラック', '日本語オーディオトラ'],
        ];
    }

    /**
     * Local UUID helper, matching the repo-wide generateUuid() pattern.
     */
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
