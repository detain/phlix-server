<?php

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\GuideManager;
use Phlix\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

class GuideManagerTest extends TestCase
{
    private GuideManager $manager;
    /** @var Connection&MockObject */
    private $mockDb;
    /** @var StructuredLogger&MockObject */
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Connection::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);
        $this->manager = new GuideManager($this->mockDb, $this->mockLogger);
    }

    public function testCanCreateGuideManager(): void
    {
        $this->assertInstanceOf(GuideManager::class, $this->manager);
    }

    public function testCategoryConstants(): void
    {
        $this->assertEquals('movie', GuideManager::CATEGORY_MOVIE);
        $this->assertEquals('series', GuideManager::CATEGORY_SERIES);
        $this->assertEquals('news', GuideManager::CATEGORY_NEWS);
        $this->assertEquals('sports', GuideManager::CATEGORY_SPORTS);
        $this->assertEquals('kids', GuideManager::CATEGORY_KIDS);
        $this->assertEquals('music', GuideManager::CATEGORY_MUSIC);
        $this->assertEquals('education', GuideManager::CATEGORY_EDUCATION);
        $this->assertEquals('other', GuideManager::CATEGORY_OTHER);
    }

    public function testRatingSystemConstants(): void
    {
        $this->assertEquals('tv', GuideManager::RATING_SYSTEM_TV);
        $this->assertEquals('mpaa', GuideManager::RATING_SYSTEM_MPAA);
        $this->assertEquals('acb', GuideManager::RATING_SYSTEM_ACB);
    }

    public function testGetProgramsForChannelsReturnsEmptyArrayForEmptyInput(): void
    {
        $programs = $this->manager->getProgramsForChannels([], time(), time() + 3600);
        $this->assertCount(0, $programs);
        $this->assertEmpty($programs);
    }

    public function testImportGuideDataHandlesMissingFields(): void
    {
        $result = $this->manager->importGuideData([
            ['title' => 'Missing channel_id'],
        ]);

        $this->assertArrayHasKey('imported', $result);
        $this->assertEquals(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testSetCacheTtl(): void
    {
        $this->manager->setCacheTtl(7200);
        $stats = $this->manager->getCacheStats();
        $this->assertEquals(7200, $stats['ttl']);
    }

    public function testGetCacheStatsReturnsArray(): void
    {
        $stats = $this->manager->getCacheStats();
        $this->assertCount(2, $stats);
        $this->assertArrayHasKey('entries', $stats);
        $this->assertArrayHasKey('ttl', $stats);
        $this->assertEquals(3600, $stats['ttl']); // default TTL
    }

    public function testClearCacheDoesNotThrow(): void
    {
        $this->manager->clearCache();
        // After clearing, the cache reports zero entries
        $stats = $this->manager->getCacheStats();
        $this->assertSame(0, $stats['entries']);
    }

    // ---- F-11: guide text is clamped to column widths at the boundary ----

    /**
     * Install a db->query capture and return the closure that reads the last
     * INSERT parameter list (the batch survives because the SELECT after it
     * simply finds no row in the double).
     *
     * @return \Closure(): ?array<int, mixed>
     */
    private function captureNextInsert(): \Closure
    {
        /** @var ?array<int, mixed> $last */
        $last = null;

        $this->mockDb->method('query')
            ->willReturnCallback(
                static function ($sql, $params = null) use (&$last) {
                    if (is_string($sql) && str_contains($sql, 'INSERT INTO livetv_programs')) {
                        /** @var array<int, mixed> $params */
                        $last = $params;
                    }
                    return null;
                }
            );

        return static function () use (&$last): ?array {
            return $last;
        };
    }

    public function testOverlongTitleIsTruncatedMbSafeAndBatchSurvives(): void
    {
        $insert = $this->captureNextInsert();

        // 700 CHARS of multibyte text (emoji = 4 bytes each): a raw insert
        // would 1406-abort the entire import batch.
        $longTitle = str_repeat('🎬', 700);

        $result = $this->manager->upsertProgram([
            'channel_id' => 'ch1',
            'title' => $longTitle,
            'start_time' => 1000,
            'end_time' => 2000,
        ]);

        $this->assertNull($result, 'Mocked SELECT finds no row; the point is the INSERT was issued, not fatal');

        $params = $insert();
        $this->assertNotNull($params, 'upsertProgram must still issue the INSERT');

        $title = (string) $params[2];
        $this->assertLessThanOrEqual(512, mb_strlen($title, 'UTF-8'));
        $this->assertSame(512, mb_strlen($title, 'UTF-8'));
        $this->assertStringEndsWith('…', $title, 'Truncation must be visible to readers');
        // Character-boundary safe: no replacement characters / partial sequences.
        $this->assertStringNotContainsString("\u{FFFD}", $title);
    }

    public function testOverlongDescriptionIsClampedToTextByteBudget(): void
    {
        $insert = $this->captureNextInsert();

        // 70000 BYTES with a multibyte tail straddling the cut point.
        $longDesc = str_repeat('a', 65530) . str_repeat('é', 20);

        $this->manager->upsertProgram([
            'channel_id' => 'ch1',
            'title' => 'Fine',
            'description' => $longDesc,
            'start_time' => 1000,
            'end_time' => 2000,
        ]);

        $params = $insert();
        $this->assertNotNull($params);

        $desc = (string) $params[3];
        $this->assertLessThanOrEqual(65535, strlen($desc), 'TEXT column is a BYTE budget');
        $this->assertStringEndsWith('…', $desc);
        // mb_strcut guarantees valid UTF-8 at the cut: round-trip proves it.
        $this->assertNotFalse(mb_check_encoding($desc, 'UTF-8'));
        $this->assertSame($desc, mb_convert_encoding($desc, 'UTF-8', 'UTF-8'));
    }

    public function testShortValuesPassThroughUnchanged(): void
    {
        $insert = $this->captureNextInsert();

        $this->manager->upsertProgram([
            'channel_id' => 'ch1',
            'title' => 'Normal Title',
            'description' => 'Normal desc',
            'category' => 'series',
            'rating_system' => 'tv',
            'rating' => 'PG',
            'series_id' => 's1',
            'episode_title' => 'e1',
            'series_episode' => '1x2',
            'start_time' => 1000,
            'end_time' => 2000,
        ]);

        $params = $insert();
        $this->assertNotNull($params);

        $this->assertSame('Normal Title', $params[2]);
        $this->assertSame('Normal desc', $params[3]);
        $this->assertSame('series', $params[6]);
        $this->assertSame('s1', $params[7]);
        $this->assertSame('e1', $params[9]);
        $this->assertSame('tv', $params[10]);
        $this->assertSame('PG', $params[11]);
        $this->assertSame('1x2', $params[13]);
    }

    public function testOversizedSizedColumnsAreAllClamped(): void
    {
        $insert = $this->captureNextInsert();

        $this->manager->upsertProgram([
            'channel_id' => 'ch1',
            'title' => 'ok',
            'category' => str_repeat('c', 200),
            'series_id' => str_repeat('s', 300),
            'episode_title' => str_repeat('e', 300),
            'rating_system' => str_repeat('r', 50),
            'rating' => str_repeat('g', 50),
            'series_episode' => str_repeat('n', 60),
            'start_time' => 1000,
            'end_time' => 2000,
        ]);

        $params = $insert();
        $this->assertNotNull($params);

        $this->assertSame(64, mb_strlen((string) $params[6]));
        $this->assertSame(255, mb_strlen((string) $params[7]));
        $this->assertSame(255, mb_strlen((string) $params[9]));
        $this->assertSame(16, mb_strlen((string) $params[10]));
        $this->assertSame(16, mb_strlen((string) $params[11]));
        $this->assertSame(32, mb_strlen((string) $params[13]));
    }
}
