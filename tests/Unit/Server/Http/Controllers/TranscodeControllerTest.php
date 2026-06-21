<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\TranscodeController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see TranscodeController} — the start/status endpoints that
 * front the {@see TranscodeManager} HLS job lifecycle.
 */
class TranscodeControllerTest extends TestCase
{
    public function testStartReturnsJobAndMasterUrl(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureHlsJob')
            ->with('media-1', 'web')
            ->willReturn([
                'job_id' => 'job-7',
                'status' => 'running',
                'master_url' => '/hls/job-7/master.m3u8',
                'hls_url' => '/hls/job-7/master.m3u8',
                'dash_url' => '/dash/job-7/manifest.mpd',
                'reused' => false,
                'subtitles' => [
                    ['index' => 0, 'language' => 'eng', 'label' => 'English', 'default' => true,
                        'url' => '/hls/job-7/sub-0.vtt'],
                ],
            ]);
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('job-7', $body['job_id']);
        // Streaming URLs are now signed (prefix-scoped to the job dir) so the
        // player can fetch the manifest/segments/subtitles without a Bearer header.
        $this->assertSignedUrlFor('/hls/job-7/master.m3u8', $body['master_url']);
        $this->assertSignedUrlFor('/dash/job-7/manifest.mpd', $body['dash_url']);
        $this->assertFalse($body['reused']);
        $this->assertSignedUrlFor('/hls/job-7/sub-0.vtt', $body['subtitles'][0]['url']);
        $this->assertSame('English', $body['subtitles'][0]['label']);
        $this->assertTrue($body['subtitles'][0]['default']);
    }

    public function testStartReturns400WhenMediaIdEmpty(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureHlsJob');
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => '']);
        $this->assertSame(400, $response->statusCode);
    }

    public function testStartReturns404WhenItemMissing(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureHlsJob')->willThrowException(new \InvalidArgumentException('Media item not found'));
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'missing']);
        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Media item not found', json_decode($response->body, true)['error']);
    }

    public function testStartReturns503WhenConcurrencyExhausted(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureHlsJob')
            ->willThrowException(new \RuntimeException('Maximum concurrent transcodes (4) reached'));
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);
        $this->assertSame(503, $response->statusCode);
    }

    public function testStatusReturnsReadiness(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('getJobReadiness')
            ->with('job-7')
            ->willReturn([
                'job_id' => 'job-7',
                'status' => 'running',
                'segments' => 3,
                'playlist_ready' => true,
                'progress' => 3.0,
                'subtitles' => [
                    ['index' => 0, 'language' => 'eng', 'label' => 'English', 'default' => true,
                        'url' => '/hls/job-7/sub-0.vtt'],
                ],
            ]);
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => 'job-7']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('running', $body['status']);
        $this->assertTrue($body['playlist_ready']);
        $this->assertSignedUrlFor('/hls/job-7/master.m3u8', $body['master_url']);
        $this->assertSignedUrlFor('/dash/job-7/manifest.mpd', $body['dash_url']);
        $this->assertSignedUrlFor('/hls/job-7/sub-0.vtt', $body['subtitles'][0]['url']);
    }

    /**
     * Asserts a URL is `$expectedPath` plus a valid `exp`/`sig` signature.
     */
    private function assertSignedUrlFor(string $expectedPath, string $url): void
    {
        $this->assertStringStartsWith($expectedPath . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue(
            \Phlix\Auth\SignedUrl::fromEnv()->verify(
                $expectedPath,
                (string) ($q['exp'] ?? ''),
                (string) ($q['sig'] ?? ''),
            ),
            "{$url} must carry a valid signature for {$expectedPath}",
        );
    }

    public function testStatusReturns404WhenJobUnknown(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobReadiness')->willReturn([
            'job_id' => 'nope',
            'status' => 'not_found',
            'segments' => 0,
            'playlist_ready' => false,
            'progress' => 0.0,
        ]);
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => 'nope']);
        $this->assertSame(404, $response->statusCode);
    }

    public function testStatusReturns400WhenJobIdEmpty(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('getJobReadiness');
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => '']);
        $this->assertSame(400, $response->statusCode);
    }
}
