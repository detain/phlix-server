<?php

declare(strict_types=1);

namespace Phlex\Theming;

use Workerman\MySQL\Connection;

/**
 * ThemeMediaRepository handles persistence of theme media scan results.
 *
 * Provides cache operations for theme media including upsert, find by
 * library ID, and delete. Uses the theme_media database table.
 *
 * @since 0.14.0
 */
class ThemeMediaRepository
{
    /**
     * @param Connection $db Database connection
     *
     * @since 0.14.0
     */
    public function __construct(
        private readonly Connection $db
    ) {
    }

    /**
     * Insert or update theme media for a library.
     *
     * @param ThemeMedia $themeMedia Theme media to persist
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function upsert(ThemeMedia $themeMedia): void
    {
        $audioPath = $themeMedia->audio?->path;
        $audioUrl = $themeMedia->audio?->url;
        $audioDuration = $themeMedia->audio?->duration;
        $audioFormat = $themeMedia->audio?->format;
        $videoPath = $themeMedia->video?->path;
        $videoUrl = $themeMedia->video?->url;
        $videoDuration = $themeMedia->video?->duration;
        $videoWidth = $themeMedia->video?->width;
        $videoHeight = $themeMedia->video?->height;
        $videoFormat = $themeMedia->video?->format;

        $this->db->query(
            "INSERT INTO theme_media (
                library_id,
                audio_path,
                audio_url,
                audio_duration,
                audio_format,
                video_path,
                video_url,
                video_duration,
                video_width,
                video_height,
                video_format,
                scanned_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                audio_path = VALUES(audio_path),
                audio_url = VALUES(audio_url),
                audio_duration = VALUES(audio_duration),
                audio_format = VALUES(audio_format),
                video_path = VALUES(video_path),
                video_url = VALUES(video_url),
                video_duration = VALUES(video_duration),
                video_width = VALUES(video_width),
                video_height = VALUES(video_height),
                video_format = VALUES(video_format),
                scanned_at = VALUES(scanned_at)",
            [
                $themeMedia->libraryId,
                $audioPath,
                $audioUrl,
                $audioDuration,
                $audioFormat,
                $videoPath,
                $videoUrl,
                $videoDuration,
                $videoWidth,
                $videoHeight,
                $videoFormat,
                $themeMedia->scannedAt->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Find theme media by library ID.
     *
     * @param string $libraryId The library identifier
     *
     * @return ThemeMedia|null Theme media if found, null otherwise
     *
     * @since 0.14.0
     */
    public function findByLibraryId(string $libraryId): ?ThemeMedia
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT * FROM theme_media WHERE library_id = ?",
            [$libraryId]
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];
        return $this->rowToThemeMedia($row);
    }

    /**
     * Delete theme media by library ID.
     *
     * @param string $libraryId The library identifier
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function deleteByLibraryId(string $libraryId): void
    {
        $this->db->query(
            "DELETE FROM theme_media WHERE library_id = ?",
            [$libraryId]
        );
    }

    /**
     * Convert a database row to a ThemeMedia instance.
     *
     * @param array<string, mixed> $row Database row
     *
     * @return ThemeMedia
     *
     * @since 0.14.0
     */
    private function rowToThemeMedia(array $row): ThemeMedia
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
}
