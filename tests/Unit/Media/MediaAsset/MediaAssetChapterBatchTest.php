<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\Markers\ChapterMarker;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Chapter thumbnails are generated in batches (one ffmpeg process per 20
 * chapters), not one process per chapter, and each chapter is judged by the
 * frame file it produced.
 */
final class MediaAssetChapterBatchTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private string $root;

    private string $chapterDir;

    /** @var list<array{0: string, 1: list<int>}> Each batch call as [input, timestamps]. */
    private array $batchCalls = [];

    /** @var list<string|null> Thumbnail path recorded by each marker INSERT. */
    private array $thumbnailsInserted = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/chapter_batch_' . uniqid('', true);
        $this->chapterDir = $this->root . '/chapters/item-1';
        mkdir($this->chapterDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return list<ChapterMarker> */
    private function chapters(int $count): array
    {
        $chapters = [];
        for ($i = 0; $i < $count; $i++) {
            $chapters[] = new ChapterMarker(
                start_seconds: $i * 60,
                end_seconds: $i * 60 + 59,
                title: 'Chapter title ' . $i,
            );
        }

        return $chapters;
    }

    /**
     * @param list<ChapterMarker> $chapters
     * @param (callable(int, list<int>): bool)|null $writeFrames Decides which
     *        frame positions the scripted ffmpeg writes; null writes them all.
     */
    private function runBatchPath(array $chapters, ?callable $writeFrames = null, bool $batchOk = true): bool
    {
        $this->thumbnailsInserted = [];

        $db = $this->createMock(Connection::class);
        $db->method('lastInsertId')->willReturn(1);
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []): array|bool {
            if (str_starts_with(trim($sql), 'INSERT')) {
                $this->thumbnailsInserted[] = is_string($params[6] ?? null) ? $params[6] : null;

                return true;
            }

            return [['id' => 1, 'media_item_id' => 'item-1', 'marker_type' => 'chapter']];
        });

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn($this->root);
        $ffmpeg->expects($this->never())->method('generateThumbnail');
        $ffmpeg->method('generateThumbnailBatch')->willReturnCallback(
            function (string $input, array $timestamps, string $outputDir) use ($writeFrames, $batchOk): bool {
                $this->batchCalls[] = [$input, array_values($timestamps)];
                $positions = array_keys(array_values($timestamps));
                foreach ($positions as $position) {
                    if ($writeFrames !== null && !$writeFrames($position, $positions)) {
                        continue;
                    }
                    file_put_contents(
                        $outputDir . '/' . FfmpegRunner::batchFrameFileName($position),
                        self::JPEG
                    );
                }

                return $batchOk;
            }
        );

        $job = new ScriptedChaptersJob($ffmpeg, $this->createMock(ItemRepository::class), $db, $chapters);
        $method = (new \ReflectionClass(MediaAssetGenerationJob::class))->getMethod('generateChapterThumbnails');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke($job, new MediaAssetJob('item-1', '/media/movie.mkv', 3600));

        return $result;
    }

    public function testFortyFiveChaptersRunInThreeProcessesAndEachIsRenamedToItsIndex(): void
    {
        $result = $this->runBatchPath($this->chapters(45));

        $this->assertTrue($result);
        $this->assertSame(
            [20, 20, 5],
            array_map(static fn(array $call): int => count($call[1]), $this->batchCalls),
            'Forty-five chapters must cost three ffmpeg processes, not forty-five.'
        );
        // The first batch's timestamps are the first 20 chapter starts, in order.
        $this->assertSame(array_map(static fn(int $i): int => $i * 60, range(0, 19)), $this->batchCalls[0][1]);

        for ($i = 0; $i < 45; $i++) {
            $this->assertFileExists($this->chapterDir . '/' . $i . '.jpg');
        }
        for ($position = 0; $position < 20; $position++) {
            $this->assertFileDoesNotExist(
                $this->chapterDir . '/' . FfmpegRunner::batchFrameFileName($position),
                'Batch frames are renamed away; none may be left behind.'
            );
        }
        $this->assertCount(45, $this->thumbnailsInserted);
        $this->assertSame($this->chapterDir . '/44.jpg', end($this->thumbnailsInserted));
    }

    public function testAChapterWithNoFrameIsSkippedWhileItsNeighboursSucceed(): void
    {
        // Position 3 (chapter 3) is unreachable: the batch writes the others and exits
        // non-zero overall. Judging per frame keeps the other four.
        $result = $this->runBatchPath(
            $this->chapters(5),
            static fn(int $position): bool => $position !== 3
        );

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->chapterDir . '/3.jpg');
        foreach ([0, 1, 2, 4] as $index) {
            $this->assertFileExists($this->chapterDir . '/' . $index . '.jpg');
        }
        $this->assertCount(4, $this->thumbnailsInserted, 'Only chapters with a frame get a marker.');
    }

    public function testAFailedBatchRecordsNoMarkersAndFailsTheJob(): void
    {
        $result = $this->runBatchPath(
            $this->chapters(3),
            static fn(): bool => false,
            false
        );

        $this->assertFalse($result);
        $this->assertSame([], $this->thumbnailsInserted);
    }

    public function testStaleFramesFromAnEarlierRunAreNotRenamedIntoAChapter(): void
    {
        // An earlier run left a frame for position 0. If this run's batch fails to
        // produce position 0, the stale frame must not be promoted to chapter 0.
        file_put_contents($this->chapterDir . '/' . FfmpegRunner::batchFrameFileName(0), 'STALE');

        $result = $this->runBatchPath($this->chapters(2), static fn(int $position): bool => $position !== 0);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->chapterDir . '/0.jpg', 'A stale frame must never become a thumbnail.');
        $this->assertFileExists($this->chapterDir . '/1.jpg');
    }
}

/**
 * The job with its chapter list supplied directly, so no media file is needed.
 */
final class ScriptedChaptersJob extends MediaAssetGenerationJob
{
    /** @param list<ChapterMarker> $chapters */
    public function __construct(
        FfmpegRunner $ffmpeg,
        ItemRepository $itemRepo,
        Connection $db,
        private readonly array $chapters,
    ) {
        parent::__construct($ffmpeg, $itemRepo, $db);
    }

    /** @return list<ChapterMarker> */
    protected function extractChapters(string $path): array
    {
        return $this->chapters;
    }
}
