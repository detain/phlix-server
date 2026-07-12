<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * BookProgressStore persists per-user book reading progress to the database.
 *
 * Uses Workerman\MySQL\Connection for database access.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Persists book reading progress to the book_progress table
 * @since 0.17.0
 * @see BookProgress For the progress value object
 */
class BookProgressStore
{
    /** @var Connection Database connection */
    private Connection $db;

    /**
     * Constructor for BookProgressStore.
     *
     * @param Connection $db Database connection for progress persistence
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Gets progress for a user/book combination.
     *
     * @param string $user_id The user's unique identifier
     * @param string $book_id The book's unique identifier
     * @return BookProgress|null Progress instance or null if not found
     */
    public function getProgress(string $user_id, string $book_id): ?BookProgress
    {
        $result = $this->db->query(
            "SELECT * FROM book_progress WHERE user_id = ? AND book_id = ?",
            [$user_id, $book_id]
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        $row = RowMap::fromMixed($result[0]);

        $positionRaw = $row['position_ms'] ?? null;
        $positionMs = is_numeric($positionRaw) ? (int) $positionRaw : 0;
        $pageRaw = $row['current_page'] ?? null;
        $currentPage = is_numeric($pageRaw) ? (int) $pageRaw : 1;
        $totalPagesRaw = $row['total_pages'] ?? null;
        $totalPages = is_numeric($totalPagesRaw) ? (int) $totalPagesRaw : 0;
        $percentRaw = $row['percent_complete'] ?? null;
        $percentComplete = is_numeric($percentRaw) ? (float) $percentRaw : 0.0;
        $lastReadRaw = $row['last_read_at'] ?? null;
        $lastReadAt = is_numeric($lastReadRaw) ? (int) $lastReadRaw : null;

        return new BookProgress(
            $book_id,
            $user_id,
            $positionMs,
            $currentPage,
            $totalPages,
            $percentComplete,
            $lastReadAt,
        );
    }

    /**
     * Saves progress for a user/book combination.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for upsert behavior.
     *
     * @param BookProgress $progress The progress to save
     * @return void
     */
    public function saveProgress(BookProgress $progress): void
    {
        $this->db->query(
            "INSERT INTO book_progress
                (user_id, book_id, position_ms, current_page, total_pages,
                 percent_complete, last_read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_ms = VALUES(position_ms),
                current_page = VALUES(current_page),
                total_pages = VALUES(total_pages),
                percent_complete = VALUES(percent_complete),
                last_read_at = VALUES(last_read_at)",
            [
                $progress->user_id,
                $progress->book_id,
                $progress->position_ms,
                $progress->current_page,
                $progress->total_pages,
                // percent_complete is a DECIMAL(5,2) column; bind a fixed
                // 2-decimal string ("25.50") so the persisted value is
                // deterministic and matches the column scale (locale-safe via
                // number_format's explicit '.' separator). Read back as float
                // in getProgress().
                number_format($progress->percent_complete, 2, '.', ''),
                $progress->last_read_at ?? time(),
            ]
        );
    }
}
