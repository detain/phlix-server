<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use PHPUnit\Framework\TestCase;
use Phlix\Theming\ThemeAudio;
use Phlix\Theming\ThemeMedia;
use Phlix\Theming\ThemeMediaRepository;
use Phlix\Theming\ThemeVideo;
use Workerman\MySQL\Connection;

class ThemeMediaRepositoryTest extends TestCase
{
    public function testUpsertInsertsNewRow(): void
    {
        $db = $this->createMock(Connection::class);

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
            scannedAt: new \DateTimeImmutable('2026-01-15 10:30:00')
        );

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO theme_media'),
                $this->callback(function ($params) {
                    return count($params) === 12
                        && $params[0] === 'lib-123'
                        && $params[1] === '/movies/theme.mp3'
                        && $params[4] === 'mp3'
                        && $params[11] === '2026-01-15 10:30:00';
                })
            );

        $repository = new ThemeMediaRepository($db);
        $repository->upsert($themeMedia);
    }

    public function testUpsertUpdatesExistingRow(): void
    {
        $db = $this->createMock(Connection::class);

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
            scannedAt: new \DateTimeImmutable('2026-01-15 10:30:00')
        );

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('ON DUPLICATE KEY UPDATE'),
                $this->callback(function ($params) {
                    return count($params) === 12
                        && $params[0] === 'lib-123'
                        && $params[5] === '/movies/backdrop.mp4'
                        && $params[10] === 'mp4'
                        && $params[11] === '2026-01-15 10:30:00';
                })
            );

        $repository = new ThemeMediaRepository($db);
        $repository->upsert($themeMedia);
    }

    public function testFindByLibraryIdReturnsCached(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'library_id' => 'lib-123',
                'audio_path' => '/movies/theme.mp3',
                'audio_url' => '/stream/theme-media/audio?path=/movies/theme.mp3',
                'audio_duration' => 180,
                'audio_format' => 'mp3',
                'video_path' => null,
                'video_url' => null,
                'video_duration' => null,
                'video_width' => null,
                'video_height' => null,
                'video_format' => null,
                'scanned_at' => '2026-01-15 10:30:00',
            ]
        ]);

        $repository = new ThemeMediaRepository($db);
        $result = $repository->findByLibraryId('lib-123');

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertSame('lib-123', $result->libraryId);
        $this->assertNotNull($result->audio);
        $this->assertSame('/movies/theme.mp3', $result->audio->path);
        $this->assertSame(180, $result->audio->duration);
        $this->assertNull($result->video);
    }

    public function testFindByLibraryIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repository = new ThemeMediaRepository($db);
        $result = $repository->findByLibraryId('non-existent');

        $this->assertNull($result);
    }

    public function testDeleteRemovesRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM theme_media WHERE library_id = ?'),
                ['lib-123']
            );

        $repository = new ThemeMediaRepository($db);
        $repository->deleteByLibraryId('lib-123');
    }

    public function testFindByLibraryIdWithVideoReturnsVideoData(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'library_id' => 'lib-123',
                'audio_path' => null,
                'audio_url' => null,
                'audio_duration' => null,
                'audio_format' => null,
                'video_path' => '/movies/backdrop.mp4',
                'video_url' => '/stream/theme-media/video?path=/movies/backdrop.mp4',
                'video_duration' => 60,
                'video_width' => 1920,
                'video_height' => 1080,
                'video_format' => 'mp4',
                'scanned_at' => '2026-01-15 10:30:00',
            ]
        ]);

        $repository = new ThemeMediaRepository($db);
        $result = $repository->findByLibraryId('lib-123');

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertNull($result->audio);
        $this->assertNotNull($result->video);
        $this->assertSame('/movies/backdrop.mp4', $result->video->path);
        $this->assertSame(60, $result->video->duration);
        $this->assertSame(1920, $result->video->width);
        $this->assertSame(1080, $result->video->height);
        $this->assertSame('mp4', $result->video->format);
    }

    public function testFindByLibraryIdWithBothAudioAndVideo(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'library_id' => 'lib-123',
                'audio_path' => '/movies/theme.mp3',
                'audio_url' => '/stream/theme-media/audio?path=/movies/theme.mp3',
                'audio_duration' => 180,
                'audio_format' => 'mp3',
                'video_path' => '/movies/backdrop.mp4',
                'video_url' => '/stream/theme-media/video?path=/movies/backdrop.mp4',
                'video_duration' => 60,
                'video_width' => 1920,
                'video_height' => 1080,
                'video_format' => 'mp4',
                'scanned_at' => '2026-01-15 10:30:00',
            ]
        ]);

        $repository = new ThemeMediaRepository($db);
        $result = $repository->findByLibraryId('lib-123');

        $this->assertInstanceOf(ThemeMedia::class, $result);
        $this->assertTrue($result->hasAudio());
        $this->assertTrue($result->hasVideo());
        $this->assertSame(180, $result->audio->duration);
        $this->assertSame(1080, $result->video->height);
    }
}
