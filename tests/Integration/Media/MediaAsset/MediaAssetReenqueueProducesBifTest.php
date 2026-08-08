<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\MediaAsset;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\MediaAsset\MediaAssetBackfill;
use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\MediaAssetWorker;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Trickplay\AssertsWalkableBifArchives;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S284 — from a `media_assets` job to a real `.bif`, against a real ffmpeg.
 *
 * ## The acceptance criterion this file is
 *
 * "Starting from an install with ZERO artefacts, the re-enqueue produces a real
 * `.bif` for a known item." The starting state is asserted, not assumed: the
 * trickplay tree is proved empty before anything runs, so a `.bif` found
 * afterwards cannot be a leftover.
 *
 * The chain exercised is the production one end to end below HTTP:
 *
 *   `LibraryScanWorker::runOnce()`  (claims a `media_assets` job)
 *     → `MediaAssetBackfill::reenqueueLibrary()`  (writes the file queue)
 *       → `MediaAssetWorker::runOnce()`  (drains it)
 *         → `MediaAssetGenerationJob::process()` → `FfmpegRunner` → `BifWriter`
 *
 * Only two things are doubled — the {@see ScanJobRepository} (the job row, whose
 * DB shape is covered by the real-DB sibling test) and the {@see ItemRepository}
 * (the library's rows). Every producer in between is the production class, and
 * ffmpeg really runs.
 *
 * The archive is then checked with the byte-wise assertions S275 shipped, reused
 * verbatim via {@see AssertsWalkableBifArchives} rather than re-implemented — a
 * fresh walker written for this test would have been free to be weaker exactly
 * where it matters.
 *
 * Skipped when ffmpeg is absent, matching the sibling integration tests. CI
 * installs it.
 */
final class MediaAssetReenqueueProducesBifTest extends TestCase
{
    use AssertsWalkableBifArchives;

    private string $root = '';
    private string $transcodeDir = '';
    private string $queueDir = '';
    private string $clip = '';

    protected function setUp(): void
    {
        if (!is_file('/usr/bin/ffmpeg') || !is_executable('/usr/bin/ffmpeg')) {
            $this->markTestSkipped('ffmpeg binary not available');
        }

        $this->root = sys_get_temp_dir() . '/phlix_s284_it_' . uniqid();
        $this->transcodeDir = $this->root . '/transcodes';
        $this->queueDir = $this->root . '/queue';
        mkdir($this->transcodeDir, 0755, true);
        mkdir($this->queueDir, 0755, true);

        $this->clip = $this->root . '/known-item.mp4';
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i %s -pix_fmt yuv420p %s 2>/dev/null',
            escapeshellarg('/usr/bin/ffmpeg'),
            escapeshellarg('testsrc=duration=24:size=320x180:rate=10'),
            escapeshellarg($this->clip)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, 'failed to generate the test clip');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->rrmdir($this->root);
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

    /** Every file under the trickplay tree, recursively. */
    private function trickplayArtefacts(): array
    {
        $base = $this->transcodeDir . '/trickplay';
        if (!is_dir($base)) {
            return [];
        }
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $base,
            \FilesystemIterator::SKIP_DOTS
        ));
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isFile()) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    private function queuedFileCount(): int
    {
        $files = glob($this->queueDir . '/*.job.json');

        return is_array($files) ? count($files) : 0;
    }

    public function testAMediaAssetsJobTakesALibraryFromZeroArtefactsToARealBif(): void
    {
        $itemId = 'known-item-' . substr(md5($this->root), 0, 8);

        // ── The starting state, asserted rather than assumed ──────────────────
        $this->assertSame(
            [],
            $this->trickplayArtefacts(),
            'the install must start with ZERO trickplay artefacts, which is the state '
            . 'S275 measured on every real install'
        );
        $this->assertSame(0, $this->queuedFileCount(), 'the media-asset queue must start empty');

        $ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', $this->transcodeDir);
        $store = new MediaAssetJobStore($this->queueDir);

        $items = $this->createMock(ItemRepository::class);
        $items->method('countByLibrary')->willReturn(1);
        $items->method('getByLibrary')->willReturnCallback(
            /** @return list<array<string, mixed>> */
            fn (string $lib, int $limit, int $offset): array => $offset === 0 ? [[
                'id' => $itemId,
                'path' => $this->clip,
                'metadata' => ['duration_seconds' => 24],
            ]] : []
        );

        $backfill = new MediaAssetBackfill($items, $store, $ffmpeg);

        // ── Step 1: the admin-triggered job type re-primes the queue ──────────
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('claimNext')->willReturn([
            'id' => 'job-1',
            'library_id' => 'lib-1',
            'type' => 'media_assets',
        ]);
        $completed = [];
        $jobs->method('markCompleted')->willReturnCallback(
            static function (string $jobId, array $counts) use (&$completed): void {
                $completed[] = [$jobId, $counts];
            }
        );
        $jobs->expects($this->never())->method('markFailed');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');

        $scanWorker = new LibraryScanWorker(
            $jobs,
            $libraries,
            $this->createMock(LibraryMetadataMatcher::class),
            $this->createMock(StructuredLogger::class),
            $backfill,
        );

        $this->assertTrue($scanWorker->runOnce());
        $this->assertSame(1, $this->queuedFileCount(), 'the job must have re-primed the media-asset queue');
        // Recorded in the callback, asserted out here.
        $this->assertSame([['job-1', [
            'items_found' => 1,
            'items_updated' => 1,
            'items_added' => 1,
        ]]], $completed);

        // ── Step 2: the media-asset worker drains it and ffmpeg really runs ───
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $assetWorker = new MediaAssetWorker(
            $store,
            new MediaAssetGenerationJob($ffmpeg, new ItemRepository($db), $db),
            null,
            1
        );
        $this->assertSame(1, $assetWorker->runOnce());

        // ── The artefacts ────────────────────────────────────────────────────
        $jobDir = $this->transcodeDir . '/trickplay/' . $itemId;
        $this->assertFileExists($jobDir . '/' . FfmpegRunner::SPRITE_FILENAME);
        $this->assertFileExists($jobDir . '/' . FfmpegRunner::TIMELINE_FILENAME);

        $bifPath = $jobDir . '/' . MediaAssetGenerationJob::BIF_FILENAME;
        $this->assertFileExists(
            $bifPath,
            'the re-enqueue must end in a real .bif — that is the whole point of S284'
        );

        // S275's byte-wise checks, reused: magic, header length derived from the
        // image count, terminator, and every offset resolving to a JPEG SOI.
        $payloads = $this->assertWalkableBifArchive((string) file_get_contents($bifPath));
        $this->assertGreaterThanOrEqual(2, count($payloads));

        // ── Idempotency at the artefact level ────────────────────────────────
        $this->assertSame(0, $this->queuedFileCount(), 'the worker consumed its job');

        $second = $backfill->reenqueueLibrary('lib-1');
        $this->assertSame(0, $second->enqueued, 'a second re-enqueue must not redo completed work');
        $this->assertSame(1, $second->alreadyComplete);
        $this->assertSame(0, $this->queuedFileCount());
    }
}
