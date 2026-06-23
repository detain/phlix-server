<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Subtitles\SubtitleExtractor;
use Phlix\Server\Http\Controllers\SubtitleController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SubtitleController (list tracks + extract WebVTT).
 */
class SubtitleControllerTest extends TestCase
{
    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && is_file($this->tempFile)) {
            @unlink($this->tempFile);
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
        return new SubtitleController($repo, $ffmpeg, new SubtitleExtractor());
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
}
