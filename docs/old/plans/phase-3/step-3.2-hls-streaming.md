# Step 3.2: HLS Streaming

**Phase:** 3 - Streaming & Transcoding Engine  
**Plan File:** step-3.2-hls-streaming.md  
**Objective:** Implement HLS playlist generation, segment delivery, and HlsStreamer class

---

## Overview

This step implements HLS streaming functionality including playlist generation, segment management, and variant stream support.

**Prerequisites:** Step 3.1 must be completed first.

---

## Tasks

### 3.2.1 Create HlsStreamer Class

Create `src/Media/Streaming/HlsStreamer.php`:
```php
<?php

namespace Phlex\Media\Streaming;

class HlsStreamer
{
    private string $segmentDir;
    private string $baseUrl;
    private QualitySelector $qualitySelector;
    private array $variantPlaylists = [];

    public function __construct(string $segmentDir, string $baseUrl, QualitySelector $qualitySelector)
    {
        $this->segmentDir = $segmentDir;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->qualitySelector = $qualitySelector;
    }

    public function generateMasterPlaylist(string $jobId, array $qualityLevels): string
    {
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";

        foreach ($qualityLevels as $index => $level) {
            $playlist .= sprintf(
                "#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,NAME=\"%s\"\n",
                $level['bandwidth'],
                $level['width'],
                $level['height'],
                $level['name']
            );
            $playlist .= "stream_{$index}.m3u8\n";
        }

        return $playlist;
    }

    public function generateVariantPlaylist(string $jobId, int $variantIndex, array $segments, int $targetDuration): string
    {
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";
        $playlist .= "#EXT-X-TARGETDURATION:{$targetDuration}\n";
        $playlist .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $playlist .= "#EXT-X-PLAYLIST-TYPE:VOD\n";

        foreach ($segments as $i => $segment) {
            $duration = $segment['duration'] ?? $targetDuration;
            $playlist .= "#EXTINF:{$duration},\n";
            $playlist .= "segment_{$variantIndex}_" . sprintf('%03d', $i) . ".ts\n";
        }

        $playlist .= "#EXT-X-ENDLIST\n";

        return $playlist;
    }

    public function getSegmentPath(string $jobId, int $variantIndex, int $segmentNumber): string
    {
        return "{$this->segmentDir}/{$jobId}/segment_{$variantIndex}_" . sprintf('%03d', $segmentNumber) . ".ts";
    }

    public function segmentExists(string $jobId, int $variantIndex, int $segmentNumber): bool
    {
        $path = $this->getSegmentPath($jobId, $variantIndex, $segmentNumber);
        return file_exists($path);
    }

    public function getSegmentContent(string $jobId, int $variantIndex, int $segmentNumber): ?string
    {
        $path = $this->getSegmentPath($jobId, $variantIndex, $segmentNumber);
        
        if (!file_exists($path)) {
            return null;
        }

        return file_get_contents($path);
    }

    public function getPlaylistUrl(string $jobId): string
    {
        return "{$this->baseUrl}/hls/{$jobId}/playlist.m3u8";
    }

    public function getVariantPlaylistUrl(string $jobId, int $variantIndex): string
    {
        return "{$this->baseUrl}/hls/{$jobId}/stream_{$variantIndex}.m3u8";
    }

    public function getSegmentUrl(string $jobId, int $variantIndex, int $segmentNumber): string
    {
        return "{$this->segmentDir}/{$jobId}/segment_{$variantIndex}_" . sprintf('%03d', $segmentNumber) . ".ts";
    }

    public function getQualityLevelsForProfile(array $profile, array $sourceInfo): array
    {
        $maxHeight = min($profile['max_resolution'][1] ?? 1080, 2160);
        
        $levels = [
            ['index' => 0, 'name' => '1080p', 'width' => 1920, 'height' => 1080, 'bandwidth' => 5000000],
            ['index' => 1, 'name' => '720p', 'width' => 1280, 'height' => 720, 'bandwidth' => 2500000],
            ['index' => 2, 'name' => '480p', 'width' => 854, 'height' => 480, 'bandwidth' => 1000000],
        ];

        // Filter based on profile max resolution
        return array_filter($levels, function ($level) use ($maxHeight) {
            return $level['height'] <= $maxHeight;
        });
    }

    public function savePlaylist(string $jobId, string $content, string $filename): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents("{$dir}/{$filename}", $content);
    }

    public function getJobDirectory(string $jobId): string
    {
        return "{$this->segmentDir}/{$jobId}";
    }

    public function cleanupJob(string $jobId): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (is_dir($dir)) {
            $files = glob("{$dir}/*");
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function getSegmentCount(string $jobId, int $variantIndex): int
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        $pattern = "{$dir}/segment_{$variantIndex}_*.ts";
        $files = glob($pattern);
        return count($files);
    }
}
```

### 3.2.2 Create HLS Controller for API Endpoints

Create `src/Server/Http/Controllers/HlsController.php`:
```php
<?php

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Streaming\HlsStreamer;

class HlsController
{
    private HlsStreamer $hlsStreamer;

    public function __construct(HlsStreamer $hlsStreamer)
    {
        $this->hlsStreamer = $hlsStreamer;
    }

    public function getMasterPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        
        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $playlist = $this->hlsStreamer->generateMasterPlaylist($jobId, [
            ['bandwidth' => 5000000, 'width' => 1920, 'height' => 1080, 'name' => '1080p'],
            ['bandwidth' => 2500000, 'width' => 1280, 'height' => 720, 'name' => '720p'],
            ['bandwidth' => 1000000, 'width' => 854, 'height' => 480, 'name' => '480p'],
        ]);

        return (new Response())
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'public, max-age=60')
            ->text($playlist);
    }

    public function getVariantPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $variantIndex = (int)($params['variant_index'] ?? 0);
        
        // In real implementation, this would read from cache or generate on-demand
        $segments = [];
        for ($i = 0; $i < 120; $i++) {
            $segments[] = ['duration' => 6];
        }

        $playlist = $this->hlsStreamer->generateVariantPlaylist($jobId, $variantIndex, $segments, 6);

        return (new Response())
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'public, max-age=60')
            ->text($playlist);
    }

    public function getSegment(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        $variantIndex = (int)($params['variant_index'] ?? 0);
        $segmentNumber = (int)($params['segment_number'] ?? 0);

        $content = $this->hlsStreamer->getSegmentContent($jobId, $variantIndex, $segmentNumber);

        if ($content === null) {
            return (new Response())->status(404)->json(['error' => 'Segment not found']);
        }

        return (new Response())
            ->header('Content-Type', 'video/mp2t')
            ->header('Cache-Control', 'public, max-age=31536000')
            ->header('Content-Length', strlen($content))
            ->header('Accept-Ranges', 'bytes')
            ->body($content);
    }

    public function getPlaylist(Request $request, array $params): Response
    {
        $jobId = $params['job_id'] ?? '';
        
        if (empty($jobId)) {
            return (new Response())->status(400)->json(['error' => 'job_id is required']);
        }

        $playlist = $this->hlsStreamer->getPlaylistUrl($jobId);

        return (new Response())->json([
            'playlist_url' => $playlist,
            'job_id' => $jobId,
        ]);
    }
}
```

### 3.2.3 Create Unit Tests

Create `tests/unit/Media/Streaming/HlsStreamerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Streaming\HlsStreamer;
use Phlex\Media\Streaming\QualitySelector;

class HlsStreamerTest extends TestCase
{
    private HlsStreamer $hlsStreamer;

    protected function setUp(): void
    {
        $segmentDir = sys_get_temp_dir() . '/phlex_test_segments_' . uniqid();
        mkdir($segmentDir, 0755, true);
        
        $this->hlsStreamer = new HlsStreamer(
            $segmentDir,
            'http://localhost:8096',
            new QualitySelector()
        );
    }

    protected function tearDown(): void
    {
        // Cleanup is handled by tests
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
}
```

---

## Verification

After completing all tasks:

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Media/Streaming/HlsStreamerTest.php --testdox
```

2. Verify HLS functions work:
```bash
php -r "
require '/home/sites/phlex/vendor/autoload.php';
\$hls = new \Phlex\Media\Streaming\HlsStreamer('/tmp', 'http://test', new \Phlex\Media\Streaming\QualitySelector());
echo 'HlsStreamer created OK' . PHP_EOL;
\$playlist = \$hls->generateMasterPlaylist('test', [['bandwidth' => 5000000, 'width' => 1920, 'height' => 1080, 'name' => '1080p']]);
echo \$playlist;
"
```

---

## Git Workflow

```bash
cd /home/sites/phlex
git checkout -b step-3.2-hls-streaming
git add .
git commit -m "Step 3.2: Implement HLS streaming with playlist generation"
unset GITHUB_TOKEN
gh pr create --title "Step 3.2: HLS Streaming" --body "Implements HlsStreamer class with master/variant playlist generation and segment delivery."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 3.3: Transcoding Engine** (`plans/phase-3/step-3.3-transcoding-engine.md`).
