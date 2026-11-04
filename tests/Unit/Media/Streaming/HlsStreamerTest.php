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
        $files = glob("{$dir}/*") ?: [];
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
        $path = $this->hlsStreamer->getSegmentPath('4535cc6a-0a25-4d69-a0f2-6fdc6804f749', 0, 5);

        $this->assertStringContainsString('4535cc6a-0a25-4d69-a0f2-6fdc6804f749', $path);
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
        $url = $this->hlsStreamer->getPlaylistUrl('25521eaa-1649-4e23-9414-786bd11bca5d');

        $this->assertStringContainsString('hls/25521eaa-1649-4e23-9414-786bd11bca5d/playlist.m3u8', $url);
    }

    public function testGetVariantPlaylistUrl(): void
    {
        $url = $this->hlsStreamer->getVariantPlaylistUrl('25521eaa-1649-4e23-9414-786bd11bca5d', 1);

        $this->assertStringContainsString('hls/25521eaa-1649-4e23-9414-786bd11bca5d/stream_1.m3u8', $url);
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
        $jobId = 'be04fd51-bd8e-4abb-885e-6048c18fcf48';
        $dir = $this->hlsStreamer->getJobDirectory($jobId);

        $this->assertEquals("{$this->segmentDir}/{$jobId}", $dir);
    }

    /**
     * Fail-loud shape law (2026-11-03 review server__secaudit, Critical): the
     * raw interpolation is now only callable with a canonical v4 id. Wire
     * callers gate upstream with the 400; this arm exists so a FUTURE internal
     * caller cannot silently re-inherit the traversal hole.
     */
    public function testGetJobDirectoryRefusesNonUuidShapes(): void
    {
        foreach (['..', '../..', '../../etc/passwd', 'job-1', ''] as $shape) {
            try {
                $this->hlsStreamer->getJobDirectory($shape);
                $this->fail('getJobDirectory accepted a non-UUID id: ' . $shape);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('canonical UUID', $e->getMessage());
            }
        }
    }

    public function testGetSegmentDirectoryExposesTheTrustedBase(): void
    {
        $this->assertSame($this->segmentDir, $this->hlsStreamer->getSegmentDirectory());
    }
}
