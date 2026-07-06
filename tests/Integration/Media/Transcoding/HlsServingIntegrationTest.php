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
 * Full on-demand HLS chain against a REAL ffmpeg binary: TranscodeManager publishes
 * a complete VOD playlist up front (no background encode), then HlsController serves
 * the master + media playlists and transcodes an individual MPEG-TS segment ON
 * DEMAND when it is fetched — including a LATER segment with no earlier segment
 * produced first, proving seek-anywhere. The DB is mocked; ffmpeg/filesystem are
 * real. Skipped when ffmpeg is absent.
 */
class HlsServingIntegrationTest extends TestCase
{
    private string $segmentDir;
    private FfmpegRunner $ffmpeg;

    /** @var array<string, mixed> The job row echoed back for `SELECT * ... WHERE id`. */
    private array $insertedJob = [];

    protected function setUp(): void
    {
        $this->ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$this->ffmpeg->isAvailable()) {
            $this->markTestSkipped('ffmpeg binary not available');
        }
        $this->segmentDir = sys_get_temp_dir() . '/phlix_hls_ondemand_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->segmentDir) && is_dir($this->segmentDir)) {
            $this->rrmdir($this->segmentDir);
        }
    }

    public function testManagerPublishesVodPlaylistAndServesSegmentsOnDemand(): void
    {
        // An 8-second clip. At 2s segments the playlist has 4 entries (seg-00000..3).
        $clip = "{$this->segmentDir}/in.mkv";
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i testsrc=duration=8:size=320x240:rate=24 '
            . '-f lavfi -i sine=frequency=440:duration=8 -c:v libx264 -pix_fmt yuv420p '
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
            2
        );

        $job = $manager->ensureHlsJob('media-1', 'web');
        $this->assertFalse($job['reused']);
        // The playlist is the deliverable — the job is ready immediately.
        $this->assertSame('completed', $job['status']);
        $jobId = $job['job_id'];

        $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
        $hls = new HlsController($streamer, $manager);
        $req = new Request();

        // Master → single media playlist.
        $master = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'master.m3u8']);
        $this->assertSame(200, $master->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $master->headers['Content-Type']);
        $this->assertSame(1, preg_match('/^(media_\d+\.m3u8)$/m', $master->body, $mm));

        // Media playlist is a COMPLETE VOD list (all segments + ENDLIST) up front.
        $media = $hls->serveFile($req, ['job_id' => $jobId, 'file' => $mm[1]]);
        $this->assertSame(200, $media->statusCode);
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $media->body);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $media->body);
        // ~8s at 2s segments → 4 (or 5 if ffmpeg's real duration rounds up) entries.
        $this->assertGreaterThanOrEqual(4, preg_match_all('/^seg-\d+\.ts$/m', $media->body));

        // On-demand: the FIRST segment is transcoded when fetched.
        $seg0 = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'seg-00000.ts']);
        $this->assertSame(200, $seg0->statusCode);
        $this->assertSame('video/mp2t', $seg0->headers['Content-Type']);
        $this->assertGreaterThan(0, strlen($seg0->body));

        // Seek-anywhere: a LATER segment is produced on demand with NO earlier
        // segment encoded first — this is what the old linear encode could not do.
        $this->assertFileDoesNotExist("{$this->segmentDir}/{$jobId}/seg-00003.ts");
        $seg3 = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'seg-00003.ts']);
        $this->assertSame(200, $seg3->statusCode);
        $this->assertSame('video/mp2t', $seg3->headers['Content-Type']);
        $this->assertGreaterThan(0, strlen($seg3->body));

        // An out-of-range segment is a 404, not an endless wait.
        $missing = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'seg-00099.ts']);
        $this->assertSame(404, $missing->statusCode);
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
                if (str_contains($sql, 'INSERT INTO transcode_jobs')) {
                    // Record the job so a later SELECT can echo the fields ensureSegment()
                    // needs (hls_dir, input_path, duration, segment_seconds, segment_params).
                    $p = $params ?? [];
                    $this->insertedJob = [
                        'id' => (string) ($p[0] ?? ''),
                        'input_path' => (string) ($p[2] ?? ''),
                        'hls_dir' => (string) ($p[4] ?? ''),
                        'status' => 'completed',
                        'duration_seconds' => (int) ($p[11] ?? 0),
                        'segment_seconds' => (int) ($p[12] ?? 0),
                        'segment_params' => is_string($p[13] ?? null) ? $p[13] : null,
                    ];
                    return [];
                }
                if (str_contains($sql, 'SELECT * FROM transcode_jobs WHERE id')) {
                    return $this->insertedJob === [] ? [] : [$this->insertedJob];
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
