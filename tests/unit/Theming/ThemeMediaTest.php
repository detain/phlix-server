<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use PHPUnit\Framework\TestCase;
use Phlix\Theming\ThemeAudio;
use Phlix\Theming\ThemeMedia;
use Phlix\Theming\ThemeVideo;

class ThemeMediaTest extends TestCase
{
    public function testConstructorStoresAudioAndVideo(): void
    {
        $audio = new ThemeAudio(
            path: '/movies/theme.mp3',
            url: '/stream/theme-media/audio?path=/movies/theme.mp3',
            duration: 180,
            format: 'mp3'
        );

        $video = new ThemeVideo(
            path: '/movies/backdrop.mp4',
            url: '/stream/theme-media/video?path=/movies/backdrop.mp4',
            duration: 60,
            width: 1920,
            height: 1080,
            format: 'mp4'
        );

        $scannedAt = new \DateTimeImmutable('2026-01-15 10:30:00');

        $themeMedia = new ThemeMedia(
            libraryId: 'lib-123',
            audio: $audio,
            video: $video,
            scannedAt: $scannedAt
        );

        $this->assertSame('lib-123', $themeMedia->libraryId);
        $this->assertSame($audio, $themeMedia->audio);
        $this->assertSame($video, $themeMedia->video);
        $this->assertSame($scannedAt, $themeMedia->scannedAt);
    }

    public function testAudioNullWhenNoAudioFile(): void
    {
        $video = new ThemeVideo(
            path: '/movies/backdrop.mp4',
            url: '/stream/theme-media/video?path=/movies/backdrop.mp4',
            duration: 60,
            width: 1920,
            height: 1080,
            format: 'mp4'
        );

        $themeMedia = new ThemeMedia(
            libraryId: 'lib-123',
            audio: null,
            video: $video,
            scannedAt: new \DateTimeImmutable()
        );

        $this->assertNull($themeMedia->audio);
        $this->assertNotNull($themeMedia->video);
    }

    public function testVideoNullWhenNoVideoFile(): void
    {
        $audio = new ThemeAudio(
            path: '/movies/theme.mp3',
            url: '/stream/theme-media/audio?path=/movies/theme.mp3',
            duration: 180,
            format: 'mp3'
        );

        $themeMedia = new ThemeMedia(
            libraryId: 'lib-123',
            audio: $audio,
            video: null,
            scannedAt: new \DateTimeImmutable()
        );

        $this->assertNotNull($themeMedia->audio);
        $this->assertNull($themeMedia->video);
    }

    public function testHasAudioReturnsTrueWhenAudioPresent(): void
    {
        $audio = new ThemeAudio(
            path: '/movies/theme.mp3',
            url: '/stream/theme-media/audio?path=/movies/theme.mp3',
            duration: 180,
            format: 'mp3'
        );

        $themeMedia = new ThemeMedia(
            libraryId: 'lib-123',
            audio: $audio,
            video: null,
            scannedAt: new \DateTimeImmutable()
        );

        $this->assertTrue($themeMedia->hasAudio());
        $this->assertFalse($themeMedia->hasVideo());
    }

    public function testHasVideoReturnsTrueWhenVideoPresent(): void
    {
        $video = new ThemeVideo(
            path: '/movies/backdrop.mp4',
            url: '/stream/theme-media/video?path=/movies/backdrop.mp4',
            duration: 60,
            width: 1920,
            height: 1080,
            format: 'mp4'
        );

        $themeMedia = new ThemeMedia(
            libraryId: 'lib-123',
            audio: null,
            video: $video,
            scannedAt: new \DateTimeImmutable()
        );

        $this->assertFalse($themeMedia->hasAudio());
        $this->assertTrue($themeMedia->hasVideo());
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $audio = new ThemeAudio(
            path: '/movies/theme.mp3',
            url: '/stream/theme-media/audio?path=/movies/theme.mp3',
            duration: 180,
            format: 'mp3'
        );

        $scannedAt = new \DateTimeImmutable('2026-01-15 10:30:00', new \DateTimeZone('UTC'));

        $themeMedia = new ThemeMedia(
            libraryId: 'lib-123',
            audio: $audio,
            video: null,
            scannedAt: $scannedAt
        );

        $array = $themeMedia->toArray();

        $this->assertSame('lib-123', $array['library_id']);
        $this->assertNotNull($array['audio']);
        $this->assertNull($array['video']);
        $this->assertSame('2026-01-15T10:30:00+00:00', $array['scanned_at']);
        $this->assertSame('/movies/theme.mp3', $array['audio']['path']);
        $this->assertSame(180, $array['audio']['duration']);
    }

    public function testBothAudioAndVideoCanBeNull(): void
    {
        $themeMedia = new ThemeMedia(
            libraryId: 'lib-123',
            audio: null,
            video: null,
            scannedAt: new \DateTimeImmutable()
        );

        $this->assertFalse($themeMedia->hasAudio());
        $this->assertFalse($themeMedia->hasVideo());
    }
}
