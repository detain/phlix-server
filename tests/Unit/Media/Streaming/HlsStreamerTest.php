<?php

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;

class HlsStreamerTest extends TestCase
{
    private HlsStreamer $hlsStreamer;
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_test_segments_' . uniqid();
        mkdir($this->segmentDir, 0755, true);

        $this->hlsStreamer = new HlsStreamer(
            $this->segmentDir,
            'http://localhost:8096',
            new QualitySelector()
        );
    }

    protected function tearDown(): void
    {
        // Cleanup test directory
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

    public function testGenerateMasterPlaylist(): void
    {
        $levels = [
            ['bandwidth' => 5000000, 'width' => 1920, 'height' => 1080, 'name' => '1080p'],
        ];

        $playlist = $this->hlsStreamer->generateMasterPlaylist('test-job', $levels);

        $this->assertStringContainsString('#EXTM3U', $playlist);
        $this->assertStringContainsString('#EXT-X-STREAM-INF', $playlist);
        $this->assertStringContainsString('BANDWIDTH=5000000', $playlist);
    }

    public function testGenerateVariantPlaylist(): void
    {
        $segments = [
            ['duration' => 6],
            ['duration' => 6],
            ['duration' => 6],
        ];

        $playlist = $this->hlsStreamer->generateVariantPlaylist('test-job', 0, $segments, 6);

        $this->assertStringContainsString('#EXTM3U', $playlist);
        $this->assertStringContainsString('#EXTINF:6,', $playlist);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $playlist);
    }

    public function testGetSegmentPath(): void
    {
        $path = $this->hlsStreamer->getSegmentPath('job-123', 0, 5);

        $this->assertStringContainsString('job-123', $path);
        $this->assertStringContainsString('segment_0_005', $path);
        $this->assertStringEndsWith('.ts', $path);
    }

    public function testSegmentExistsReturnsFalseForNonExistent(): void
    {
        $exists = $this->hlsStreamer->segmentExists('non-existent-job', 0, 0);

        $this->assertFalse($exists);
    }

    public function testGetPlaylistUrl(): void
    {
        $url = $this->hlsStreamer->getPlaylistUrl('job-abc');

        $this->assertStringContainsString('hls/job-abc/playlist.m3u8', $url);
    }

    public function testGetVariantPlaylistUrl(): void
    {
        $url = $this->hlsStreamer->getVariantPlaylistUrl('job-abc', 1);

        $this->assertStringContainsString('hls/job-abc/stream_1.m3u8', $url);
    }

    public function testGetSegmentCountReturnsZeroForEmptyJob(): void
    {
        $count = $this->hlsStreamer->getSegmentCount('empty-job', 0);

        $this->assertEquals(0, $count);
    }

    public function testCleanupJobRemovesDirectory(): void
    {
        $jobId = 'cleanup-test-job';
        $jobDir = "{$this->segmentDir}/{$jobId}";
        mkdir($jobDir, 0755, true);
        file_put_contents("{$jobDir}/test.ts", 'test content');

        $this->assertTrue(is_dir($jobDir));

        $this->hlsStreamer->cleanupJob($jobId);

        $this->assertFalse(is_dir($jobDir));
    }

    public function testSavePlaylistCreatesDirectoryAndFile(): void
    {
        $jobId = 'save-playlist-job';
        $filename = 'playlist.m3u8';
        $content = "#EXTM3U\n#EXT-X-VERSION:3\n";

        $this->hlsStreamer->savePlaylist($jobId, $content, $filename);

        $expectedPath = "{$this->segmentDir}/{$jobId}/{$filename}";
        $this->assertTrue(file_exists($expectedPath));
        $this->assertEquals($content, file_get_contents($expectedPath));
    }

    public function testGetJobDirectory(): void
    {
        $jobId = 'test-job';
        $dir = $this->hlsStreamer->getJobDirectory($jobId);

        $this->assertEquals("{$this->segmentDir}/{$jobId}", $dir);
    }
}