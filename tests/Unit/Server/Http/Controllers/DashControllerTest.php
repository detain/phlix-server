<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see DashController}.
 *
 * DASH is served from the SAME CMAF job directory as HLS (shared fMP4 segments):
 *   GET /dash/{job_id}/manifest -> getManifest (JSON mpd url)
 *   GET /dash/{job_id}/{file}   -> serveFile   (manifest.mpd / *.m4s)
 */
class DashControllerTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_dashctl_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    private function controller(): DashController
    {
        return new DashController($this->segmentDir);
    }

    private function writeJobFile(string $jobId, string $file, string $content): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("{$dir}/{$file}", $content);
    }

    /**
     * Resolves the served bytes of a response. File-backed responses (the MPD and
     * segments now stream via {@see \Phlix\Server\Http\Response::withFile()} rather
     * than buffering into `->body`), so read the window from disk; plain responses
     * (JSON errors) fall back to the buffered body.
     */
    private function bodyOf(\Phlix\Server\Http\Response $res): string
    {
        if ($res->filePath === null) {
            return $res->body;
        }
        $bytes = $res->fileLength > 0
            ? file_get_contents($res->filePath, false, null, $res->fileOffset, $res->fileLength)
            : file_get_contents($res->filePath, false, null, $res->fileOffset);
        return $bytes === false ? '' : $bytes;
    }

    public function testGetManifestReturnsMpdUrl(): void
    {
        $res = $this->controller()->getManifest(new Request(), ['job_id' => 'job-1']);
        $this->assertSame(200, $res->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('/dash/job-1/manifest.mpd', $body['manifest_url']);
        $this->assertSame('DASH', $body['protocol']);
    }

    public function testGetManifestReturns400WhenJobIdEmpty(): void
    {
        $res = $this->controller()->getManifest(new Request(), ['job_id' => '']);
        $this->assertSame(400, $res->statusCode);
    }

    public function testServesMpdWithDashContentType(): void
    {
        $this->writeJobFile('job-2', 'manifest.mpd', '<?xml version="1.0"?><MPD></MPD>');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-2', 'file' => 'manifest.mpd']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/dash+xml', $res->headers['Content-Type']);
        $this->assertSame('no-cache', $res->headers['Cache-Control']);
        $this->assertStringContainsString('<MPD>', $this->bodyOf($res));
    }

    public function testServesM4sSegment(): void
    {
        $this->writeJobFile('job-3', 'chunk-1-00002.m4s', 'AUDIOBYTES');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-3', 'file' => 'chunk-1-00002.m4s']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame('AUDIOBYTES', $this->bodyOf($res));
    }

    public function testServeFile404WhenMissing(): void
    {
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-x', 'file' => 'manifest.mpd']);
        $this->assertSame(404, $res->statusCode);
    }

    public function testServeFileRejectsTraversal(): void
    {
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-3', 'file' => '..']);
        $this->assertSame(400, $res->statusCode);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
