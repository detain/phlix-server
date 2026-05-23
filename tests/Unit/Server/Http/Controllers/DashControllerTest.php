<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\AdaptationSet;
use Phlix\Media\Streaming\Dash\DashStreamer;
use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see DashController}.
 *
 * Covers the four handler methods now wired in Application::loadStreamingRoutes():
 *   GET /dash/{job_id}/manifest.mpd                     -> getMasterManifest
 *   GET /dash/{job_id}/{set_id}/manifest.mpd            -> getAdaptationSetManifest
 *   GET /dash/{job_id}/{set_id}/segment_{n}.m4s         -> getSegment
 *   GET /dash/{job_id}/manifest                         -> getManifest
 *
 * Uses createMock() for DashStreamer dependency following the project's
 * existing controller-test conventions.
 */
class DashControllerTest extends TestCase
{
    private DashController $controller;
    private DashStreamer $mockStreamer;

    protected function setUp(): void
    {
        $this->mockStreamer = $this->createMock(DashStreamer::class);
        $this->controller = new DashController($this->mockStreamer);
    }

    /**
     * Happy path: getMasterManifest() returns 200 with MPD manifest content.
     */
    public function testGetMasterManifestReturns200WithMpd(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('generateMasterMpd')
            ->with('job-456', $this->isType('array'))
            ->willReturn('<?xml version="1.0"?><MPD></MPD>');

        $request = new Request();
        $response = $this->controller->getMasterManifest($request, ['job_id' => 'job-456']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/dash+xml', $response->headers['Content-Type']);
        $this->assertStringContainsString('<MPD', $response->body);
    }

    /**
     * Negative: getMasterManifest() returns 400 when job_id is empty.
     */
    public function testGetMasterManifestReturns400WhenJobIdEmpty(): void
    {
        $this->mockStreamer->expects($this->never())->method('generateMasterMpd');

        $request = new Request();
        $response = $this->controller->getMasterManifest($request, ['job_id' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('job_id is required', $body['error']);
    }

    /**
     * Happy path: getAdaptationSetManifest() returns 200 with adaptation set MPD.
     */
    public function testGetAdaptationSetManifestReturns200WithMpd(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('generateAdaptationSetMpd')
            ->with('job-456', 0, $this->anything(), $this->anything())
            ->willReturn('<?xml version="1.0"?><MPD><Period></Period></MPD>');

        $request = new Request();
        $response = $this->controller->getAdaptationSetManifest($request, [
            'job_id' => 'job-456',
            'set_id' => '0',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/dash+xml', $response->headers['Content-Type']);
        $this->assertStringContainsString('<MPD', $response->body);
    }

    /**
     * Happy path: getSegment() returns 200 with segment content when file exists.
     */
    public function testGetSegmentReturns200WithContent(): void
    {
        // Create a temp file to simulate existing segment
        $tempDir = sys_get_temp_dir() . '/phlix_test_dash_' . uniqid();
        mkdir($tempDir, 0755, true);
        $segmentFile = $tempDir . '/segment_0_00005.m4s';
        file_put_contents($segmentFile, 'segment-binary-data');

        try {
            $this->mockStreamer->expects($this->once())
                ->method('getSegmentPath')
                ->with('job-456', 0, 5)
                ->willReturn($segmentFile);

            $request = new Request();
            $response = $this->controller->getSegment($request, [
                'job_id' => 'job-456',
                'set_id' => '0',
                'segment_number' => '5',
            ]);

            $this->assertSame(200, $response->statusCode);
            $this->assertSame('video/mp4', $response->headers['Content-Type']);
            $this->assertSame('segment-binary-data', $response->body);
            $this->assertSame('public, max-age=31536000', $response->headers['Cache-Control']);
            $this->assertSame('bytes', $response->headers['Accept-Ranges']);
        } finally {
            @unlink($segmentFile);
            @rmdir($tempDir);
        }
    }

    /**
     * Negative: getSegment() returns 404 when segment file does not exist.
     */
    public function testGetSegmentReturns404WhenFileNotFound(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('getSegmentPath')
            ->with('job-456', 0, 999)
            ->willReturn('/nonexistent/path/segment_0_00999.m4s');

        $request = new Request();
        $response = $this->controller->getSegment($request, [
            'job_id' => 'job-456',
            'set_id' => '0',
            'segment_number' => '999',
        ]);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Segment not found', $body['error']);
    }

    /**
     * Negative: getSegment() returns 400 when job_id is empty.
     */
    public function testGetSegmentReturns400WhenJobIdEmpty(): void
    {
        $this->mockStreamer->expects($this->never())->method('getSegmentPath');

        $request = new Request();
        $response = $this->controller->getSegment($request, [
            'job_id' => '',
            'set_id' => '0',
            'segment_number' => '5',
        ]);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('job_id is required', $body['error']);
    }

    /**
     * Happy path: getManifest() returns 200 with manifest URL in JSON.
     */
    public function testGetManifestReturns200WithManifestUrl(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('getMasterMpdUrl')
            ->with('job-456')
            ->willReturn('/dash/job-456/manifest.mpd');

        $request = new Request();
        $response = $this->controller->getManifest($request, ['job_id' => 'job-456']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('/dash/job-456/manifest.mpd', $body['manifest_url']);
        $this->assertSame('job-456', $body['job_id']);
        $this->assertSame('DASH', $body['protocol']);
    }

    /**
     * Negative: getManifest() returns 400 when job_id is empty.
     */
    public function testGetManifestReturns400WhenJobIdEmpty(): void
    {
        $this->mockStreamer->expects($this->never())->method('getMasterMpdUrl');

        $request = new Request();
        $response = $this->controller->getManifest($request, ['job_id' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('job_id is required', $body['error']);
    }
}
