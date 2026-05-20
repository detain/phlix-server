<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * AudiobookProgressStore persists per-user audiobook progress to the database.
 *
 * Uses Workerman\MySQL\Connection for database access.
 * The completed_chapters array is stored as JSON.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Persists audiobook progress to the audiobook_progress table
 * @since 0.18.0
 * @see AudiobookProgress For the progress value object
 */
class AudiobookProgressStore
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Constructor for AudiobookProgressStore.
     *
     * @param Connection $db Database connection for progress persistence
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Gets progress for a user/audiobook combination.
     *
     * @param string $user_id The user's unique identifier
     * @param string $audiobook_id The audiobook's unique identifier
     * @return AudiobookProgress|null Progress instance or null if not found
     */
    public function getProgress(string $user_id, string $audiobook_id): ?AudiobookProgress
    {
        $result = $this->db->query(
            "SELECT * FROM audiobook_progress WHERE user_id = ? AND audiobook_id = ?",
            [$user_id, $audiobook_id]
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        $row = RowMap::fromMixed($result[0]);

        $rawCompleted = $row['completed_chapters'] ?? '[]';
        if (is_string($rawCompleted)) {
            $decoded = json_decode($rawCompleted, true);
            $rawCompleted = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($rawCompleted)) {
            $rawCompleted = [];
        }

        $completedChapters = [];
        foreach ($rawCompleted as $chapterKey => $position) {
            if (is_int($chapterKey) && is_numeric($position)) {
                $completedChapters[$chapterKey] = (int) $position;
            } elseif (is_string($chapterKey) && is_numeric($chapterKey) && is_numeric($position)) {
                $completedChapters[(int) $chapterKey] = (int) $position;
            }
        }

        $positionRaw = $row['position_ms'] ?? null;
        $positionMs = is_numeric($positionRaw) ? (int) $positionRaw : 0;
        $chapterRaw = $row['current_chapter_index'] ?? null;
        $chapterIndex = is_numeric($chapterRaw) ? (int) $chapterRaw : 0;
        $percentRaw = $row['percent_complete'] ?? null;
        $percentComplete = is_numeric($percentRaw) ? (float) $percentRaw : 0.0;
        $lastPlayedRaw = $row['last_played_at'] ?? null;
        $lastPlayedAt = is_numeric($lastPlayedRaw) ? (int) $lastPlayedRaw : null;

        return new AudiobookProgress(
            $audiobook_id,
            $user_id,
            $positionMs,
            $chapterIndex,
            $completedChapters,
            $percentComplete,
            $lastPlayedAt,
        );
    }

    /**
     * Saves progress for a user/audiobook combination.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for upsert behavior.
     *
     * @param AudiobookProgress $progress The progress to save
     * @return void
     */
    public function saveProgress(AudiobookProgress $progress): void
    {
        $completedJson = json_encode($progress->completed_chapters);

        $this->db->query(
            "INSERT INTO audiobook_progress
                (user_id, audiobook_id, position_ms, current_chapter_index,
                 completed_chapters, percent_complete, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ms = VALUES(position_ms),
                current_chapter_index = VALUES(current_chapter_index),
                completed_chapters = VALUES(completed_chapters),
                percent_complete = VALUES(percent_complete),
                last_played_at = VALUES(last_played_at)",
            [
                $progress->user_id,
                $progress->audiobook_id,
                $progress->position_ms,
                $progress->current_chapter_index,
                $completedJson,
                $progress->percent_complete,
                $progress->last_played_at ?? time(),
            ]
        );
    }

    /**
     * Marks a chapter as complete for a user/audiobook.
     *
     * @param string $user_id The user's unique identifier
     * @param string $audiobook_id The audiobook's unique identifier
     * @param int $chapter_index The chapter index that was completed
     * @return void
     */
    public function markChapterComplete(string $user_id, string $audiobook_id, int $chapter_index): void
    {
        $progress = $this->getProgress($user_id, $audiobook_id);

        if ($progress === null) {
            $progress = AudiobookProgress::fresh($audiobook_id, $user_id);
        }

        $completedChapters = $progress->completed_chapters;
        $completedChapters[$chapter_index] = $progress->position_ms;

        $newProgress = new AudiobookProgress(
            $progress->audiobook_id,
            $progress->user_id,
            $progress->position_ms,
            $progress->current_chapter_index,
            $completedChapters,
            $progress->percent_complete,
            time()
        );

        $this->saveProgress($newProgress);
    }
}
