# Step 3.3: Transcoding Engine

**Phase:** 3 - Streaming & Transcoding Engine  
**Plan File:** step-3.3-transcoding-engine.md  
**Objective:** Implement FFmpeg integration, EncodingHelper, and TranscodeManager

---

## Overview

This step implements the transcoding engine with FFmpeg integration for converting media files and generating HLS segments.

**Prerequisites:** Step 3.2 must be completed first.

---

## Tasks

### 3.3.1 Create FfmpegRunner Class

Create `src/Media/Transcoding/FfmpegRunner.php`:
```php
<?php

namespace Phlex\Media\Transcoding;

class FfmpegRunner
{
    private string $ffmpegPath;
    private string $ffprobePath;
    private string $transcodeDir;
    private StructuredLogger $logger;

    public function __construct(
        string $ffmpegPath = '/usr/bin/ffmpeg',
        string $ffprobePath = '/usr/bin/ffprobe',
        string $transcodeDir = '/var/transcodes'
    ) {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
        $this->transcodeDir = $transcodeDir;
        $this->logger = LoggerFactory::get(LogChannels::TRANSCODING);
    }

    public function probe(string $inputPath): ?array
    {
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            escapeshellarg($this->ffprobePath),
            escapeshellarg($inputPath)
        );

        $output = shell_exec($cmd);
        if (!$output) {
            return null;
        }

        $data = json_decode($output, true);
        return is_array($data) ? $data : null;
    }

    public function transcode(string $inputPath, string $outputPath, array $params): bool
    {
        $cmd = $this->buildTranscodeCommand($inputPath, $outputPath, $params);
        
        $this->logger->debug('Starting transcode', ['command' => $cmd]);
        
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        
        if (!is_resource($process)) {
            $this->logger->error('Failed to start transcode process');
            return false;
        }

        // Close stdin
        fclose($pipes[0]);
        
        // Read output (could be async in real implementation)
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        
        $exitCode = proc_close($process);
        
        if ($exitCode !== 0) {
            $this->logger->error('Transcode failed', ['exit_code' => $exitCode, 'stderr' => $stderr]);
            return false;
        }

        return true;
    }

    public function buildTranscodeCommand(string $inputPath, string $outputPath, array $params): string
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegPath)
        );

        // Input
        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Video codec
        if (isset($params['video_codec'])) {
            $cmd .= ' -c:v ' . $params['video_codec'];
            
            switch ($params['video_codec']) {
                case 'libx264':
                    $cmd .= ' -preset ' . ($params['preset'] ?? 'medium');
                    $cmd .= ' -crf ' . ($params['crf'] ?? 23);
                    break;
                case 'libx265':
                    $cmd .= ' -preset ' . ($params['preset'] ?? 'medium');
                    $cmd .= ' -crf ' . ($params['crf'] ?? 28);
                    break;
            }
        }

        // Video filters (scale, pad)
        if (isset($params['width']) && isset($params['height'])) {
            $scaleFilter = "scale={$params['width']}:{$params['height']}:force_original_aspect_ratio=decrease";
            $cmd .= ' -vf "' . $scaleFilter . '"';
        }

        // Audio codec
        if (isset($params['audio_codec'])) {
            $cmd .= ' -c:a ' . $params['audio_codec'];
            $cmd .= ' -b:a ' . ($params['audio_bitrate'] ?? '128k');
            $cmd .= ' -ar ' . ($params['audio_sample_rate'] ?? 48000);
            
            if (isset($params['audio_channels'])) {
                $cmd .= ' -ac ' . $params['audio_channels'];
            }
        } else {
            $cmd .= ' -c:a copy';
        }

        // Output format/container
        if (isset($params['format'])) {
            $cmd .= ' -f ' . $params['format'];
        }

        // Faststart for MP4
        if (($params['container'] ?? '') === 'mp4') {
            $cmd .= ' -movflags +faststart';
        }

        // Threads
        $cmd .= ' -threads 0';

        // Output
        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    public function generateThumbnail(string $inputPath, string $outputPath, int $timeSeconds = 10): bool
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -ss %d -vframes 1 -q:v 2 -f image2 %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $timeSeconds,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    public function extractSubtitle(string $inputPath, string $outputPath, int $streamIndex = 0): bool
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -map 0:s:%d -c:s copy %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $streamIndex,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    public function isAvailable(): bool
    {
        return file_exists($this->ffmpegPath) && is_executable($this->ffmpegPath);
    }

    public function getVersion(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $output = shell_exec(escapeshellarg($this->ffmpegPath) . ' -version 2>/dev/null');
        if (preg_match('/ffmpeg version (\S+)/', $output, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
```

### 3.3.2 Create EncodingHelper Class

Create `src/Media/Transcoding/EncodingHelper.php`:
```php
<?php

namespace Phlex\Media\Transcoding;

class EncodingHelper
{
    public function getEncodingParams(array $sourceInfo, array $profile, array $options = []): array
    {
        $videoStream = $this->getVideoStream($sourceInfo);
        $audioStream = $this->getAudioStream($sourceInfo);

        $params = [];

        // Determine if transcoding is needed
        $needsTranscode = $this->needsTranscode($videoStream, $audioStream, $profile);

        if (!$needsTranscode) {
            // Direct play - no encoding needed
            return [
                'method' => 'direct',
                'video_codec' => $videoStream['codec'] ?? null,
                'audio_codec' => $audioStream['codec'] ?? null,
            ];
        }

        // Transcode required
        $params['method'] = 'transcode';

        // Select video codec based on profile
        $params['video_codec'] = $this->selectVideoCodec($profile, $videoStream);
        $params['preset'] = 'medium';
        $params['crf'] = $this->selectCrf($params['video_codec']);

        // Select resolution
        $maxRes = $profile['max_resolution'] ?? [1920, 1080];
        $sourceRes = [$videoStream['width'] ?? 1920, $videoStream['height'] ?? 1080];
        
        if ($sourceRes[0] > $maxRes[0] || $sourceRes[1] > $maxRes[1]) {
            $params['width'] = $maxRes[0];
            $params['height'] = $maxRes[1];
        }

        // Select audio codec
        $params['audio_codec'] = 'aac';
        $params['audio_bitrate'] = $this->selectAudioBitrate($profile);
        $params['audio_channels'] = min($audioStream['channels'] ?? 2, 6);
        $params['audio_sample_rate'] = 48000;

        // Container
        $params['container'] = 'ts';
        $params['format'] = 'mpegts';

        return $params;
    }

    private function needsTranscode(array $videoStream, array $audioStream, array $profile): bool
    {
        $videoCodec = strtolower($videoStream['codec'] ?? '');
        $directPlayCodecs = $profile['direct_play'] ?? ['h264', 'h265', 'vp9'];
        
        if (!in_array($videoCodec, $directPlayCodecs)) {
            return true;
        }

        // Check resolution
        $width = $videoStream['width'] ?? 0;
        $height = $videoStream['height'] ?? 0;
        [$maxWidth, $maxHeight] = $profile['max_resolution'] ?? [1920, 1080];

        if ($width > $maxWidth || $height > $maxHeight) {
            return true;
        }

        return false;
    }

    private function selectVideoCodec(array $profile, array $videoStream): string
    {
        $videoCodec = strtolower($videoStream['codec'] ?? '');
        $transcodeCodecs = $profile['transcode'] ?? ['h264'];

        // If source is H.265 but client only supports H.264, transcode
        if ($videoCodec === 'hevc' && !in_array('h264', $transcodeCodecs)) {
            return 'libx265';
        }

        // Default to H.264
        return 'libx264';
    }

    private function selectCrf(string $codec): int
    {
        return match($codec) {
            'libx264' => 23,
            'libx265' => 28,
            'libvpx-vp9' => 31,
            default => 23,
        };
    }

    private function selectAudioBitrate(array $profile): string
    {
        $maxBitrate = $profile['max_bitrate'] ?? 100000000;
        
        if ($maxBitrate < 2000000) {
            return '96k';
        } elseif ($maxBitrate < 5000000) {
            return '128k';
        } else {
            return '192k';
        }
    }

    private function getVideoStream(array $sourceInfo): array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                return $stream;
            }
        }
        return [];
    }

    private function getAudioStream(array $sourceInfo): array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                return $stream;
            }
        }
        return [];
    }
}
```

### 3.3.3 Create TranscodeManager Class

Create `src/Media/Transcoding/TranscodeManager.php`:
```php
<?php

namespace Phlex\Media\Transcoding;

use Phlex\Common\Database\Connection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;
use Phlex\Media\Streaming\StreamState;

class TranscodeManager
{
    private Connection $db;
    private FfmpegRunner $ffmpeg;
    private EncodingHelper $encodingHelper;
    private string $transcodeDir;
    private string $segmentDir;
    private array $activeJobs = [];
    private int $maxConcurrentTranscodes;
    private StructuredLogger $logger;

    public function __construct(
        Connection $db,
        FfmpegRunner $ffmpeg,
        EncodingHelper $encodingHelper,
        string $transcodeDir,
        string $segmentDir
    ) {
        $this->db = $db;
        $this->ffmpeg = $ffmpeg;
        $this->encodingHelper = $encodingHelper;
        $this->transcodeDir = $transcodeDir;
        $this->segmentDir = $segmentDir;
        $this->maxConcurrentTranscodes = 4;
        $this->logger = LoggerFactory::get(LogChannels::TRANSCODING);
    }

    public function startTranscode(StreamState $state, array $options = []): string
    {
        $jobId = $this->generateUuid();
        
        // Create output directory
        $outputDir = "{$this->transcodeDir}/{$jobId}";
        mkdir($outputDir, 0755, true);
        
        // Get media item
        $item = $this->getMediaItem($state->mediaItemId);
        if (!$item) {
            throw new \InvalidArgumentException("Media item not found");
        }

        // Probe source
        $sourceInfo = $this->ffmpeg->probe($item['path']);
        if (!$sourceInfo) {
            throw new \RuntimeException("Failed to probe media file");
        }

        // Get encoding parameters
        $profile = $options['device_profile'] ?? [];
        $encodingParams = $this->encodingHelper->getEncodingParams($sourceInfo, $profile, $options);

        // Build output path
        $container = $encodingParams['container'] ?? 'ts';
        $outputPath = "{$outputDir}/output.{$container}";

        // Create transcode job record
        $this->db->query(
            "INSERT INTO transcode_jobs (id, media_item_id, input_path, output_path, status) VALUES (?, ?, ?, ?, 'running')",
            [$jobId, $state->mediaItemId, $item['path'], $outputPath]
        );

        // Start transcode process (async in real implementation)
        $success = $this->ffmpeg->transcode($item['path'], $outputPath, $encodingParams);

        if (!$success) {
            $this->db->query("UPDATE transcode_jobs SET status = 'failed' WHERE id = ?", [$jobId]);
            throw new \RuntimeException("Transcode failed");
        }

        $this->activeJobs[$jobId] = [
            'id' => $jobId,
            'state' => $state,
            'output_path' => $outputPath,
            'encoding_params' => $encodingParams,
            'started_at' => time(),
        ];

        $this->logger->info('Transcode started', ['job_id' => $jobId]);

        return $jobId;
    }

    public function stopTranscode(string $jobId): void
    {
        if (!isset($this->activeJobs[$jobId])) {
            return;
        }

        // Kill process if running
        $job = $this->activeJobs[$jobId];
        
        // Delete output files
        $dir = dirname($job['output_path']);
        if (is_dir($dir)) {
            array_map('unlink', glob("{$dir}/*"));
            rmdir($dir);
        }

        // Update database
        $this->db->query("UPDATE transcode_jobs SET status = 'cancelled' WHERE id = ?", [$jobId]);

        unset($this->activeJobs[$jobId]);

        $this->logger->info('Transcode cancelled', ['job_id' => $jobId]);
    }

    public function getTranscodeStatus(string $jobId): ?array
    {
        if (isset($this->activeJobs[$jobId])) {
            return [
                'id' => $jobId,
                'status' => 'running',
                'output_path' => $this->activeJobs[$jobId]['output_path'],
            ];
        }

        $result = $this->db->query("SELECT * FROM transcode_jobs WHERE id = ?", [$jobId]);
        return $result[0] ?? null;
    }

    public function getActiveTranscodeCount(): int
    {
        return count(array_filter($this->activeJobs, fn($j) => $j['status'] === 'running'));
    }

    public function cleanupStaleJobs(int $maxAgeSeconds = 3600): void
    {
        $cutoff = time() - $maxAgeSeconds;
        
        foreach ($this->activeJobs as $jobId => $job) {
            if ($job['started_at'] < $cutoff) {
                $this->stopTranscode($jobId);
                $this->logger->warning('Cleaned up stale transcode job', ['job_id' => $jobId]);
            }
        }
    }

    private function getMediaItem(string $itemId): ?array
    {
        $result = $this->db->query("SELECT * FROM media_items WHERE id = ?", [$itemId]);
        return $result[0] ?? null;
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

### 3.3.4 Create Unit Tests

Create `tests/unit/Media/Transcoding/FfmpegRunnerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Transcoding\FfmpegRunner;

class FfmpegRunnerTest extends TestCase
{
    public function testCanCreateFfmpegRunner(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        
        $this->assertInstanceOf(FfmpegRunner::class, $runner);
    }

    public function testBuildTranscodeCommand(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        
        $params = [
            'video_codec' => 'libx264',
            'preset' => 'medium',
            'crf' => 23,
            'width' => 1920,
            'height' => 1080,
            'audio_codec' => 'aac',
            'audio_bitrate' => '192k',
            'container' => 'mp4',
        ];
        
        $cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', $params);
        
        $this->assertStringContainsString('libx264', $cmd);
        $this->assertStringContainsString('aac', $cmd);
        $this->assertStringContainsString('/input.mkv', $cmd);
        $this->assertStringContainsString('/output.mp4', $cmd);
    }

    public function testIsAvailableReturnsFalseForNonexistentBinary(): void
    {
        $runner = new FfmpegRunner('/nonexistent/ffmpeg', '/nonexistent/ffprobe', '/tmp');
        
        $this->assertFalse($runner->isAvailable());
    }
}
```

---

## Verification

After completing all tasks:

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Media/Transcoding/ --testdox
```

2. Verify classes exist:
```bash
ls -la /home/sites/phlex/src/Media/Transcoding/
```

---

## Git Workflow

```bash
cd /home/sites/phlex
git checkout -b step-3.3-transcoding-engine
git add .
git commit -m "Step 3.3: Implement transcoding engine with FFmpeg integration"
unset GITHUB_TOKEN
gh pr create --title "Step 3.3: Transcoding Engine" --body "Implements FfmpegRunner, EncodingHelper, and TranscodeManager for media transcoding."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 3.R: Phase 3 Review** (`plans/phase-3/step-3.R-phase-review.md`).
