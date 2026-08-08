<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\MediaAssetWorker;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Trickplay\AssertsWalkableBifArchives;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S275 — the trickplay producer, end to end against a REAL ffmpeg.
 *
 * ## Why this file exists at all
 *
 * A runtime probe over the production media-asset pipeline found that
 * `FfmpegRunner::generateTrickplaySprites()` emitted `tile=6:10:…`, which ffmpeg
 * rejects (`Unable to parse option value "6" as image size` — the `tile` filter's
 * layout is an image size, `6x10`). The whole filtergraph failed, so the sprite
 * sheet was NEVER produced, on any item, on any install. Every unit test in the
 * tree passed throughout, because they asserted on the command string or on
 * mocked return values and nothing ever ran ffmpeg.
 *
 * So the assertions here are deliberately about **artefacts on disk with measured
 * properties**, not about return codes:
 *
 * - the sprite's pixel dimensions are asserted against the literal arithmetic of
 *   ffmpeg's `tile` layout, so a silently different grid reds;
 * - the BIF is parsed back by an index walker that resolves every offset to a
 *   JPEG SOI marker inside the same file;
 * - the whole `MediaAssetWorker` → `MediaAssetGenerationJob` chain is run, so the
 *   wiring between the producer and the archive is exercised rather than assumed.
 *
 * Skipped when ffmpeg is absent, matching the sibling integration tests. CI
 * installs it (`.github/workflows/phpunit.yml`), so this is not a permanent skip.
 */
final class TrickplayBifProductionTest extends TestCase
{
    /**
     * The byte-wise archive checks. S284 MOVED them into this trait so its own
     * re-enqueue path could reuse them verbatim instead of writing a second,
     * weaker walker; the assertions themselves are unchanged.
     */
    use AssertsWalkableBifArchives;

    private string $dir = '';
    private FfmpegRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$this->runner->isAvailable()) {
            $this->markTestSkipped('ffmpeg binary not available');
        }
        $this->dir = sys_get_temp_dir() . '/phlix_bif_it_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->rrmdir($this->dir);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeClip(string $path, int $durationSeconds = 24): void
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i %s -pix_fmt yuv420p %s 2>/dev/null',
            escapeshellarg('/usr/bin/ffmpeg'),
            escapeshellarg(sprintf('testsrc=duration=%d:size=320x180:rate=10', $durationSeconds)),
            escapeshellarg($path)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, 'failed to generate test clip');
    }

    public function testTheSpriteSheetIsActuallyProducedAtTheExpectedTileGeometry(): void
    {
        $clip = $this->dir . '/in.mp4';
        $this->makeClip($clip);
        $outDir = $this->dir . '/sprites';

        $result = $this->runner->generateTrickplaySprites($clip, $outDir, 12);

        $this->assertIsArray(
            $result,
            'generateTrickplaySprites() returned null: ffmpeg refused the filtergraph. Before S275 '
            . 'this was the permanent state of the feature — `tile=6:10` instead of `tile=6x10`.'
        );
        [$spritePath, $timelinePath] = $result;
        $this->assertFileExists($spritePath);
        $this->assertFileExists($timelinePath);

        $size = getimagesize($spritePath);
        $this->assertIsArray($size);

        // 12 thumbnails at 160x90, 6 columns => 2 rows, margin 2, padding 1.
        // Width  = 6*160 + 5*1 + 2*2 = 969
        // Height = 2*90  + 1*1 + 2*2 = 185
        // Spelled out rather than recomputed from the class's constants, so a
        // changed grid cannot make this check quietly agree with it.
        $this->assertSame(969, $size[0], 'sprite width');
        $this->assertSame(185, $size[1], 'sprite height');
    }

    public function testTheTimelineOffsetsMatchWhereTheTilesActuallyAre(): void
    {
        $clip = $this->dir . '/in.mp4';
        $this->makeClip($clip);
        $outDir = $this->dir . '/sprites';

        $result = $this->runner->generateTrickplaySprites($clip, $outDir, 12);
        $this->assertIsArray($result);
        [, $timelinePath] = $result;

        /** @var list<array{time: float|int, x: int, y: int}> $timeline */
        $timeline = json_decode((string) file_get_contents($timelinePath), true);
        $this->assertCount(12, $timeline);

        // ffmpeg's tile filter puts cell (col,row) at
        // (margin + col*(w+padding), margin + row*(h+padding)).
        // The pre-S275 formula was col*(w+margin+padding), which drifts by the
        // margin every step: it placed column 5 at 815 instead of 807.
        $this->assertSame(2, $timeline[0]['x']);
        $this->assertSame(2, $timeline[0]['y']);
        $this->assertSame(2 + 5 * 161, $timeline[5]['x'], 'last column of row 0');
        $this->assertSame(2, $timeline[5]['y']);
        $this->assertSame(2, $timeline[6]['x'], 'first column of row 1');
        $this->assertSame(2 + 91, $timeline[6]['y']);
        $this->assertNotSame(815, $timeline[5]['x'], 'the drifting pre-S275 offset must not come back');

        // 24s over 12 thumbs = 2s apart, sampled at each interval's midpoint, so
        // the last thumbnail is inside the file rather than past its end.
        $this->assertEqualsWithDelta(1.0, $timeline[0]['time'], 0.001);
        $this->assertEqualsWithDelta(23.0, $timeline[11]['time'], 0.001);
        $this->assertLessThan(24.0, $timeline[11]['time']);
    }

    public function testGenerateBifFramesProducesRealJpegsAtTheRequestedWidth(): void
    {
        $clip = $this->dir . '/in.mp4';
        $this->makeClip($clip);
        $outDir = $this->dir . '/frames';

        $extracted = $this->runner->generateBifFrames($clip, $outDir, 8, 320);

        $this->assertIsArray($extracted);
        [$paths, $intervalMs] = $extracted;

        $this->assertCount(8, $paths);
        $this->assertSame(3000, $intervalMs, '24s over 8 frames is one every 3000 ms');

        foreach ($paths as $i => $path) {
            $this->assertFileExists($path);
            $head = (string) file_get_contents($path, false, null, 0, 2);
            $this->assertSame("\xFF\xD8", $head, "frame {$i} must be a JPEG");
            $size = getimagesize($path);
            $this->assertIsArray($size);
            $this->assertSame(320, $size[0], "frame {$i} width");
        }
    }

    public function testTheWholeMediaAssetChainWritesAWalkableBifArchive(): void
    {
        $clip = $this->dir . '/in.mp4';
        $this->makeClip($clip);

        $transcodeDir = $this->dir . '/transcodes';
        mkdir($transcodeDir, 0755, true);
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', $transcodeDir);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $itemId = 'item-' . substr(md5($this->dir), 0, 12);
        $store = new MediaAssetJobStore($this->dir . '/queue');
        $store->enqueue(new MediaAssetJob($itemId, $clip, 24));

        $worker = new MediaAssetWorker(
            $store,
            new MediaAssetGenerationJob($runner, new ItemRepository($db), $db),
            null,
            1
        );

        $this->assertSame(1, $worker->runOnce());

        $jobDir = $transcodeDir . '/trickplay/' . $itemId;
        $this->assertFileExists($jobDir . '/sprite.jpg');
        $this->assertFileExists($jobDir . '/timeline.json');

        $bifPath = $jobDir . '/' . MediaAssetGenerationJob::BIF_FILENAME;
        $this->assertFileExists(
            $bifPath,
            'the media-asset worker must leave a thumbs.bif next to the sprite; without it the '
            . 'trickplay_bif_url branch can never fire in production.'
        );

        // The intermediate frames are cleaned up — only the archive is served.
        $this->assertSame([], glob($jobDir . '/bifframe_*.jpg') ?: []);

        $this->assertWalkableBifArchive((string) file_get_contents($bifPath));
    }
}
