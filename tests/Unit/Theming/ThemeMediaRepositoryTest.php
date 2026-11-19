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
        $this->assertNotNull($result->audio);
        $this->assertNotNull($result->video);
        $this->assertSame(180, $result->audio->duration);
        $this->assertSame(1080, $result->video->height);
    }

    /**
     * E-09 cohort 5a legacy oracle: ThemeMediaRepository::rowToThemeMedia's
     * strict-guard ternaries transcribed verbatim from the 6cd39c5d state of
     * src/Theming/ThemeMediaRepository.php (the asset-skip guards and the raw
     * DateTimeImmutable ctor included), compared across the type domain the
     * SELECT * FROM theme_media feed can deliver.
     *
     * @param array<string, mixed> $row
     */
    private function legacyRowToThemeMedia(array $row): ThemeMedia
    {
        $audio = null;
        if (!empty($row['audio_path']) && is_string($row['audio_path'])) {
            $audio = new ThemeAudio(
                path: $row['audio_path'],
                url: is_string($row['audio_url']) ? $row['audio_url'] : '',
                duration: is_int($row['audio_duration']) ? $row['audio_duration'] : 0,
                format: is_string($row['audio_format']) ? $row['audio_format'] : ''
            );
        }

        $video = null;
        if (!empty($row['video_path']) && is_string($row['video_path'])) {
            $video = new ThemeVideo(
                path: $row['video_path'],
                url: is_string($row['video_url']) ? $row['video_url'] : '',
                duration: is_int($row['video_duration']) ? $row['video_duration'] : 0,
                width: is_int($row['video_width']) ? $row['video_width'] : 0,
                height: is_int($row['video_height']) ? $row['video_height'] : 0,
                format: is_string($row['video_format']) ? $row['video_format'] : ''
            );
        }

        $scannedAtStr = is_string($row['scanned_at'] ?? null) ? $row['scanned_at'] : 'now';
        $scannedAt = new \DateTimeImmutable($scannedAtStr);

        $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : '';

        return new ThemeMedia(
            libraryId: $libraryId,
            audio: $audio,
            video: $video,
            scannedAt: $scannedAt
        );
    }

    /**
     * @param callable(): mixed $fn
     * @return array{0: string, 1: mixed}
     */
    private function captureOutcome(callable $fn): array
    {
        try {
            return ['ok', $fn()];
        } catch (\Throwable $e) {
            return ['throw', $e::class];
        }
    }

    public function testRowToThemeMediaShapingIsByteIdenticalToLegacyAcrossVectors(): void
    {
        $typed = [
            'library_id' => 'lib-1',
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
        ];

        $audioNull = array_merge($typed, [
            'audio_path' => null, 'audio_url' => 5, 'audio_duration' => '180', 'audio_format' => 3.2,
        ]);
        $audioGuardEmpty = array_merge($typed, ['audio_path' => '']);
        $audioPartialNull = array_merge($typed, [
            'audio_path' => '/movies/theme.mp3', 'audio_url' => null, 'audio_duration' => null, 'audio_format' => null,
        ]);
        $videoWrongTypes = array_merge($typed, [
            'video_url' => true, 'video_duration' => 60.0, 'video_width' => null,
            'video_height' => '1080', 'video_format' => '',
        ]);
        $allNullAssets = array_merge($typed, [
            'library_id' => null, 'audio_path' => null, 'audio_url' => null, 'audio_duration' => null,
            'audio_format' => null, 'video_path' => null, 'video_url' => null, 'video_duration' => null,
            'video_width' => null, 'video_height' => null, 'video_format' => null,
        ]);
        $scannedAtInt = array_merge($typed, ['scanned_at' => 5]);
        $scannedAtNull = array_merge($typed, ['scanned_at' => null]);
        $scannedAtEmpty = array_merge($typed, ['scanned_at' => '']);
        $scannedAtMissing = $typed;
        unset($scannedAtMissing['scanned_at']);
        $scannedAtGarbage = array_merge($typed, ['scanned_at' => 'zzz-not-a-date']);

        $vectors = [
            'typed' => [$typed, false],
            'audio-null-legacy-guard' => [$audioNull, false],
            'audio-guard-empty-path' => [$audioGuardEmpty, false],
            'audio-partial-nulls' => [$audioPartialNull, false],
            'video-wrong-types' => [$videoWrongTypes, false],
            'all-assets-null' => [$allNullAssets, false],
            'scanned-at-int-defaults-to-now' => [$scannedAtInt, true],
            'scanned-at-null-defaults-to-now' => [$scannedAtNull, true],
            'scanned-at-empty-string-is-now-like-legacy' => [$scannedAtEmpty, true],
            'scanned-at-missing-defaults-to-now' => [$scannedAtMissing, true],
            'scanned-at-garbage-still-throws' => [$scannedAtGarbage, false],
        ];

        foreach ($vectors as $label => [$row, $approximateNow]) {
            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturn([$row]);
            $repository = new ThemeMediaRepository($db);

            $production = $this->captureOutcome(
                fn(): array => $repository->findByLibraryId('lib-1')?->toArray() ?? []
            );
            $legacy = $this->captureOutcome(
                fn(): array => $this->legacyRowToThemeMedia($row)->toArray()
            );

            if ($approximateNow && $production[0] === 'ok' && $legacy[0] === 'ok') {
                // 'now' legs cannot be string-pinned across the two calls: the
                // ATOM stamps may straddle a second boundary. Compare shape and
                // timestamps with a 2 s tolerance instead.
                $this->assertSame(array_keys($production[1]), array_keys($legacy[1]), $label);
                $this->assertSame($production[1]['library_id'], $legacy[1]['library_id'], $label);
                $this->assertSame($production[1]['audio'], $legacy[1]['audio'], $label);
                $this->assertSame($production[1]['video'], $legacy[1]['video'], $label);
                $prodAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $production[1]['scanned_at']);
                $legacyAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $legacy[1]['scanned_at']);
                $this->assertLessThanOrEqual(2, abs($prodAt->getTimestamp() - $legacyAt->getTimestamp()), $label);
                continue;
            }

            $this->assertSame($legacy, $production, $label);
        }
    }
}
