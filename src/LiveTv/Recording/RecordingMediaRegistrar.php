<?php

/**
 * Phlix media server component: Recording.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Recording;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * Registers a completed DVR recording's captured `.ts` file as a playable
 * `media_items` row and persists the resulting `media_items.id` back onto the
 * `livetv_recordings` row (the `media_item_id` linkage column added by
 * migration 077).
 *
 * Wired as a {@see \Phlix\LiveTv\Recorder} onComplete hook by
 * {@see \Phlix\Common\Container\Providers\LiveTvServicesProvider}. The
 * Recorder fires onComplete EXACTLY once per completion (its atomic
 * compare-and-swap guard, SV-3.1c), so the registration side effect runs once.
 *
 * Reads/writes use the plain-array result convention of the media library layer
 * ({@see ItemRepository}, {@see \Phlix\Media\Library\LibraryManager}) rather
 * than the LiveTv `RowQuery` cursor helpers, because the registrar crosses into
 * the media layer and delegates the actual insert to
 * {@see ItemRepository::upsertByPath()} — the canonical, path-deduped insert
 * path (so a retried completion never creates a second row).
 *
 * ## What is NOT here
 * Comskip / commercial chapter-marker attachment to the real media item is a
 * SEPARATE, DEFERRED sub-step (SV-3.1d-comskip, gated on SV-4.3's non-blocking
 * ComskipRunner). This class only produces + persists the `media_item_id` so
 * that later step has a real item to attach markers to.
 *
 * @since SV-3.1d
 */
final class RecordingMediaRegistrar
{
    /** @var Connection Shared per-worker MySQL connection. */
    private Connection $db;

    /** @var ItemRepository Canonical media-item insert path (path-deduped upsert). */
    private ItemRepository $items;

    /** @var string Name of the video library recordings are registered under. */
    private string $libraryName;

    /** @var StructuredLogger Structured logger instance. */
    private StructuredLogger $logger;

    /**
     * Media type stored for a registered recording.
     *
     * `video` is a valid `media_items.type` enum value (migration 001) and the
     * honest type for a raw captured broadcast (not a curated movie/episode).
     *
     * @var string
     */
    private const MEDIA_TYPE = 'video';

    /**
     * Library type recordings are registered under.
     *
     * `video` is a valid `libraries.type` enum value (migration 001).
     *
     * @var string
     */
    private const LIBRARY_TYPE = 'video';

    /**
     * Creates a new RecordingMediaRegistrar.
     *
     * @param Connection            $db          Shared per-worker MySQL connection.
     * @param ItemRepository        $items       Canonical media-item upsert path.
     * @param string                $libraryName Name of the DVR recordings library.
     * @param StructuredLogger|null $logger      Optional logger (defaults to LiveTV channel).
     *
     * @since SV-3.1d
     */
    public function __construct(
        Connection $db,
        ItemRepository $items,
        string $libraryName = 'DVR Recordings',
        ?StructuredLogger $logger = null
    ) {
        $this->db = $db;
        $this->items = $items;
        $this->libraryName = $libraryName !== '' ? $libraryName : 'DVR Recordings';
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::LIVETV);
    }

    /**
     * Register a completed recording's `.ts` file as a `media_items` row and
     * persist the resulting media_item_id linkage.
     *
     * Guards (each returns null WITHOUT inserting anything):
     *  - recording row not found;
     *  - recording status is not `completed` (onComplete also fires for rows
     *    marked FAILED by {@see \Phlix\LiveTv\Recorder::resumeActiveRecordings()});
     *  - recording is ALREADY linked to a media item (idempotent replay);
     *  - the capture file is missing or zero-length (never register a broken item).
     *
     * The insert itself is delegated to {@see ItemRepository::upsertByPath()},
     * which de-dupes by path, so even a double-fire cannot create a second row.
     *
     * @param string $recordingId   Completed recording id.
     * @param string $recordingPath Absolute path to the captured `.ts` file.
     *
     * @return string|null The `media_items.id`, or null when nothing was registered.
     *
     * @since SV-3.1d
     */
    public function register(string $recordingId, string $recordingPath): ?string
    {
        $recording = $this->fetchRecording($recordingId);
        if ($recording === null) {
            $this->logger->warning('Recording not found; skipping media-item registration', [
                'recording_id' => $recordingId,
            ]);
            return null;
        }

        // Only register genuinely completed captures. onComplete also fires from
        // resumeActiveRecordings() for rows transitioned to FAILED after a
        // worker restart — those must never become library items.
        if (self::str($recording['status'] ?? null) !== 'completed') {
            return null;
        }

        // Idempotency: an already-linked recording returns its existing item id
        // without a second insert (belt to the Recorder's once-only CAS).
        $existing = self::strOrNull($recording['media_item_id'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        // A missing / zero-length capture must never become a broken, unplayable
        // library item — skip and leave media_item_id NULL.
        if ($recordingPath === '' || !is_file($recordingPath) || (int) @filesize($recordingPath) <= 0) {
            $this->logger->warning('Recording file missing or empty; not registering media item', [
                'recording_id' => $recordingId,
                'path' => $recordingPath,
            ]);
            return null;
        }

        $libraryId = $this->ensureRecordingsLibrary($recordingPath);

        $title = self::str($recording['title'] ?? null);
        if ($title === '') {
            $title = 'Untitled Recording';
        }

        $startTime = self::int($recording['start_time'] ?? null);
        $endTime = self::int($recording['end_time'] ?? null);

        // Preserve the recording's EPG/programme context on the media item so
        // the deferred comskip sub-step + the DVR UI can relate the two.
        $metadata = [
            'source' => 'livetv_dvr',
            'recording_id' => $recordingId,
            'channel_id' => self::strOrNull($recording['channel_id'] ?? null),
            'program_id' => self::strOrNull($recording['program_id'] ?? null),
            'description' => self::strOrNull($recording['description'] ?? null),
            'recorded_start' => $startTime,
            'recorded_end' => $endTime,
            'duration_seconds' => max(0, $endTime - $startTime),
        ];

        // Canonical, path-deduped insert: a retried completion returns the same
        // id rather than inserting a duplicate.
        $mediaItemId = $this->items->upsertByPath([
            'library_id' => $libraryId,
            'name' => $title,
            'type' => self::MEDIA_TYPE,
            'path' => $recordingPath,
            'metadata_json' => $metadata,
        ]);

        // Persist the linkage so later processing (SV-3.1d-comskip) can attach to
        // the real media item.
        $this->db->query(
            "UPDATE livetv_recordings SET media_item_id = ?, updated_at = NOW() WHERE recording_id = ?",
            [$mediaItemId, $recordingId]
        );

        $this->logger->info('Recording registered as media item', [
            'recording_id' => $recordingId,
            'media_item_id' => $mediaItemId,
            'library_id' => $libraryId,
        ]);

        return $mediaItemId;
    }

    /**
     * Load a recording row (plain-array media-layer convention).
     *
     * @param string $recordingId The recording id.
     *
     * @return array<string, mixed>|null The row, or null when not found.
     *
     * @since SV-3.1d
     */
    private function fetchRecording(string $recordingId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM livetv_recordings WHERE recording_id = ?",
            [$recordingId]
        );
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        $row = [];
        foreach ($result[0] as $key => $value) {
            if (is_string($key)) {
                $row[$key] = $value;
            }
        }
        return $row;
    }

    /**
     * Find (or create) the dedicated `video` library recordings register under.
     *
     * A direct find-or-create against `libraries` (NOT via LibraryManager) is
     * intentional: recordings are registered individually as they complete, so
     * the library must NOT be attached to a FolderWatcher / scan of the storage
     * path (which would race the registrar and double-register the `.ts` files).
     *
     * @param string $recordingPath Absolute path to a recording (its directory
     *                              seeds the library's `paths` for display).
     *
     * @return string The library id.
     *
     * @since SV-3.1d
     */
    private function ensureRecordingsLibrary(string $recordingPath): string
    {
        $existing = $this->findRecordingsLibraryId();
        if ($existing !== null) {
            return $existing;
        }

        $id = Uuid::v4();
        $storageDir = \dirname($recordingPath);
        try {
            $this->db->query(
                "INSERT INTO libraries (id, name, type, paths, options) VALUES (?, ?, ?, ?, ?)",
                [
                    $id,
                    $this->libraryName,
                    self::LIBRARY_TYPE,
                    json_encode([$storageDir]),
                    json_encode(['dvr' => true]),
                ]
            );
        } catch (\Throwable $e) {
            // A concurrent first-completion may have created the library between
            // our SELECT and INSERT. If a UNIQUE(type, name) index is ever present
            // this INSERT raises a duplicate-key error; either way fall through to
            // the reconciling re-SELECT below rather than propagating.
            $this->logger->debug('DVR recordings library insert raced; reconciling', [
                'error' => $e->getMessage(),
            ]);
        }

        // Re-SELECT the canonical id. Two DIFFERENT recordings completing
        // concurrently before the library first exists can each miss the initial
        // SELECT and both INSERT (there is no UNIQUE(type, name) constraint on
        // `libraries`). The deterministic ORDER BY in findRecordingsLibraryId()
        // makes EVERY caller converge on the SAME single library id, so recordings
        // are never split across duplicate DVR libraries — the method returns the
        // one canonical id under concurrency.
        $canonical = $this->findRecordingsLibraryId();
        if ($canonical !== null) {
            if ($canonical === $id) {
                $this->logger->info('Created DVR recordings library', [
                    'library_id' => $id,
                    'name' => $this->libraryName,
                ]);
            } else {
                $this->logger->info('Reused existing DVR recordings library after concurrent create', [
                    'library_id' => $canonical,
                    'name' => $this->libraryName,
                ]);
            }
            return $canonical;
        }

        // Extremely defensive: the re-SELECT should always see our own INSERT.
        return $id;
    }

    /**
     * Look up the canonical DVR recordings library id.
     *
     * A deterministic `ORDER BY created_at ASC, id ASC` (not a bare `LIMIT 1`,
     * whose row order is undefined) so that if a concurrent first-completion race
     * ever creates two rows, every caller converges on the SAME single library —
     * the earliest-created one, id-tie-broken — instead of picking arbitrarily.
     *
     * @return string|null The canonical library id, or null when none exists yet.
     *
     * @since SV-3.1-rowquery
     */
    private function findRecordingsLibraryId(): ?string
    {
        $result = $this->db->query(
            "SELECT id FROM libraries WHERE type = ? AND name = ?
             ORDER BY created_at ASC, id ASC LIMIT 1",
            [self::LIBRARY_TYPE, $this->libraryName]
        );
        if (is_array($result) && isset($result[0]) && is_array($result[0])) {
            $id = $result[0]['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * Coerce a mixed row value to a string ('' when not scalar).
     *
     * @param mixed $value Raw row value.
     *
     * @since SV-3.1d
     */
    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Coerce a mixed row value to a non-empty string or null.
     *
     * @param mixed $value Raw row value.
     *
     * @since SV-3.1d
     */
    private static function strOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Coerce a mixed row value to an int (0 when non-numeric/absent).
     *
     * @param mixed $value Raw row value.
     *
     * @since SV-3.1d
     */
    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return 0;
    }
}
