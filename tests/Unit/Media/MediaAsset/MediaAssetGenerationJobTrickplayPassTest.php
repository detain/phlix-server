<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * The media-asset job consumes ONE combined trickplay pass: it records the sprite
 * markers from it and builds `thumbs.bif` from the frames it returns, then deletes
 * the intermediate JPEGs.
 */
final class MediaAssetGenerationJobTrickplayPassTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trickplay_job_' . uniqid('', true);
        mkdir($this->root, 0755, true);
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

    private function runJob(FfmpegRunner $ffmpeg, ItemRepository $repo): bool
    {
        $job = new MediaAssetGenerationJob($ffmpeg, $repo, $this->createMock(Connection::class));
        $method = (new \ReflectionClass(MediaAssetGenerationJob::class))->getMethod('generateTrickplaySprites');
        $method->setAccessible(true);

        /** @var bool $result */
        $result = $method->invoke($job, new MediaAssetJob('item-1', '/media/movie.mkv', 3600));

        return $result;
    }

    public function testCombinedPassRecordsTheSpriteAndBuildsTheBifFromItsFrames(): void
    {
        $spriteDir = $this->root . '/trickplay/item-1';
        mkdir($spriteDir, 0755, true);
        $frames = [$spriteDir . '/bifframe_00001.jpg', $spriteDir . '/bifframe_00002.jpg'];
        foreach ($frames as $frame) {
            file_put_contents($frame, self::JPEG);
        }

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn($this->root);
        // 3600 s runtime: 60 sprite thumbs, and one BIF frame per 10 s = 360.
        $ffmpeg->expects($this->once())
            ->method('generateTrickplayArtefacts')
            ->with('/media/movie.mkv', $spriteDir, 60, 360, 320, ['item_id' => 'item-1'])
            ->willReturn([
                'sprite' => $spriteDir . '/sprite.jpg',
                'timeline' => $spriteDir . '/timeline.json',
                'bif_frames' => $frames,
                'bif_interval_ms' => 10000,
                'generation' => '0123456789abcdef',
            ]);

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())
            ->method('updateMarkers')
            ->with('item-1', [
                'trickplay_sprite_path' => $spriteDir . '/sprite.jpg',
                'trickplay_timeline_path' => $spriteDir . '/timeline.json',
            ]);

        $this->assertTrue($this->runJob($ffmpeg, $repo));

        $bif = $spriteDir . '/' . BifWriter::FILENAME;
        $this->assertFileExists($bif);
        $this->assertSame(BifWriter::MAGIC, substr((string) file_get_contents($bif), 0, 8));
        $this->assertFileDoesNotExist($frames[0], 'The per-frame JPEGs are an intermediate and must be removed.');
        $this->assertFileDoesNotExist($frames[1]);
    }

    public function testAnItemHeldByAnotherPassIsSkippedWithoutGenerating(): void
    {
        // The backfill script holds the item's lock while it generates. The job must
        // skip the item rather than run a second pass into the same directory.
        $spriteDir = $this->root . '/trickplay/item-1';
        mkdir($spriteDir, 0755, true);
        $held = TrickplayArtefacts::tryLock($spriteDir);
        $this->assertNotNull($held);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn($this->root);
        $ffmpeg->expects($this->never())->method('generateTrickplayArtefacts');
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('updateMarkers');

        $this->assertTrue($this->runJob($ffmpeg, $repo));

        TrickplayArtefacts::unlock($held);
    }

    public function testTheItemLockIsReleasedWhenThePassEnds(): void
    {
        $spriteDir = $this->root . '/trickplay/item-1';

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn($this->root);
        $ffmpeg->method('generateTrickplayArtefacts')->willReturn(null);
        $repo = $this->createMock(ItemRepository::class);

        $this->assertFalse($this->runJob($ffmpeg, $repo));

        $after = TrickplayArtefacts::tryLock($spriteDir);
        $this->assertNotNull($after, 'A failed pass must release the item lock.');
        TrickplayArtefacts::unlock($after);
    }

    public function testASpriteWithNoBifFramesStillSucceedsAndWritesNoArchive(): void
    {
        $spriteDir = $this->root . '/trickplay/item-1';

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn($this->root);
        $ffmpeg->method('generateTrickplayArtefacts')->willReturn([
            'sprite' => $spriteDir . '/sprite.jpg',
            'timeline' => $spriteDir . '/timeline.json',
            'bif_frames' => [],
            'bif_interval_ms' => 0,
            'generation' => '0123456789abcdef',
        ]);

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())->method('updateMarkers');

        $this->assertTrue($this->runJob($ffmpeg, $repo), 'A BIF failure must not fail the sprite job.');
        $this->assertFileDoesNotExist($spriteDir . '/' . BifWriter::FILENAME);
        $this->assertFileExists(
            $spriteDir . '/' . TrickplayArtefacts::BIF_FAILED_MARKER_FILENAME,
            'A BIF the pass could not write is recorded, so the backfill skips the item.'
        );
    }

    public function testASuccessfulBifClearsAPriorBifFailureMarker(): void
    {
        // An earlier backfill run recorded a BIF failure for this item. The BIF this pass writes
        // replaces that failure, so the marker must go and the backfill must not keep skipping it.
        $spriteDir = $this->root . '/trickplay/item-1';
        mkdir($spriteDir, 0755, true);
        TrickplayArtefacts::markBifFailed($spriteDir, 'earlier run');
        $frames = [$spriteDir . '/bifframe_00001.jpg', $spriteDir . '/bifframe_00002.jpg'];
        foreach ($frames as $frame) {
            file_put_contents($frame, self::JPEG);
        }

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn($this->root);
        $ffmpeg->method('generateTrickplayArtefacts')->willReturn([
            'sprite' => $spriteDir . '/sprite.jpg',
            'timeline' => $spriteDir . '/timeline.json',
            'bif_frames' => $frames,
            'bif_interval_ms' => 10000,
            'generation' => '0123456789abcdef',
        ]);
        $repo = $this->createMock(ItemRepository::class);

        $this->assertTrue($this->runJob($ffmpeg, $repo));

        $this->assertFileExists($spriteDir . '/' . BifWriter::FILENAME);
        $this->assertFileDoesNotExist($spriteDir . '/' . TrickplayArtefacts::BIF_FAILED_MARKER_FILENAME);
    }

    public function testAFailedPassRecordsNothingAndFailsTheJob(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getTranscodeDir')->willReturn($this->root);
        $ffmpeg->method('generateTrickplayArtefacts')->willReturn(null);

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('updateMarkers');

        $this->assertFalse($this->runJob($ffmpeg, $repo));
    }
}
