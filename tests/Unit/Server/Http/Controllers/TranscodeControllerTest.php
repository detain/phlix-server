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
            ]);
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('job-7', $body['job_id']);
        $this->assertSame('/hls/job-7/master.m3u8', $body['master_url']);
        $this->assertSame('/dash/job-7/manifest.mpd', $body['dash_url']);
        $this->assertFalse($body['reused']);
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
            ]);
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => 'job-7']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('running', $body['status']);
        $this->assertTrue($body['playlist_ready']);
        $this->assertSame('/hls/job-7/master.m3u8', $body['master_url']);
        $this->assertSame('/dash/job-7/manifest.mpd', $body['dash_url']);
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
