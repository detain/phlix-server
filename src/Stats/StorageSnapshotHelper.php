<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Stats;

use Workerman\MySQL\Connection;

/**
 * Helper for bootstrapping storage snapshots from PHP-FPM context.
 *
 * When the Workerman daemon is running, storage snapshots are recorded
 * periodically via Application::startStorageSnapshotTimer(). When served
 * via PHP-FPM (e.g., admin dashboard), this helper provides a one-time
 * snapshot so the dashboard has data even without the daemon.
 *
 * @internal Phlix-internal helper for admin dashboard bootstrap.
 *
 * @package Phlix\Stats
 * @since 1.8
 */
final class StorageSnapshotHelper
{
    /**
     * Record one storage snapshot if data is stale or missing.
     *
     * This is a one-time bootstrap for PHP-FPM context. It scans the
     * filesystem at /vault1 and /vault2 to get real storage sizes and
     * queries the database for item counts, then records a snapshot.
     *
     * @param StatsCollector $collector Collector to write through
     * @param Connection     $db        Live MySQL connection
     *
     * @return void
     *
     * @since 1.8
     */
    public static function bootstrapSnapshot(
        StatsCollector $collector,
        Connection $db
    ): void {
        // Map storage folders to media type buckets
        // anime/ and movies/ -> movie, tv/ -> series, music/ -> music
        $folderToBucket = [
            'anime' => 'movie',
            'movies' => 'movie',
            'tv' => 'series',
            'music' => 'music',
        ];

        // Initialize buckets with filesystem-sourced sizes and DB-sourced counts
        $buckets = [
            'movie' => ['count' => 0, 'bytes' => 0],
            'series' => ['count' => 0, 'bytes' => 0],
            'music' => ['count' => 0, 'bytes' => 0],
            'photo' => ['count' => 0, 'bytes' => 0],
        ];

        // Scan filesystem for actual storage sizes
        $vaultRoots = ['/vault1', '/vault2'];
        foreach ($vaultRoots as $vaultRoot) {
            if (!is_dir($vaultRoot)) {
                continue;
            }

            $entries = @scandir($vaultRoot) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $bucket = $folderToBucket[$entry] ?? null;
                if ($bucket === null) {
                    continue;
                }
                $dirPath = $vaultRoot . '/' . $entry;
                if (!is_dir($dirPath)) {
                    continue;
                }
                // Use du -sb to get apparent size in bytes (follows symlinks)
                $output = @shell_exec('du -sb ' . escapeshellarg($dirPath));
                if (!is_string($output)) {
                    continue;
                }
                $matches = [];
                if (preg_match('/^(\d+)/', $output, $matches) === 1) {
                    $buckets[$bucket]['bytes'] += (int) $matches[1];
                }
            }
        }

        // Get item counts from database
        /** @var array<array<string, mixed>> $rows */
        $rows = $db->query(
            "SELECT type, COUNT(*) AS item_count
             FROM media_items
             GROUP BY type"
        );

        // Fold the granular media_items.type ENUM into the four buckets the
        // dashboard / stats_storage ENUM supports. Types with no bucket
        // (e.g. book, video) are intentionally dropped.
        $typeToBucket = [
            'movie' => 'movie',
            'series' => 'series', 'season' => 'series', 'episode' => 'series',
            'music' => 'music', 'album' => 'music', 'artist' => 'music', 'audio' => 'music',
            'photo' => 'photo',
        ];

        foreach ($rows as $row) {
            $type = is_string($row['type'] ?? null) ? $row['type'] : '';
            $bucket = $typeToBucket[$type] ?? null;
            if ($bucket === null) {
                continue;
            }
            $count = is_numeric($row['item_count'] ?? null) ? (int) $row['item_count'] : 0;
            $buckets[$bucket]['count'] += $count;
        }

        foreach ($buckets as $mediaType => $totals) {
            $collector->recordStorageSnapshot($mediaType, $totals['count'], $totals['bytes']);
        }
    }
}
