<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Subtitles\EmbeddedSubtitleCache;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Subtitles\SubtitleExtractor;
use Phlix\Server\Http\Controllers\SubtitleController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SubtitleController (list tracks + extract WebVTT + the persisted
 * embedded-extraction cache, minors M-SERVER-54(a)).
 */
class SubtitleControllerTest extends TestCase
{
    private string $tempFile = '';

    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/phlix_subctrl_' . uniqid('', true);
        mkdir($this->cacheDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            @system('rm -rf ' . escapeshellarg($this->cacheDir));
        }
        parent::tearDown();
    }

    /** A real, existing file path so the controller's is_file() guard passes. */
    private function realMediaPath(): string
    {
        $this->tempFile = (string) tempnam(sys_get_temp_dir(), 'phlix-media-') . '.mkv';
        file_put_contents($this->tempFile, 'not really a video');

        return $this->tempFile;
    }

    private function controller(ItemRepository $repo, FfmpegRunner $ffmpeg): SubtitleController
    {
        return new SubtitleController(
            $repo,
            $ffmpeg,
            new SubtitleExtractor(),
            new EmbeddedSubtitleCache($this->cacheDir)
        );
    }

    public function testListTracksReturns404WhenItemNotFound(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(null);

        $response = $this->controller($repo, $this->createMock(FfmpegRunner::class))
            ->listTracks(new Request(), ['id' => 'nope']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testListTracksReturnsTextTracks(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => $this->realMediaPath()]);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264'],
                ['codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'eng']],
                ['codec_type' => 'subtitle', 'codec_name' => 'hdmv_pgs_subtitle'], // bitmap → excluded
            ],
        ]);

        $response = $this->controller($repo, $ffmpeg)->listTracks(new Request(), ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{tracks: list<array<string, mixed>>} $body */
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['tracks'], 'the bitmap track is excluded');
        $this->assertSame('eng', $body['tracks'][0]['language']);
        $this->assertSame(0, $body['tracks'][0]['index']);
    }

    public function testListTracksEmptyWhenTheFileIsMissing(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => '/no/such/file.mkv']);

        $response = $this->controller($repo, $this->createMock(FfmpegRunner::class))
            ->listTracks(new Request(), ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame([], $body['tracks']);
    }

    public function testGetTrackReturnsCleanedWebVtt(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => $this->realMediaPath()]);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // Mock the extraction by writing the destination file the controller reads.
        $ffmpeg->method('extractSubtitleVtt')->willReturnCallback(
            static function (string $in, string $out, int $idx): bool {
                file_put_contents($out, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello there");

                return true;
            }
        );

        $response = $this->controller($repo, $ffmpeg)->getTrack(new Request(), ['id' => 'm1', 'index' => '0']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('text/vtt; charset=utf-8', $response->headers['Content-Type']);
        $this->assertStringContainsString('WEBVTT', $response->body);
        $this->assertStringContainsString('Hello there', $response->body);
    }

    public function testGetTrackReturns404WhenItemNotFound(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(null);

        $response = $this->controller($repo, $this->createMock(FfmpegRunner::class))
            ->getTrack(new Request(), ['id' => 'nope', 'index' => '0']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testGetTrackReturns404WhenExtractionFails(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => $this->realMediaPath()]);

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('extractSubtitleVtt')->willReturn(false); // e.g. a bitmap track

        $response = $this->controller($repo, $ffmpeg)->getTrack(new Request(), ['id' => 'm1', 'index' => '5']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testGetTrackReturns404OnNonNumericIndex(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => $this->realMediaPath()]);

        $response = $this->controller($repo, $this->createMock(FfmpegRunner::class))
            ->getTrack(new Request(), ['id' => 'm1', 'index' => 'abc']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * minors M-SERVER-54(a) core pin: the second request for the SAME
     * (item, index) of an UNCHANGED source is a static cache read — ffmpeg
     * extracts exactly once, and the body served is identical. Pre-fix, the
     * extraction ran per request (this counted 2).
     */
    public function testRepeatedTrackRequestsExtractOnceThenServeFromCache(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => $this->realMediaPath()]);

        $extracts = 0;
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('extractSubtitleVtt')->willReturnCallback(
            static function (string $in, string $out, int $idx) use (&$extracts): bool {
                $extracts++;
                file_put_contents($out, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nOnce only");

                return true;
            }
        );

        $controller = $this->controller($repo, $ffmpeg);
        $first = $controller->getTrack(new Request(), ['id' => 'm1', 'index' => '0']);
        $second = $controller->getTrack(new Request(), ['id' => 'm1', 'index' => '0']);

        $this->assertSame(200, $first->statusCode);
        $this->assertSame(200, $second->statusCode);
        $this->assertSame($first->body, $second->body);
        $this->assertStringContainsString('Once only', (string) $second->body);
        $this->assertSame(1, $extracts, 'the cached hit must not re-run ffmpeg');
        // Persisted under the fingerprinted layout, ready for the next worker.
        $this->assertCount(1, glob($this->cacheDir . '/m1/embedded-0-*.vtt') ?: []);
    }

    /**
     * Invalidation idiom: replacing the source changes the mtime/size
     * fingerprint, so the next request is a fresh generation's cold extract —
     * the stale cache can never alias the new file.
     */
    public function testReplacedSourceFileInvalidatesTheCachedGeneration(): void
    {
        $path = $this->realMediaPath();
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => $path]);

        $extracts = 0;
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('extractSubtitleVtt')->willReturnCallback(
            static function (string $in, string $out, int $idx) use (&$extracts): bool {
                $extracts++;
                file_put_contents($out, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nGeneration {$extracts}");

                return true;
            }
        );

        $controller = $this->controller($repo, $ffmpeg);
        $first = $controller->getTrack(new Request(), ['id' => 'm1', 'index' => '0']);
        $this->assertStringContainsString('Generation 1', (string) $first->body);

        // Replace the media file: new content, new size, forced-new mtime.
        file_put_contents($path, 'a genuinely different container, byte-wise');
        touch($path, time() + 120);

        $second = $controller->getTrack(new Request(), ['id' => 'm1', 'index' => '0']);
        $this->assertSame(200, $second->statusCode);
        $this->assertStringContainsString(
            'Generation 2',
            (string) $second->body,
            'stale generation must not be served'
        );
        $this->assertSame(2, $extracts);
    }

    /**
     * Failed extractions are NOT cached: a transient ffmpeg failure must not
     * ossify into a permanent 404 — the next request tries again.
     */
    public function testFailedExtractionIsNotCachedAndRetriesNextRequest(): void
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(['id' => 'm1', 'path' => $this->realMediaPath()]);

        $extracts = 0;
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('extractSubtitleVtt')->willReturnCallback(
            static function () use (&$extracts): bool {
                $extracts++;

                return false;
            }
        );

        $controller = $this->controller($repo, $ffmpeg);
        $first = $controller->getTrack(new Request(), ['id' => 'm1', 'index' => '9']);
        $this->assertSame(404, $first->statusCode);
        $second = $controller->getTrack(new Request(), ['id' => 'm1', 'index' => '9']);
        $this->assertSame(404, $second->statusCode);
        $this->assertSame(2, $extracts, 'failures stay uncached');
        $this->assertSame([], glob($this->cacheDir . '/m1/*.vtt') ?: []);
    }
}
