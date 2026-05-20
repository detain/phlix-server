# Step 3.1: Stream Manager

**Phase:** 3 - Streaming & Transcoding Engine  
**Plan File:** step-3.1-stream-manager.md  
**Objective:** Implement stream state management, StreamManager, and quality selector

---

## Overview

This step implements the core streaming infrastructure including StreamState, StreamManager, and QualitySelector for determining play methods.

**Prerequisites:** Phase 2 must be completed first.

---

## Tasks

### 3.1.1 Create StreamState Class

Create `src/Media/Streaming/StreamState.php`:
```php
<?php

namespace Phlex\Media\Streaming;

class StreamState
{
    public string $id;
    public string $mediaItemId;
    public string $sessionId;
    public string $userId;
    public int $positionTicks;
    public int $durationTicks;
    public string $status; // playing, paused, stopped
    public string $playMethod; // direct, transcode
    public array $requestedStreams = [];
    public array $actualStreams = [];
    public ?string $transcodeJobId = null;
    public ?string $directStreamUrl = null;
    public float $startedAt;
    public ?float $pausedAt = null;

    public function __construct()
    {
        $this->status = 'stopped';
        $this->positionTicks = 0;
        $this->startedAt = microtime(true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_item_id' => $this->mediaItemId,
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'position_ticks' => $this->positionTicks,
            'duration_ticks' => $this->durationTicks,
            'status' => $this->status,
            'play_method' => $this->playMethod,
            'requested_streams' => $this->requestedStreams,
            'actual_streams' => $this->actualStreams,
            'transcode_job_id' => $this->transcodeJobId,
        ];
    }

    public function getPositionSeconds(): float
    {
        return $this->positionTicks / 10000000;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationTicks / 10000000;
    }

    public function getProgressPercent(): float
    {
        if ($this->durationTicks === 0) {
            return 0;
        }
        return ($this->positionTicks / $this->durationTicks) * 100;
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['playing', 'paused']);
    }

    public function play(): void
    {
        $this->status = 'playing';
        if ($this->pausedAt !== null) {
            // Adjust start time to account for pause duration
            $pauseDuration = microtime(true) - $this->pausedAt;
            $this->startedAt += $pauseDuration;
            $this->pausedAt = null;
        }
    }

    public function pause(): void
    {
        $this->status = 'paused';
        $this->pausedAt = microtime(true);
    }

    public function stop(): void
    {
        $this->status = 'stopped';
    }

    public function seek(int $positionTicks): void
    {
        $this->positionTicks = max(0, min($positionTicks, $this->durationTicks));
    }
}
```

### 3.1.2 Create QualitySelector Class

Create `src/Media/Streaming/QualitySelector.php`:
```php
<?php

namespace Phlex\Media\Streaming;

class QualitySelector
{
    private array $deviceProfiles;

    public function __construct(array $deviceProfiles = [])
    {
        $this->deviceProfiles = $deviceProfiles;
        $this->loadDefaultProfiles();
    }

    private function loadDefaultProfiles(): void
    {
        $this->deviceProfiles = array_merge($this->deviceProfiles, [
            'generic' => [
                'max_bitrate' => 100000000,
                'max_resolution' => [3840, 2160],
                'direct_play' => ['h264', 'h265', 'vp9'],
                'transcode' => ['h264'],
                'container' => ['mp4', 'mkv', 'webm'],
            ],
            'mobile-low' => [
                'max_bitrate' => 1500000,
                'max_resolution' => [854, 480],
                'direct_play' => ['h264'],
                'transcode' => ['h264'],
                'container' => ['mp4'],
            ],
            'mobile-high' => [
                'max_bitrate' => 4000000,
                'max_resolution' => [1280, 720],
                'direct_play' => ['h264', 'h265'],
                'transcode' => ['h264'],
                'container' => ['mp4'],
            ],
            'web' => [
                'max_bitrate' => 10000000,
                'max_resolution' => [1920, 1080],
                'direct_play' => ['h264', 'vp9'],
                'transcode' => ['h264', 'vp9'],
                'container' => ['mp4', 'webm'],
            ],
            'tv-4k' => [
                'max_bitrate' => 50000000,
                'max_resolution' => [3840, 2160],
                'direct_play' => ['h264', 'h265', 'vp9'],
                'transcode' => ['h264', 'h265'],
                'container' => ['mp4', 'mkv', 'ts'],
            ],
        ]);
    }

    public function selectQuality(array $sourceInfo, string $profileName, array $options = []): array
    {
        $profile = $this->deviceProfiles[$profileName] ?? $this->deviceProfiles['generic'];
        
        $videoStream = $this->getVideoStream($sourceInfo);
        $audioStream = $this->getAudioStream($sourceInfo);
        
        $canDirectPlay = $this->canDirectPlay($videoStream, $audioStream, $profile);
        
        if ($canDirectPlay) {
            return [
                'method' => 'direct',
                'container' => $this->detectContainer($sourceInfo),
                'video_codec' => $videoStream['codec'] ?? null,
                'audio_codec' => $audioStream['codec'] ?? null,
                'max_resolution' => $profile['max_resolution'],
                'max_bitrate' => $profile['max_bitrate'],
            ];
        }
        
        return [
            'method' => 'transcode',
            'container' => 'ts',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'max_resolution' => $profile['max_resolution'],
            'max_bitrate' => min($profile['max_bitrate'], 8000000),
        ];
    }

    private function canDirectPlay(?array $videoStream, ?array $audioStream, array $profile): bool
    {
        if (!$videoStream || !$audioStream) {
            return false;
        }

        $videoCodec = strtolower($videoStream['codec'] ?? '');
        $audioCodec = strtolower($audioStream['codec'] ?? '');
        
        // Check video codec
        if (!in_array($videoCodec, $profile['direct_play'])) {
            return false;
        }
        
        // Check audio codec (simplified - might need more codecs)
        $supportedAudio = ['aac', 'ac3', 'eac3', 'mp3', 'flac', 'opus'];
        if (!in_array($audioCodec, $supportedAudio)) {
            return false;
        }
        
        // Check resolution
        $width = $videoStream['width'] ?? 0;
        $height = $videoStream['height'] ?? 0;
        [$maxWidth, $maxHeight] = $profile['max_resolution'];
        
        if ($width > $maxWidth || $height > $maxHeight) {
            return false;
        }
        
        // Check bitrate
        $bitrate = $videoStream['bitrate'] ?? 0;
        if ($bitrate > $profile['max_bitrate']) {
            return false;
        }
        
        return true;
    }

    private function getVideoStream(array $sourceInfo): ?array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                return $stream;
            }
        }
        return null;
    }

    private function getAudioStream(array $sourceInfo): ?array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                return $stream;
            }
        }
        return null;
    }

    private function detectContainer(array $sourceInfo): string
    {
        $format = $sourceInfo['format'] ?? [];
        $formatName = strtolower($format['format_name'] ?? '');
        
        if (str_contains($formatName, 'matroska')) {
            return 'mkv';
        }
        if (str_contains($formatName, 'mp4')) {
            return 'mp4';
        }
        if (str_contains($formatName, 'webm')) {
            return 'webm';
        }
        if (str_contains($formatName, 'mpegts')) {
            return 'ts';
        }
        
        return 'mp4';
    }

    public function registerProfile(string $name, array $profile): void
    {
        $this->deviceProfiles[$name] = $profile;
    }

    public function getProfile(string $name): ?array
    {
        return $this->deviceProfiles[$name] ?? null;
    }
}
```

### 3.1.3 Create StreamManager Class

Create `src/Media/Streaming/StreamManager.php`:
```php
<?php

namespace Phlex\Media\Streaming;

use Phlex\Common\Database\Connection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;
use Phlex\Media\Library\ItemRepository;

class StreamManager
{
    private array $activeStreams = [];
    private Connection $db;
    private ItemRepository $itemRepository;
    private QualitySelector $qualitySelector;
    private StructuredLogger $logger;

    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        QualitySelector $qualitySelector
    ) {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->qualitySelector = $qualitySelector;
        $this->logger = LoggerFactory::get(LogChannels::STREAMING);
    }

    public function createStream(
        string $mediaItemId,
        string $sessionId,
        string $userId,
        array $options = []
    ): StreamState {
        $item = $this->itemRepository->findById($mediaItemId);
        if (!$item) {
            throw new \InvalidArgumentException("Media item not found: $mediaItemId");
        }

        $state = new StreamState();
        $state->id = $this->generateUuid();
        $state->mediaItemId = $mediaItemId;
        $state->sessionId = $sessionId;
        $state->userId = $userId;
        $state->durationTicks = $item['metadata']['runtime_ticks'] ?? 0;

        // Get device profile
        $profileName = $options['device_profile'] ?? 'generic';
        
        // Probe source file
        $sourceInfo = $this->probeMedia($item['path']);
        
        // Select quality
        $quality = $this->qualitySelector->selectQuality($sourceInfo, $profileName, $options);
        $state->playMethod = $quality['method'];

        if ($quality['method'] === 'direct') {
            $state->directStreamUrl = $this->buildDirectStreamUrl($mediaItemId);
        } else {
            // Transcode will be started by TranscodeManager
            $state->transcodeJobId = $options['transcode_job_id'] ?? null;
        }

        $this->activeStreams[$state->id] = $state;
        
        // Persist to database
        $this->persistStreamState($state);
        
        $this->logger->info('Stream created', [
            'stream_id' => $state->id,
            'media_item_id' => $mediaItemId,
            'method' => $quality['method'],
        ]);

        return $state;
    }

    public function getStream(string $streamId): ?StreamState
    {
        return $this->activeStreams[$streamId] ?? null;
    }

    public function getStreamBySession(string $sessionId): ?StreamState
    {
        foreach ($this->activeStreams as $stream) {
            if ($stream->sessionId === $sessionId) {
                return $stream;
            }
        }
        return null;
    }

    public function updatePosition(string $streamId, int $positionTicks): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->positionTicks = $positionTicks;
        
        // Persist periodically (could add debouncing)
        $this->persistPlaybackState($stream);
    }

    public function play(string $streamId): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->play();
        $this->logger->debug('Stream playing', ['stream_id' => $streamId]);
    }

    public function pause(string $streamId): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->pause();
        $this->logger->debug('Stream paused', ['stream_id' => $streamId]);
    }

    public function stop(string $streamId): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->stop();
        $this->persistPlaybackState($stream);
        unset($this->activeStreams[$streamId]);
        
        $this->logger->info('Stream stopped', ['stream_id' => $streamId]);
    }

    public function seek(string $streamId, int $positionTicks): void
    {
        $stream = $this->getStream($streamId);
        if (!$stream) {
            return;
        }

        $stream->seek($positionTicks);
        $this->logger->debug('Stream seeked', [
            'stream_id' => $streamId,
            'position_ticks' => $positionTicks,
        ]);
    }

    public function getActiveStreams(): array
    {
        return array_values($this->activeStreams);
    }

    public function getActiveStreamCount(): int
    {
        return count($this->activeStreams);
    }

    private function probeMedia(string $path): array
    {
        // Simplified - would use FFprobe in real implementation
        return [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 5000000],
                ['codec_type' => 'audio', 'codec' => 'aac', 'channels' => 2],
            ],
            'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
        ];
    }

    private function buildDirectStreamUrl(string $mediaItemId): string
    {
        return "/media/$mediaItemId/stream";
    }

    private function persistStreamState(StreamState $state): void
    {
        $this->db->query(
            "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position_ticks = VALUES(position_ticks), playback_status = VALUES(playback_status)",
            [$state->id, $state->sessionId, $state->mediaItemId, $state->positionTicks, $state->durationTicks, $state->status]
        );
    }

    private function persistPlaybackState(StreamState $state): void
    {
        $this->db->query(
            "UPDATE playback_state SET position_ticks = ?, duration_ticks = ?, playback_status = ?, updated_at = NOW()
             WHERE id = ?",
            [$state->positionTicks, $state->durationTicks, $state->status, $state->id]
        );
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

### 3.1.4 Create Unit Tests

Create `tests/unit/Media/Streaming/StreamStateTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Streaming\StreamState;

class StreamStateTest extends TestCase
{
    public function testCanCreateStreamState(): void
    {
        $state = new StreamState();
        $this->assertInstanceOf(StreamState::class, $state);
        $this->assertEquals('stopped', $state->status);
    }

    public function testPlayChangesStatus(): void
    {
        $state = new StreamState();
        $state->play();
        $this->assertEquals('playing', $state->status);
    }

    public function testPauseChangesStatus(): void
    {
        $state = new StreamState();
        $state->play();
        $state->pause();
        $this->assertEquals('paused', $state->status);
    }

    public function testSeekClampsPosition(): void
    {
        $state = new StreamState();
        $state->durationTicks = 100000000;
        
        $state->seek(50000000);
        $this->assertEquals(50000000, $state->positionTicks);
        
        // Test clamping at boundaries
        $state->seek(-10000000);
        $this->assertEquals(0, $state->positionTicks);
        
        $state->seek(200000000);
        $this->assertEquals(100000000, $state->positionTicks);
    }

    public function testToArray(): void
    {
        $state = new StreamState();
        $state->id = 'test-id';
        $state->mediaItemId = 'media-1';
        
        $arr = $state->toArray();
        $this->assertEquals('test-id', $arr['id']);
        $this->assertEquals('media-1', $arr['media_item_id']);
    }
}
```

Create `tests/unit/Media/Streaming/QualitySelectorTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Streaming\QualitySelector;

class QualitySelectorTest extends TestCase
{
    public function testCanCreateQualitySelector(): void
    {
        $selector = new QualitySelector();
        $this->assertInstanceOf(QualitySelector::class, $selector);
    }

    public function testSelectsDirectPlayForCompatibleSource(): void
    {
        $selector = new QualitySelector();
        
        $sourceInfo = [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 5000000],
                ['codec_type' => 'audio', 'codec' => 'aac', 'channels' => 2],
            ],
        ];
        
        $result = $selector->selectQuality($sourceInfo, 'generic');
        
        $this->assertEquals('direct', $result['method']);
    }

    public function testSelectsTranscodeForIncompatibleSource(): void
    {
        $selector = new QualitySelector();
        
        $sourceInfo = [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'hevc', 'width' => 3840, 'height' => 2160, 'bitrate' => 100000000],
                ['codec_type' => 'audio', 'codec' => 'truehd', 'channels' => 8],
            ],
        ];
        
        $result = $selector->selectQuality($sourceInfo, 'mobile-low');
        
        $this->assertEquals('transcode', $result['method']);
    }
}
```

---

## Verification

After completing all tasks:

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Media/Streaming/ --testdox
```

2. Verify classes exist:
```bash
ls -la /home/sites/phlex/src/Media/Streaming/
```

---

## Git Workflow

```bash
cd /home/sites/phlex
git checkout -b step-3.1-stream-manager
git add .
git commit -m "Step 3.1: Implement stream manager and quality selector"
unset GITHUB_TOKEN
gh pr create --title "Step 3.1: Stream Manager" --body "Implements StreamState, StreamManager, and QualitySelector for stream management."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 3.2: HLS Streaming** (`plans/phase-3/step-3.2-hls-streaming.md`).
