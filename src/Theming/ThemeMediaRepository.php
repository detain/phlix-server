<?php

/**
 * Phlix media server component: Theming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Theming;

use Phlix\Common\Util\RowMap;
use Phlix\Shared\Database\UpsertSyntax;
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
            UpsertSyntax::adapt("INSERT INTO theme_media (
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
             AS new_row
            ON DUPLICATE KEY UPDATE
                audio_path = new_row.audio_path,
                audio_url = new_row.audio_url,
                audio_duration = new_row.audio_duration,
                audio_format = new_row.audio_format,
                video_path = new_row.video_path,
                video_url = new_row.video_url,
                video_duration = new_row.video_duration,
                video_width = new_row.video_width,
                video_height = new_row.video_height,
                video_format = new_row.video_format,
                scanned_at = new_row.scanned_at"),
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
        // E-09 cohort 5a: RowMap fold of the strict-guard ternaries (fallbacks
        // transcribed; the asset-skip guards and the raw DateTimeImmutable ctor
        // are pre-fold shapes kept verbatim). The feed is `SELECT * FROM
        // theme_media`, so the pre-fold direct-access arms could not warn on a
        // real row; the fold only removes that never-fires hazard. Byte-identity
        // pinned by the legacy-oracle vectors in
        // tests/Unit/Theming/ThemeMediaRepositoryTest.php.
        $audio = null;
        if (!empty($row['audio_path']) && is_string($row['audio_path'])) {
            $audio = new ThemeAudio(
                path: $row['audio_path'],
                url: RowMap::str($row, 'audio_url', ''),
                duration: RowMap::int($row, 'audio_duration', 0),
                format: RowMap::str($row, 'audio_format', '')
            );
        }

        $video = null;
        if (!empty($row['video_path']) && is_string($row['video_path'])) {
            $video = new ThemeVideo(
                path: $row['video_path'],
                url: RowMap::str($row, 'video_url', ''),
                duration: RowMap::int($row, 'video_duration', 0),
                width: RowMap::int($row, 'video_width', 0),
                height: RowMap::int($row, 'video_height', 0),
                format: RowMap::str($row, 'video_format', '')
            );
        }

        // T1H half-fold: missing/NULL/non-string default to 'now'; a garbage
        // string still throws from the ctor — exactly like the pre-fold block
        // (a nullableDateTime here would NULL-on-garbage ≠ legacy throw).
        $scannedAtStr = RowMap::str($row, 'scanned_at', 'now');
        $scannedAt = new \DateTimeImmutable($scannedAtStr);

        $libraryId = RowMap::str($row, 'library_id', '');

        return new ThemeMedia(
            libraryId: $libraryId,
            audio: $audio,
            video: $video,
            scannedAt: $scannedAt
        );
    }
}
