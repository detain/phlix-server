<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * BookProgress value object representing per-user progress in a book.
 *
 * Tracks position within the book, current page, and overall completion percentage.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Immutable value object for book reading progress
 * @since 0.17.0
 */
final class BookProgress
{
    /**
     * @param string $book_id The book's unique identifier
     * @param string $user_id The user's unique identifier
     * @param int $position_ms Current position within the book in milliseconds
     * @param int $current_page Current page number (1-based)
     * @param int $total_pages Total pages in the book (0 if unknown)
     * @param float $percent_complete Overall completion percentage (0.0 – 100.0)
     * @param int|null $last_read_at Unix timestamp of last read
     */
    public function __construct(
        public readonly string $book_id,
        public readonly string $user_id,
        public readonly int $position_ms,
        public readonly int $current_page,
        public readonly int $total_pages,
        public readonly float $percent_complete,
        public readonly ?int $last_read_at = null,
    ) {
    }

    /**
     * Creates a new progress instance for a new user/book combination.
     *
     * @param string $book_id The book's unique identifier
     * @param string $user_id The user's unique identifier
     * @return self Fresh progress at page 1, position 0
     */
    public static function fresh(string $book_id, string $user_id): self
    {
        return new self(
            $book_id,
            $user_id,
            0,
            1,
            0,
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
            'book_id' => $this->book_id,
            'user_id' => $this->user_id,
            'position_ms' => $this->position_ms,
            'current_page' => $this->current_page,
            'total_pages' => $this->total_pages,
            'percent_complete' => $this->percent_complete,
            'last_read_at' => $this->last_read_at,
        ];
    }
}
