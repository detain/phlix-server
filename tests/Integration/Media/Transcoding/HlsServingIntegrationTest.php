<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\EncodingHelper;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * Full server-side HLS chain against a REAL ffmpeg binary: TranscodeManager
 * launches the encode, then HlsController serves the produced master playlist,
 * variant playlist (with rewritten segment URIs) and the actual segment bytes —
 * proving the playlist-URI <-> segment-route round trip end to end. The DB is
 * mocked; ffmpeg/filesystem are real. Skipped when ffmpeg is absent.
 */
class HlsServingIntegrationTest extends TestCase
{
    private string $segmentDir;
    private FfmpegRunner $ffmpeg;

    protected function setUp(): void
    {
        $this->ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$this->ffmpeg->isAvailable()) {
            $this->markTestSkipped('ffmpeg binary not available');
        }
        $this->segmentDir = sys_get_temp_dir() . '/phlix_hls_serve_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->segmentDir) && is_dir($this->segmentDir)) {
            $this->rrmdir($this->segmentDir);
        }
    }

    public function testManagerProducesHlsThatControllerServes(): void
    {
        $clip = "{$this->segmentDir}/in.mkv";
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i testsrc=duration=3:size=320x240:rate=24 '
            . '-f lavfi -i sine=frequency=440:duration=3 -c:v libx264 -pix_fmt yuv420p '
            . '-c:a aac -shortest %s 2>/dev/null',
            escapeshellarg('/usr/bin/ffmpeg'),
            escapeshellarg($clip)
        );
        exec($cmd, $o, $code);
        $this->assertSame(0, $code);

        $db = $this->mockDb($clip);
        $manager = new TranscodeManager(
            $db,
            $this->ffmpeg,
            new EncodingHelper(),
            $this->segmentDir,
            $this->segmentDir,
            null,
            1
        );

        $job = $manager->ensureHlsJob('media-1', 'web');
        $this->assertFalse($job['reused']);
        $jobId = $job['job_id'];

        // Poll until the detached encode finishes.
        $deadline = microtime(true) + 30.0;
        do {
            $readiness = $manager->getJobReadiness($jobId);
            if (in_array($readiness['status'], ['completed', 'failed'], true)) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        $this->assertSame('completed', $readiness['status'], 'transcode did not complete');
        $this->assertGreaterThanOrEqual(1, $readiness['segments']);

        $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
        $controller = new HlsController($streamer, $manager);
        $req = new Request();

        // Master playlist references the variant.
        $master = $controller->getMasterPlaylist($req, ['job_id' => $jobId]);
        $this->assertSame(200, $master->statusCode);
        $this->assertStringContainsString('0/playlist.m3u8', $master->body);

        // Variant playlist: real, with canonical segment URIs.
        $variant = $controller->getVariantPlaylist($req, ['job_id' => $jobId, 'variant_index' => '0']);
        $this->assertSame(200, $variant->statusCode);
        $this->assertStringContainsString('#EXTM3U', $variant->body);
        $this->assertStringNotContainsString('segment_0_', $variant->body);

        // Extract the first segment number from the playlist and fetch it.
        $this->assertSame(1, preg_match('/^(\d+)\.ts$/m', $variant->body, $m));
        $segment = $controller->getSegment($req, [
            'job_id' => $jobId,
            'variant_index' => '0',
            'segment_number' => $m[1],
        ]);
        $this->assertSame(200, $segment->statusCode);
        $this->assertSame('video/mp2t', $segment->headers['Content-Type']);
        $this->assertGreaterThan(0, strlen($segment->body), 'segment body should not be empty');
    }

    private function mockDb(string $clipPath): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($clipPath) {
                if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                    return [];
                }
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                if (str_contains($sql, 'FROM media_items')) {
                    return [['id' => 'media-1', 'path' => $clipPath]];
                }
                if (str_contains($sql, 'SELECT * FROM transcode_jobs WHERE id')) {
                    // Readiness reads hls_dir from the row; the manager created the
                    // dir under segmentDir/{jobId} but we don't know the id here, so
                    // return null hls_dir to force the segmentDir/{jobId} fallback.
                    return [['status' => 'running', 'variant_width' => 320, 'variant_height' => 240]];
                }
                return [];
            }
        );
        return $db;
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "{$dir}/{$e}";
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
