<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Streaming\Dash;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Streaming\Dash\DashStreamer;
use Phlex\Media\Streaming\Dash\AdaptationSet;

class DashStreamerTest extends TestCase
{
    private DashStreamer $dashStreamer;
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlex_test_dash_' . uniqid();
        mkdir($this->segmentDir, 0755, true);

        $this->dashStreamer = new DashStreamer(
            $this->segmentDir,
            'http://localhost:8096'
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupDirectory($this->segmentDir);
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob("{$dir}/*");
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->cleanupDirectory($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    public function testGenerateMasterMpd(): void
    {
        $videoSet = new AdaptationSet('video-1080', 'video', 'avc1.64001f', 1920, 1080, 5000000);
        $audioSet = new AdaptationSet('audio-en', 'audio', 'mp4a.40.2', 0, 0, 128000, 48000);

        $mpd = $this->dashStreamer->generateMasterMpd('test-job', [$videoSet, $audioSet]);

        $this->assertStringContainsString('<MPD', $mpd);
        $this->assertStringContainsString('urn:mpeg:dash:profile:isoff-live:2011', $mpd);
        $this->assertStringContainsString('<AdaptationSet', $mpd);
    }

    public function testGenerateAdaptationSetMpd(): void
    {
        $segments = [
            ['duration' => 6.0, 'url' => 'segment_1.m4s'],
            ['duration' => 6.0, 'url' => 'segment_2.m4s'],
        ];

        $mpd = $this->dashStreamer->generateAdaptationSetMpd('test-job', 0, $segments, [
            'content_type' => 'video',
            'bandwidth' => 5000000,
            'width' => 1920,
            'height' => 1080,
            'codec' => 'avc1.64001f',
        ]);

        $this->assertStringContainsString('<MPD', $mpd);
        $this->assertStringContainsString('SegmentTemplate', $mpd);
        $this->assertStringContainsString('initialization', $mpd);
        $this->assertStringContainsString('media', $mpd);
    }

    public function testGetMasterMpdUrl(): void
    {
        $url = $this->dashStreamer->getMasterMpdUrl('job-123');

        $this->assertEquals('/dash/job-123/manifest.mpd', $url);
    }

    public function testGetAdaptationSetMpdUrl(): void
    {
        $url = $this->dashStreamer->getAdaptationSetMpdUrl('job-123', 1);

        $this->assertEquals('/dash/job-123/1/manifest.mpd', $url);
    }

    public function testGetSegmentPath(): void
    {
        $path = $this->dashStreamer->getSegmentPath('job-123', 0, 5);

        $this->assertStringContainsString('job-123', $path);
        $this->assertStringContainsString('segment_0_00005', $path);
        $this->assertStringEndsWith('.m4s', $path);
    }

    public function testSaveMpd(): void
    {
        $jobId = 'test-job';
        $content = '<?xml version="1.0"?><MPD></MPD>';
        $filename = 'manifest.mpd';

        $this->dashStreamer->saveMpd($jobId, $content, $filename);

        $expectedPath = "{$this->segmentDir}/{$jobId}/{$filename}";
        $this->assertTrue(file_exists($expectedPath));
        $this->assertEquals($content, file_get_contents($expectedPath));
    }

    public function testSaveSegment(): void
    {
        $jobId = 'test-job';
        $setId = 0;
        $segmentNumber = 1;
        $content = 'segment data';

        $this->dashStreamer->saveSegment($jobId, $setId, $segmentNumber, $content);

        $path = $this->dashStreamer->getSegmentPath($jobId, $setId, $segmentNumber);
        $this->assertTrue(file_exists($path));
        $this->assertEquals($content, file_get_contents($path));
    }

    public function testCleanupJob(): void
    {
        $jobId = 'cleanup-test-job';
        $jobDir = "{$this->segmentDir}/{$jobId}";
        mkdir($jobDir, 0755, true);
        file_put_contents("{$jobDir}/manifest.mpd", 'test content');
        file_put_contents("{$jobDir}/segment_0_00001.m4s", 'segment');

        $this->assertTrue(is_dir($jobDir));

        $this->dashStreamer->cleanupJob($jobId);

        $this->assertFalse(is_dir($jobDir));
    }

    public function testGetJobDirectory(): void
    {
        $jobId = 'test-job';
        $dir = $this->dashStreamer->getJobDirectory($jobId);

        $this->assertEquals("{$this->segmentDir}/{$jobId}", $dir);
    }

    public function testGetSegmentUrl(): void
    {
        $url = $this->dashStreamer->getSegmentUrl('job-123', 0, 5);

        $this->assertEquals('/dash/job-123/0/segment_00005.m4s', $url);
    }
}
