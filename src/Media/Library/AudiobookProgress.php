<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

/**
 * AudiobookProgress value object representing per-user progress in an audiobook.
 *
 * Tracks position within a chapter, completed chapters, and overall completion percentage.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Immutable value object for audiobook playback progress
 * @since 0.18.0
 */
final class AudiobookProgress
{
    /**
     * @param string $audiobook_id The audiobook's unique identifier
     * @param string $user_id The user's unique identifier
     * @param int $position_ms Current position within the chapter in milliseconds
     * @param int $current_chapter_index Index of the current chapter (0-based)
     * @param array<int, int> $completed_chapters Map of chapter_index => position_ms when completed
     * @param float $percent_complete Overall completion percentage (0.0 – 100.0)
     * @param int|null $last_played_at Unix timestamp of last play
     */
    public function __construct(
        public readonly string $audiobook_id,
        public readonly string $user_id,
        public readonly int $position_ms,
        public readonly int $current_chapter_index,
        public readonly array $completed_chapters,
        public readonly float $percent_complete,
        public readonly ?int $last_played_at = null,
    ) {
    }

    /**
     * Creates a new progress instance for a new user/audiobook combination.
     *
     * @param string $audiobook_id The audiobook's unique identifier
     * @param string $user_id The user's unique identifier
     * @return self Fresh progress at chapter 0, position 0
     */
    public static function fresh(string $audiobook_id, string $user_id): self
    {
        return new self(
            $audiobook_id,
            $user_id,
            0,
            0,
            [],
            0.0,
            time()
        );
    }

    /**
     * Gets a summary array of the progress.
     *
     * @return array<string, mixed> Summary array
     */
    public function toArray(): array
    {
        return [
            'audiobook_id' => $this->audiobook_id,
            'user_id' => $this->user_id,
            'position_ms' => $this->position_ms,
            'current_chapter_index' => $this->current_chapter_index,
            'completed_chapters' => $this->completed_chapters,
            'percent_complete' => $this->percent_complete,
            'last_played_at' => $this->last_played_at,
        ];
    }
}
