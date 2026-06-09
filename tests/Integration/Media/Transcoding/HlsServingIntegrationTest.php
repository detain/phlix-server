<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\EncodingHelper;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * Full server-side CMAF chain against a REAL ffmpeg binary: TranscodeManager runs
 * one encode that produces BOTH HLS and DASH, then HlsController and DashController
 * serve the master playlist, a media playlist, the MPD manifest, and the shared
 * fMP4 segment bytes — proving DASH and HLS are both wired off one transcode. The
 * DB is mocked; ffmpeg/filesystem are real. Skipped when ffmpeg is absent.
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
        $this->segmentDir = sys_get_temp_dir() . '/phlix_cmaf_serve_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->segmentDir) && is_dir($this->segmentDir)) {
            $this->rrmdir($this->segmentDir);
        }
    }

    public function testManagerProducesCmafThatHlsAndDashControllersServe(): void
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

        $manager = new TranscodeManager(
            $this->mockDb($clip),
            $this->ffmpeg,
            new EncodingHelper(),
            $this->segmentDir,
            $this->segmentDir,
            null,
            1
        );

        $job = $manager->ensureHlsJob('media-1', 'web');
        $this->assertFalse($job['reused']);
        $this->assertSame('/dash/' . $job['job_id'] . '/manifest.mpd', $job['dash_url']);
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
        $hls = new HlsController($streamer);
        $dash = new DashController($this->segmentDir);
        $req = new Request();

        // --- HLS side ---
        $master = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'master.m3u8']);
        $this->assertSame(200, $master->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $master->headers['Content-Type']);
        $this->assertStringContainsString('#EXTM3U', $master->body);
        // Master references a media playlist; fetch it and a referenced segment.
        $this->assertSame(1, preg_match('/^(media_\d+\.m3u8)$/m', $master->body, $mm));
        $media = $hls->serveFile($req, ['job_id' => $jobId, 'file' => $mm[1]]);
        $this->assertSame(200, $media->statusCode);
        $this->assertSame(1, preg_match('/^(chunk-[\w-]+\.m4s)$/m', $media->body, $cm));
        $seg = $hls->serveFile($req, ['job_id' => $jobId, 'file' => $cm[1]]);
        $this->assertSame(200, $seg->statusCode);
        $this->assertSame('video/mp4', $seg->headers['Content-Type']);
        $this->assertGreaterThan(0, strlen($seg->body));

        // --- DASH side (same job dir, real .mpd + shared .m4s) ---
        $mpd = $dash->serveFile($req, ['job_id' => $jobId, 'file' => 'manifest.mpd']);
        $this->assertSame(200, $mpd->statusCode);
        $this->assertSame('application/dash+xml', $mpd->headers['Content-Type']);
        $this->assertStringContainsString('<MPD', $mpd->body);
        // The same segment is reachable under the DASH prefix too.
        $dashSeg = $dash->serveFile($req, ['job_id' => $jobId, 'file' => $cm[1]]);
        $this->assertSame(200, $dashSeg->statusCode);
        $this->assertSame($seg->body, $dashSeg->body);
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
