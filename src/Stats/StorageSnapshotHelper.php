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
 * Both paths share {@see collectBuckets()} so the folder map, the vault scan
 * and — critically — the {@see TYPE_TO_BUCKET} fold exist exactly once.
 *
 * @internal Phlix-internal helper for admin dashboard bootstrap.
 *
 * @package Phlix\Stats
 * @since 1.8
 */
final class StorageSnapshotHelper
{
    /**
     * The `stats_storage.media_type` ENUM (migration 019 → 086), i.e. the
     * coarse buckets a snapshot may be recorded under.
     *
     * @var list<string>
     */
    public const BUCKETS = ['movie', 'series', 'music', 'photo', 'book'];

    /**
     * Zeroed starting totals, one entry per {@see BUCKETS} member, so a bucket
     * with no rows is still recorded as an explicit zero rather than vanishing
     * from the dashboard.
     *
     * Spelled out as a literal (rather than built from {@see BUCKETS} in a
     * loop) so the array shape stays statically inferable at PHPStan level 9.
     * `testCollectBucketsAlwaysReturnsEveryBucketEvenWithNoRows` asserts the
     * two constants cannot drift apart.
     *
     * @var array<string, array{count: int, bytes: int}>
     */
    private const EMPTY_BUCKETS = [
        'movie' => ['count' => 0, 'bytes' => 0],
        'series' => ['count' => 0, 'bytes' => 0],
        'music' => ['count' => 0, 'bytes' => 0],
        'photo' => ['count' => 0, 'bytes' => 0],
        'book' => ['count' => 0, 'bytes' => 0],
    ];

    /**
     * EXHAUSTIVE fold of the `media_items.type` ENUM onto {@see BUCKETS}.
     *
     * Every member of {@see \Phlix\Media\MediaItemType::ALL} MUST appear here as
     * a key. A type missing from this map is dropped from the snapshot entirely
     * — silently, since the row simply never reaches a bucket — which is exactly
     * how `track` (the type the music scanner actually writes, see
     * {@see \Phlix\Media\Library\AudioScanner}) came to be excluded from the
     * Music totals, and how `book`/`audiobook` were excluded from everything.
     *
     * The key set is pinned against `MediaItemType::ALL` by
     * {@see \Phlix\Tests\Unit\Media\MediaItemTypeDriftTest}, which also reads the
     * real ENUMs out of the migration SQL — so adding a 14th member without
     * giving it a bucket here is a red test, not a silent under-report.
     *
     * The fold is IDEMPOTENT: every bucket maps to itself, so it is safe to apply
     * to an already-folded value. {@see \Phlix\Stats\StatsCollector::recordStorageSnapshot()}
     * relies on that when it normalises whatever a caller hands it.
     *
     * @var array<string, string>
     */
    public const TYPE_TO_BUCKET = [
        // Video content.
        'movie' => 'movie',
        'video' => 'movie',
        // Series hierarchy — containers and leaves all count as series content.
        'series' => 'series',
        'season' => 'series',
        'episode' => 'series',
        // Music hierarchy. `track` is the scanner's real per-file type;
        // `music`/`album`/`artist` are containers, `audio` the generic leaf.
        'music' => 'music',
        'album' => 'music',
        'artist' => 'music',
        'audio' => 'music',
        'track' => 'music',
        // Stills.
        'photo' => 'photo',
        // Book shelf. `audiobook` is book content that happens to be
        // audio-encoded, so it is shelved with books rather than music.
        'book' => 'book',
        'audiobook' => 'book',
    ];

    /**
     * Map of top-level vault folder name to the bucket its bytes belong to.
     *
     * @var array<string, string>
     */
    private const FOLDER_TO_BUCKET = [
        'anime' => 'movie',
        'movies' => 'movie',
        'tv' => 'series',
        'music' => 'music',
    ];

    /**
     * Filesystem roots scanned for real on-disk sizes.
     *
     * @var list<string>
     */
    private const VAULT_ROOTS = ['/vault1', '/vault2'];

    /**
     * Build the per-bucket item counts and byte totals for one snapshot.
     *
     * Byte totals come from the filesystem (the `file_size` field in
     * `media_items.metadata_json` is never populated); item counts come from
     * the database so they reflect what is actually indexed.
     *
     * @param Connection $db Live MySQL connection
     *
     * @return array<string, array{count: int, bytes: int}> Totals keyed by
     *         bucket, always containing every {@see BUCKETS} member
     *
     * @since 1.8
     */
    public static function collectBuckets(Connection $db): array
    {
        $buckets = self::EMPTY_BUCKETS;

        // Scan filesystem for actual storage sizes.
        foreach (self::VAULT_ROOTS as $vaultRoot) {
            if (!is_dir($vaultRoot)) {
                continue;
            }

            $entries = @scandir($vaultRoot) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $bucket = self::FOLDER_TO_BUCKET[$entry] ?? null;
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

        // Get item counts from database.
        /** @var array<array<string, mixed>> $rows */
        $rows = $db->query(
            "SELECT type, COUNT(*) AS item_count
             FROM media_items
             GROUP BY type"
        );

        foreach ($rows as $row) {
            $type = is_string($row['type'] ?? null) ? $row['type'] : '';
            // TYPE_TO_BUCKET covers the whole column ENUM, so a miss here means
            // the ENUM grew without this map being updated.
            $bucket = self::TYPE_TO_BUCKET[$type] ?? null;
            if ($bucket === null) {
                continue;
            }
            $count = is_numeric($row['item_count'] ?? null) ? (int) $row['item_count'] : 0;
            $buckets[$bucket]['count'] += $count;
        }

        return $buckets;
    }

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
        foreach (self::collectBuckets($db) as $mediaType => $totals) {
            $collector->recordStorageSnapshot($mediaType, $totals['count'], $totals['bytes']);
        }
    }
}
