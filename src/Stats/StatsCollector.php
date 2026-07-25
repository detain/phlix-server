<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Stats;

use DateTimeInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Uuid;
use Phlix\Media\MediaItemType;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Stats collector for aggregating playback, library, user activity, and storage data.
 *
 * This service records events into the stats_* tables and provides aggregation
 * queries for the admin dashboard (top users, top media, playback time series).
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Collects and aggregates statistics for the Phlix Media Server.
 */
class StatsCollector
{
    /**
     * Symbolic names of every WRITE routed through {@see write()}.
     *
     * Every failure-counter key starts with one of these values (anything else
     * folds into {@see OTHER_OPERATION}), which is what BOUNDS
     * {@see $writeFailures} to a compile-time vocabulary — six operations crossed
     * with the exception classes the code can raise. Nothing a request can
     * influence ever becomes part of a key, so the map cannot grow in a resident
     * worker.
     *
     * @var list<string>
     */
    private const WRITE_OPERATIONS = [
        'playback_start',
        'playback_end',
        'library_change',
        'user_activity',
        'storage_snapshot',
    ];

    /** Counter bucket for an operation name outside {@see WRITE_OPERATIONS}. */
    private const OTHER_OPERATION = 'other';

    /**
     * Minimum seconds between two logged failures for the SAME operation AND the
     * SAME exception class.
     *
     * `ItemRepository::recordChange()` calls {@see recordLibraryChange()} once per
     * scanned item, so an unthrottled boundary emitted one `error` line per item —
     * ~29,000 lines for the production music library's 29,245 tracks (S102 review
     * r1 LOW-5). Suppressed failures are still COUNTED and the count is reported
     * on the next line that does get through, so the condition stays visible
     * without drowning the log.
     */
    private const FAILURE_LOG_INTERVAL_SECONDS = 60;

    /**
     * Maximum gap between two consecutive storage writes for the second one to still
     * count as part of the same run.
     *
     * The comparison is `<` ({@see snapshotRunSecond()}), so a gap of exactly
     * 5.000000000 s already starts a NEW run — "5 s or more", not "more than 5 s".
     * (S102 review r3, C1: the docs said the latter. The words were wrong, not the
     * comparison, so the words were fixed.)
     *
     * Deliberately a gap and not a total duration: measured, 13
     * `recordStorageSnapshot()` calls a full second apart (13 s end to end) stay one
     * generation and round-trip all 91,000 bytes, where a 5-second TOTAL-duration
     * window split them into three generations and lost 23,000. Tiny next to the
     * six-hourly cadence at which the two live callers record snapshots, so two runs
     * cannot be merged in practice; enormous next to the milliseconds a batch or a
     * 13-call loop actually takes.
     *
     * ## What 5 s does NOT buy, so nobody builds on it (S102 review r3, LOW-1b)
     *
     * This window cannot tell a STALLED run from a BUSY one. All it ever sees is the
     * interval between two WRITES — and for a hypothetical per-library writer that
     * interval IS the next library's `du -sb`. Measured twice on the dev box with a
     * WARM page cache: ~333 k inodes in 2.20 s (151 k inodes/s, three other MySQL
     * containers running) and in 1.30 s (257 k inodes/s, quieter), so 5 s buys only
     * something like 0.75–1.3 M inodes even warm — and a snapshot on a 6-HOURLY
     * cadence always meets a COLD cache, where a seek-bound or network-backed vault
     * is slower again. A three-library run at 30 s per `du` would become three
     * generations, and the reader's per-`media_type` `MAX(recorded_at)` would then
     * keep only the LAST library's rows: exactly the loss this stamp exists to
     * prevent. So an earlier version of this docblock, which justified 5 s with "a run
     * can take minutes in total while never pausing for seconds", was wrong: for a
     * per-library writer the pause and the work are the same interval.
     *
     * It is INERT for the two live callers, and that is the only reason 5 s is
     * defensible: both hand the whole run to ONE
     * {@see recordStorageSnapshots()} call, which takes the stamp once
     * (see the local `$recordedAt`) BEFORE its write loop — so the window is never
     * consulted between one run's own rows. It only ever separates one CALL from the
     * next. A future per-library writer must therefore NOT rely on this window to
     * hold a run together: it has to pass the whole run through one
     * `recordStorageSnapshots()` call, or carry its own explicit stamp.
     */
    private const SNAPSHOT_RUN_MAX_GAP_SECONDS = 5;

    /**
     * Write-failure counters, shared across every instance in this worker process,
     * keyed by `"<operation>|<exception class>"`.
     *
     * Static because the counter has to survive across the short-lived
     * `StatsCollector` instances the DI container hands out, and because a
     * process-wide count is the useful one. This is NOT request state: the keys are
     * the fixed {@see WRITE_OPERATIONS} vocabulary (at most six values) crossed
     * with the exception classes the driver and PHP itself can raise — both
     * compile-time sets, neither influenced by anything a request carries — and the
     * values are one string plus three ints, so it cannot leak memory in a resident
     * worker. (S102 review r2 LOW-6: keying the throttle window on the operation
     * ALONE meant that once `library_change` had logged a `RuntimeException`, a
     * `PDOException "MySQL server has gone away"` inside the same 60 s window was
     * counted but never described. A different failure CLASS is different news.)
     *
     * `logged_at_ns` uses `hrtime()`, never `time()`, so a clock adjustment
     * cannot make the throttle window jump. (`hrtime(true)` is `int` on 64-bit
     * builds and `float` on 32-bit ones, hence the union.)
     *
     * @var array<string, array{operation: string, failures: int, suppressed: int, logged_at_ns: float|int}>
     */
    private static array $writeFailures = [];

    /**
     * `recorded_at` (unix seconds) shared by every row of the snapshot run in
     * progress, or 0 before the first row. See {@see snapshotRunSecond()}.
     */
    private int $snapshotRunSecond = 0;

    /**
     * Monotonic timestamp of the MOST RECENT storage write, which is what the
     * run-gap window in {@see snapshotRunSecond()} is measured from.
     *
     * `hrtime()`, never `time()`, so a clock adjustment cannot stretch or collapse
     * the run window. (`int` on 64-bit builds, `float` on 32-bit ones.)
     */
    private float|int $snapshotRunLastWriteNs = 0;

    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    /**
     * Create a new StatsCollector instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     *
     * @example
     * ```php
     * $collector = new StatsCollector($db);
     * ```
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a UUID v4 string.
     */
    /**
     * Are usage statistics being recorded?
     *
     * Backs the `stats.enabled` setting. Read at call time through
     * {@see \Phlix\Config\EffectiveConfig::file()} (which memoises per
     * bootstrap generation), so an admin override applies on reload without a
     * full restart.
     *
     * Guarding HERE rather than at the ~52 call sites is deliberate: a switch
     * that each caller had to remember to consult would be honoured
     * inconsistently, which is the "half-effective setting" failure this
     * settings program keeps running into.
     *
     * Defaults to TRUE when the key is absent so existing installs keep
     * recording exactly as before.
     *
     * @return bool
     *
     * @since 1.3.0
     */
    public function isEnabled(): bool
    {
        return (\Phlix\Config\EffectiveConfig::file('stats')['enabled'] ?? true) !== false;
    }

    private function generateUuid(): string
    {
        return Uuid::v4();
    }

    /**
     * Convert a mixed value to string.
     *
     * @param mixed $value The value to convert
     *
     * @return string The string value
     */
    private function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Convert a mixed value to int.
     *
     * @param mixed $value The value to convert
     *
     * @return int The integer value
     */
    private function toInt(mixed $value): int
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
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return 0;
    }

    /**
     * Run one telemetry WRITE, containing any failure inside the stats subsystem.
     *
     * ## Why this boundary exists (S102)
     *
     * Statistics are telemetry: recording them is strictly less important than
     * the user action that triggered them. Before this method every `record*()`
     * call let the driver's exception escape straight to the caller — and
     * `PlaybackController::dispatchPlaybackStarted()` has no try/catch, so a
     * single bad column value took playback-start down with it. In production
     * that is exactly what happened: `media_type = 'episode'` against migration
     * 019's four-member ENUM raised
     * `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'media_type'`,
     * which surfaced as an unhandled `PDOException` in the HTTP worker on EVERY
     * episode play. Widening the ENUM fixes that one value; this boundary makes
     * the whole class of failure (a stats table missing, a column drifted, the
     * connection dropped) non-fatal to playback.
     *
     * ## Deliberately narrow
     *
     * Only WRITES are wrapped. The read/aggregation methods
     * ({@see getPlaybackStats()}, {@see getTopUsers()}, {@see getTopMedia()})
     * are NOT: they serve the admin dashboard, where a broken query must surface
     * as a visible error rather than as a silently empty chart, and none of them
     * sits on a playback path.
     *
     * Failures are logged at **error** level, so they land in
     * `.logs/error-YYYY-MM-DD.log` (the `error` handler is a `rotating_file`, so
     * the configured `error.log` path is date-stamped on disk, and it is untagged
     * and therefore attaches to every channel) and are visible to anyone watching
     * the error log — swallowed, but never silent. The SQL is included because
     * these statements are static literals with bound parameters; the parameters
     * themselves are NOT logged, since they carry user ids and client IPs.
     *
     * Repeated failures are THROTTLED and COUNTED rather than logged one-per-event
     * ({@see noteWriteFailure()}), and the running totals are readable through
     * {@see writeFailureCounters()} so a persistently degraded stats subsystem is
     * countable rather than only greppable.
     *
     * This mirrors the reasoning behind {@see isEnabled()}: guarding HERE rather
     * than at the ~52 call sites means the guarantee cannot be forgotten by a
     * future caller.
     *
     * @param string             $operation Symbolic name of the write, for the log.
     * @param string             $sql       Static SQL statement.
     * @param array<int, mixed>  $params    Bound parameters.
     *
     * @return bool True when the write landed, false when it was contained.
     *
     * @since 1.9
     */
    private function write(string $operation, string $sql, array $params): bool
    {
        try {
            $this->db->query($sql, $params);
            return true;
        } catch (Throwable $e) {
            $this->noteWriteFailure($operation, $e, $sql);
            return false;
        }
    }

    /**
     * Count a contained write failure and log it, at most once per operation PER
     * EXCEPTION CLASS per {@see FAILURE_LOG_INTERVAL_SECONDS}.
     *
     * The first failure of an operation always logs immediately (so a broken
     * subsystem is loud straight away); subsequent failures of the same operation
     * AND the same exception class inside the window are counted into `suppressed`
     * and reported on the next line that does get through. Both `failures_total`
     * and `suppressed_since_last_log` are in the context, so the log itself answers
     * "how bad is it?" rather than requiring a `wc -l` over identical lines.
     *
     * ## Why the exception class is part of the window key (S102 review r2, LOW-6)
     *
     * Keyed on the operation alone, the throttle suppressed a NEW KIND of failure
     * for an already-failing operation: once `library_change` had logged a
     * `RuntimeException`, a `PDOException "MySQL server has gone away"` in the same
     * 60 s window was counted but its message never reached the log (measured: two
     * failures, two classes, ONE line). A different exception class is different
     * news — the throttle exists to stop 29,000 copies of the SAME line, not to
     * hide the second symptom. `failures_total` is per operation+class, and
     * `operation_failures_total` keeps the "how bad is the operation?" answer that
     * r1's LOW-5 asked for.
     *
     * @param string    $operation Symbolic write name (see {@see WRITE_OPERATIONS}).
     * @param Throwable $e         The contained failure.
     * @param string    $sql       Static SQL statement (no bound parameters).
     *
     * @return void
     *
     * @since 1.9
     */
    private function noteWriteFailure(string $operation, Throwable $e, string $sql): void
    {
        $bucket = in_array($operation, self::WRITE_OPERATIONS, true) ? $operation : self::OTHER_OPERATION;
        $key = $bucket . '|' . $e::class;
        $state = self::$writeFailures[$key]
            ?? ['operation' => $bucket, 'failures' => 0, 'suppressed' => 0, 'logged_at_ns' => 0];
        $state['failures']++;

        $nowNs = hrtime(true);
        $windowNs = self::FAILURE_LOG_INTERVAL_SECONDS * 1_000_000_000;

        if ($state['logged_at_ns'] !== 0 && ($nowNs - $state['logged_at_ns']) < $windowNs) {
            $state['suppressed']++;
            self::$writeFailures[$key] = $state;

            return;
        }

        $suppressed = $state['suppressed'];
        $state['suppressed'] = 0;
        $state['logged_at_ns'] = $nowNs;
        self::$writeFailures[$key] = $state;

        LoggerFactory::get(LogChannels::APPLICATION)->error(
            'Stats write failed and was contained; the triggering action is unaffected',
            [
                'operation' => $bucket,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                'failures_total' => $state['failures'],
                'suppressed_since_last_log' => $suppressed,
                'operation_failures_total' => self::writeFailureCounters()[$bucket]['failures'] ?? $state['failures'],
            ]
        );
    }

    /**
     * Contained write failures so far in this worker, keyed by operation.
     *
     * Exposed so the condition is countable — a stats subsystem that has been
     * failing every write for a week is otherwise invisible except as repeated
     * log lines. `suppressed` is the number of failures counted but not logged
     * since the last line that was emitted.
     *
     * The internal counters are keyed per operation AND exception class (the LOW-6
     * throttle fix); this reporting surface stays keyed per OPERATION, summing the
     * classes, so the operator-facing shape did not change when the window did.
     *
     * @return array<string, array{failures: int, suppressed: int}>
     *
     * @since 1.9
     */
    public static function writeFailureCounters(): array
    {
        /** @var array<string, array{failures: int, suppressed: int}> $out */
        $out = [];
        foreach (self::$writeFailures as $state) {
            $operation = $state['operation'];
            if (!isset($out[$operation])) {
                $out[$operation] = ['failures' => 0, 'suppressed' => 0];
            }
            $out[$operation]['failures'] += $state['failures'];
            $out[$operation]['suppressed'] += $state['suppressed'];
        }

        return $out;
    }

    /**
     * Clear the failure counters (test seam; also usable after an operator has
     * acknowledged a fault).
     *
     * @return void
     *
     * @since 1.9
     */
    public static function resetWriteFailureCounters(): void
    {
        self::$writeFailures = [];
    }

    /**
     * Record a playback start event.
     *
     * `$mediaType` is stored VERBATIM: `stats_playback_events.media_type` carries
     * the full 13-member `media_items.type` vocabulary (migration 094), so an
     * `episode` play is recorded as `episode` rather than folded into `series`.
     * A value outside {@see MediaItemType::ALL} would be rejected by the column
     * (MySQL error 1265 under `STRICT_TRANS_TABLES`), so it is coerced to
     * {@see MediaItemType::FALLBACK} and logged as a warning — a future ENUM
     * member shows up in the log instead of losing the whole event.
     *
     * @param string $userId User UUID starting playback
     * @param string $mediaItemId Media item UUID being played
     * @param string $mediaType Raw `media_items.type` value; see MediaItemType::ALL
     * @param string|null $deviceId Optional device identifier
     *
     * @return string Event ID for later completion via recordPlaybackEnd
     *
     * @example
     * ```php
     * $eventId = $collector->recordPlaybackStart('user-123', 'media-456', 'episode', 'device-789');
     * ```
     */
    public function recordPlaybackStart(
        string $userId,
        string $mediaItemId,
        string $mediaType,
        ?string $deviceId = null
    ): string {
        $eventId = $this->generateUuid();

        // Still returns a well-formed id when disabled: callers hand it to
        // recordPlaybackEnd(), which is guarded too, so the contract holds and
        // no caller needs to know statistics are off.
        if (!$this->isEnabled()) {
            return $eventId;
        }
        $clientIp = null;
        $storedType = MediaItemType::normalize($mediaType);
        if ($storedType !== $mediaType) {
            LoggerFactory::get(LogChannels::APPLICATION)->warning(
                'Playback stats received a media type outside the media_items.type ENUM; '
                . 'recording it under the fallback type instead',
                [
                    'received' => $mediaType,
                    'recorded_as' => $storedType,
                    'media_item_id' => $mediaItemId,
                ]
            );
        }

        $this->write(
            'playback_start',
            "INSERT INTO stats_playback_events
             (id, user_id, media_item_id, media_type, started_at, device_id, client_ip)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)",
            [$eventId, $userId, $mediaItemId, $storedType, $deviceId, $clientIp]
        );

        return $eventId;
    }

    /**
     * Record a playback end event.
     *
     * @param string $eventId Event ID from recordPlaybackStart
     * @param int $durationSeconds Duration of playback in seconds
     * @param bool $completed Whether playback was completed
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordPlaybackEnd($eventId, 3600, true);
     * ```
     */
    public function recordPlaybackEnd(string $eventId, int $durationSeconds, bool $completed): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->write(
            'playback_end',
            "UPDATE stats_playback_events
             SET ended_at = NOW(), duration_seconds = ?, completed = ?
             WHERE id = ?",
            [$durationSeconds, $completed, $eventId]
        );
    }

    /**
     * Record a library change event.
     *
     * @param string $changeType Change type (item_added, item_removed, metadata_updated)
     * @param string|null $mediaItemId Media item UUID if applicable
     * @param string|null $libraryId Library UUID if applicable
     * @param string|null $userId User UUID who triggered the change
     * @param array<string, mixed> $details Additional details as key-value pairs
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordLibraryChange('item_added', 'media-456', null, null, ['path' => 'movie.mkv']);
     * ```
     */
    public function recordLibraryChange(
        string $changeType,
        ?string $mediaItemId = null,
        ?string $libraryId = null,
        ?string $userId = null,
        array $details = []
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $id = $this->generateUuid();
        $detailsJson = $details !== [] ? json_encode($details) : null;

        $this->write(
            'library_change',
            "INSERT INTO stats_library_changes
             (id, change_type, media_item_id, library_id, user_id, changed_at, details_json)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)",
            [$id, $changeType, $mediaItemId, $libraryId, $userId, $detailsJson]
        );
    }

    /**
     * Record a user activity event.
     *
     * @param string $userId User UUID performing the activity
     * @param string $activityType Activity type (login, logout, search, profile_change)
     * @param string|null $ipAddress IP address of the user
     * @param array<string, mixed> $details Additional details as key-value pairs
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordUserActivity('user-123', 'login', '192.168.1.1', ['device' => 'Chrome']);
     * ```
     */
    public function recordUserActivity(
        string $userId,
        string $activityType,
        ?string $ipAddress = null,
        array $details = []
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $id = $this->generateUuid();
        $userAgent = null;
        $detailsJson = $details !== [] ? json_encode($details) : null;

        $this->write(
            'user_activity',
            "INSERT INTO stats_user_activity
             (id, user_id, activity_type, occurred_at, ip_address, user_agent, details_json)
             VALUES (?, ?, ?, NOW(), ?, ?, ?)",
            [$id, $userId, $activityType, $ipAddress, $userAgent, $detailsJson]
        );
    }

    /**
     * Record ONE storage snapshot row.
     *
     * A single-entry call into {@see recordStorageSnapshots()}, which is where the
     * fold, the per-bucket SUM and the unknown-type rejection live. Prefer the
     * batch form whenever more than one media type is being recorded in the same
     * snapshot run: two raw types folding to the same bucket must become ONE
     * summed row, and only the batch form can see that.
     *
     * Calling this in a LOOP is nonetheless safe now, including across a wall-clock
     * second boundary: every row of a run shares one `recorded_at`
     * ({@see snapshotRunSecond()}, whose one bound is a gap of
     * {@see SNAPSHOT_RUN_MAX_GAP_SECONDS} seconds OR MORE between two calls), so the
     * reader's per-`media_type` `MAX(recorded_at)` join sees all of them and sums them.
     * Before that stamp,
     * thirteen calls spread over three seconds lost 47,000 of 91,000 bytes with no
     * error anywhere (S102 review r2, MED-2). The rows are still one-per-call
     * rather than one-per-bucket, which is why the batch form remains the paved
     * road.
     *
     * @param string $mediaType Bucket name, or any `media_items.type` value to fold
     * @param int $itemCount Number of items of this type
     * @param int $totalBytes Total bytes used by this media type
     * @param int $transcodeCacheBytes Transcode cache bytes used
     * @param string|null $libraryId Library UUID if applicable
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordStorageSnapshot('movie', 150, 50000000000, 2000000000, 'lib-123');
     * ```
     */
    public function recordStorageSnapshot(
        string $mediaType,
        int $itemCount,
        int $totalBytes,
        int $transcodeCacheBytes = 0,
        ?string $libraryId = null
    ): void {
        $this->recordStorageSnapshots(
            [$mediaType => ['count' => $itemCount, 'bytes' => $totalBytes, 'cache' => $transcodeCacheBytes]],
            $libraryId
        );
    }

    /**
     * Record one storage snapshot RUN: exactly one row per coarse bucket.
     *
     * ## Coarse buckets, not raw types (S102)
     *
     * Unlike {@see recordPlaybackStart()}, `stats_storage.media_type` is
     * deliberately a COARSE column: five buckets
     * ({@see StorageSnapshotHelper::BUCKETS}), because it has a real reader —
     * `DashboardService::getStorageSummary()` groups by it and matches the result
     * into a fixed `{movie,series,music,photo,book}_bytes` shape, so a raw
     * 13-member type would land in that `match`'s `default => null` arm and its
     * bytes would vanish from the dashboard totals. Widening this column would
     * therefore BREAK a consumer; widening `stats_playback_events.media_type`
     * (which has no readers) does not.
     *
     * So instead of widening, the fold is enforced here, at the only writer, via
     * {@see StorageSnapshotHelper::TYPE_TO_BUCKET} (exhaustive over
     * {@see \Phlix\Media\MediaItemType::ALL} and idempotent, since every bucket
     * maps to itself).
     *
     * ## Why this is a BATCH (S102 review r1, MED-2)
     *
     * Folding alone is not enough: several raw types map to the SAME bucket
     * (`series`/`season`/`episode` → `series`, five music types → `music`), and a
     * fold applied one call at a time emitted one row PER CALL. All of those rows
     * carry the same `NOW()` second, so `getStorageSummary()`'s
     * `MAX(recorded_at)` join returned every one of them for the same bucket.
     * Measured on real MySQL, writing all 13 types that way put only **31,000 of
     * 91,000** bytes into the five headline totals — worse than the widening this
     * design rejects. Summing per bucket BEFORE the INSERT means one snapshot run
     * is one row per bucket, which is what the reader's grouping assumes.
     *
     * ## What keeping the fold costs, honestly (S102 review r2, item 6)
     *
     * The alternative was to NARROW this entry point to {@see
     * StorageSnapshotHelper::BUCKETS} and refuse raw types loudly. "It fails the
     * 91,000-byte round-trip" is not an argument against narrowing, because that
     * metric only exists BECAUSE the fold is part of this writer's contract — the
     * very thing narrowing questions. The real reasons to keep the fold are that
     * `TYPE_TO_BUCKET` then lives at exactly ONE writer (no future caller has to
     * re-derive it, and no caller can fold differently), and that both live callers
     * already hand over bucket names, so they are untouched either way. The cost is
     * real and was not free: narrowing would have made the multi-second straddle in
     * {@see snapshotRunSecond()} structurally IMPOSSIBLE — one row per bucket per
     * run cannot collide with itself — whereas keeping the fold made a run's
     * one-timestamp stamp a requirement instead of a nicety.
     *
     * ## An unmapped type is DROPPED, loudly (S102 review r1, MED-3)
     *
     * A media type with no bucket is logged at **error** and written NOWHERE. It
     * is deliberately NOT attributed to {@see \Phlix\Media\MediaItemType::FALLBACK}
     * (`movie`): `migrations/086_stats_storage_book_bucket.sql:11-14` writes the
     * rule down — *"counting their bytes as Music produces a wrong number that
     * looks right, which is worse than a visibly missing one."* `FALLBACK` is
     * right for a LABEL (`normalize()`, `shape()`, `lookupMediaType()`) and wrong
     * for a byte accumulator. `TYPE_TO_BUCKET` is pinned exhaustive against
     * `MediaItemType::ALL` by
     * {@see \Phlix\Tests\Unit\Media\MediaItemTypeDriftTest}, so this branch is
     * unreachable for any real column value.
     *
     * ## The run stamp is taken BEFORE the fold can reject everything (r3, C2)
     *
     * `$recordedAt` is computed before {@see foldStorageTotals()} runs, so a call whose
     * every type is unmapped writes ZERO rows and has still started (or extended) a
     * run. Deliberate and harmless: the worst effect is that a real run starting a few
     * seconds later inherits a stamp a few seconds old, which changes no reader —
     * `getStorageSummary()` joins on `MAX(recorded_at)` per bucket and does not care
     * which second inside the window a generation is labelled with. It is NOT reordered,
     * because taking the stamp once, up front, into a LOCAL is what guarantees that a
     * concurrent coroutine mutating the instance field mid-loop cannot tear one call's
     * rows across two seconds; folding first would buy nothing observable and would move
     * that hoist. Documented rather than "fixed" so the next reader does not read the
     * ordering as an oversight.
     *
     * @param array<string, array{count: int, bytes: int, cache?: int}> $totals
     *        Item counts and byte totals keyed by bucket name or raw
     *        `media_items.type` value; `cache` (transcode cache bytes) defaults to 0.
     * @param string|null $libraryId Library UUID if the run is scoped to one library
     *
     * @return void
     *
     * @example
     * ```php
     * $collector->recordStorageSnapshots([
     *     'episode' => ['count' => 300, 'bytes' => 4_000],
     *     'season'  => ['count' => 20,  'bytes' => 3_000],
     * ]);
     * // -> ONE `series` row: item_count 320, total_bytes 7_000
     * ```
     *
     * @since 1.9
     */
    public function recordStorageSnapshots(array $totals, ?string $libraryId = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $recordedAt = $this->snapshotRunSecond();

        foreach ($this->foldStorageTotals($totals) as $bucket => $summed) {
            $this->write(
                'storage_snapshot',
                "INSERT INTO stats_storage
                 (id, library_id, media_type, item_count, total_bytes, transcode_cache_bytes, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))",
                [
                    $this->generateUuid(),
                    $libraryId,
                    $bucket,
                    $summed['count'],
                    $summed['bytes'],
                    $summed['cache'],
                    $recordedAt,
                ]
            );
        }
    }

    /**
     * The ONE `recorded_at` second every row of the current snapshot run carries.
     *
     * ## Why a run needs one timestamp (S102 review r2, MED-2)
     *
     * `stats_storage.recorded_at` is a second-precision `DATETIME` and
     * `DashboardService::getStorageSummary()` joins on `MAX(recorded_at)` **per
     * `media_type`**, then SUMS the rows of that second. So every row a run writes
     * has to land on the SAME second or the reader keeps only the last second's
     * rows for any bucket that received more than one. With a fresh `NOW()` per
     * INSERT that was a coin toss decided by wall-clock luck: measured on this box
     * at load 14, thirteen `recordStorageSnapshot()` calls spread over THREE
     * seconds and the dashboard reported **44,000 of the 91,000 bytes handed in**
     * — 47,000 lost, silently. (The batch form survived by accident, because one
     * row per bucket makes the per-type `MAX` a no-op; that is luck, not a
     * guarantee, and it stopped being true the moment a caller looped.)
     *
     * A run is therefore stamped once: the first storage write computes the second,
     * and every write whose gap to the PREVIOUS one is strictly less than
     * {@see SNAPSHOT_RUN_MAX_GAP_SECONDS} reuses that value. So the property holds
     * however many calls the run is spread over and however long the run takes in
     * total — what ends a run is a STALL, not elapsed time. (Measured: 13 calls a
     * second apart, 13 s end to end, one generation, 91,000 of 91,000 bytes. A
     * total-duration window instead of a gap window split that same run into three
     * generations and lost 23,000 — which is why this is a gap.) After such a stall
     * the next write starts a fresh generation, i.e. it degrades to the old
     * `NOW()`-per-INSERT behaviour rather than to something worse, and the stamp can
     * never be frozen indefinitely by a stopped caller.
     *
     * The boundary is `<`, so a gap of exactly 5.000000000 s ALREADY starts a new run
     * — "5 s or more", not "more than 5 s" (S102 review r3, C1; unobservable at
     * `hrtime()` granularity, but the docs used to state the opposite of the code).
     * Bracketed by {@see \Phlix\Tests\Unit\Stats\StatsCollectorTest::testAStallLongerThanTheGapWindowStartsANewRun},
     * which discriminates "continued" from "recomputed" with a sentinel stamp instead
     * of comparing two `time()` reads that are equal either way — the r3 MED-1 hole
     * that let the whole gap arm be deleted with a green suite.
     *
     * `FROM_UNIXTIME(?)` — not a PHP-formatted string — is
     * what keeps the value identical to what `NOW()` would have produced: both are
     * evaluated in the MySQL session's time zone, so a PHP/MySQL time-zone
     * disagreement cannot offset a snapshot (which would then also poison
     * {@see StorageSnapshotHelper::bootstrapSnapshot()}'s staleness check).
     *
     * ## How far the stamp can lag the wall clock: without bound (r3, LOW-1b)
     *
     * There is no absolute cap. A caller that keeps writing more often than every
     * {@see SNAPSHOT_RUN_MAX_GAP_SECONDS} extends the SAME generation indefinitely:
     * measured, 12 writes one second apart left `recorded_at` **11 s** behind `NOW()`
     * with all 12 rows on one second. Only a *stopped* caller cannot freeze it. That
     * is by design — one generation per run is the whole point — but "the stamp can
     * never be frozen for the life of a worker" (as an earlier S102 note put it) is
     * false; the precise statement is the one above, about a stopped caller.
     * Unreachable on the live paths, which write once per 6 h.
     *
     * ## Per-INSTANCE — which is NOT per-coroutine (r3, LOW-1a)
     *
     * A run is what ONE holder of a collector does, so the memo lives on the instance,
     * not in a static: the state is two scalars, carries no request data, and is
     * overwritten rather than appended, so it cannot grow in a resident worker. Two
     * collector INSTANCES therefore keep separate stamps and cannot merge into one
     * generation — that, and only that, is what
     * {@see \Phlix\Tests\Unit\Stats\StatsCollectorTest::testTheRunStampDoesNotLeakBetweenCollectorInstances}
     * establishes.
     *
     * ⚠ It does NOT follow that two coroutines get separate stamps.
     * `Common\Container\Providers\AdminServicesProvider` registers
     * `StatsCollector::class => autowire()`, and php-di's `autowire()` is a SINGLETON
     * per container — two `$container->get(StatsCollector::class)` calls return the
     * same object (measured: identical `spl_object_id`). So two coroutines that both
     * resolve the collector from the container share ONE stamp and their runs merge
     * into one generation, which the reader then SUMS: measured, two container-resolved
     * runs a second apart reported 2,000 bytes for a real 1,000 (2×).
     *
     * Latent today, and only by luck of the caller list: the one container-resolved
     * snapshot writer is `public/index.php`'s PHP-FPM bootstrap (one request per
     * process, no coroutines), and the daemon timer CONSTRUCTS its own collector
     * (`Server\Core\Application::startStorageSnapshotTimer()`). It becomes reachable the
     * moment a Workerman task takes the snapshot with `$container->get(...)`, which is
     * the obvious way to write it. Such a caller must construct its own collector (or
     * carry its own stamp) — the DI registration itself is deliberately left alone here.
     *
     * ## Residual, and what it hands to the unique-index step
     *
     * Two runs by the SAME collector less than a gap window apart now share a second,
     * where before they had to collide on the same second — and the reader SUMS rows
     * that share a second, so such a pair double-counts. "The same collector" includes
     * every coroutine holding the container's singleton, per the note above.
     * Unreachable on the live paths
     * (`bootstrapSnapshot()` refuses inside 6 h, and the daemon timer is one
     * `count=1` worker on a 6 h interval), and the structural fix is the unique
     * index on `(recorded_at, media_type, library_id)` plus a SUMMING upsert, which
     * needs a migration. That step must ship the upsert WITH the index: this method
     * makes duplicate `(recorded_at, media_type, library_id)` tuples from a looping
     * caller deterministic rather than occasional, so a bare unique index would
     * turn them into rejected INSERTs (contained and logged, but rows lost) instead
     * of merged ones.
     *
     * @return int Unix seconds; bind through `FROM_UNIXTIME(?)`, never as a string.
     *
     * @since 1.9
     */
    private function snapshotRunSecond(): int
    {
        $nowNs = hrtime(true);
        $maxGapNs = self::SNAPSHOT_RUN_MAX_GAP_SECONDS * 1_000_000_000;
        $continuingARun = $this->snapshotRunSecond !== 0
            && ($nowNs - $this->snapshotRunLastWriteNs) < $maxGapNs;

        $this->snapshotRunLastWriteNs = $nowNs;

        if ($continuingARun) {
            return $this->snapshotRunSecond;
        }

        $this->snapshotRunSecond = time();

        return $this->snapshotRunSecond;
    }

    /**
     * Fold raw `media_items.type` totals onto the coarse `stats_storage` buckets,
     * SUMMING every type that lands in the same bucket.
     *
     * Types with no bucket are dropped and reported once per run at `error`
     * level; folded types are reported once per run at `warning` level (one line
     * per run rather than one per type, per S102 review r1 LOW-5).
     *
     * @param array<string, array{count: int, bytes: int, cache?: int}> $totals
     *
     * @return array<string, array{count: int, bytes: int, cache: int}> Keyed by bucket
     *
     * @since 1.9
     */
    private function foldStorageTotals(array $totals): array
    {
        /** @var array<string, array{count: int, bytes: int, cache: int}> $folded */
        $folded = [];
        /** @var array<string, string> $foldedFrom */
        $foldedFrom = [];
        /** @var array<string, int> $droppedBytes */
        $droppedBytes = [];

        foreach ($totals as $mediaType => $entry) {
            $bucket = StorageSnapshotHelper::TYPE_TO_BUCKET[$mediaType] ?? null;

            if ($bucket === null) {
                $droppedBytes[$mediaType] = $entry['bytes'];
                continue;
            }

            if ($bucket !== $mediaType) {
                $foldedFrom[$mediaType] = $bucket;
            }

            if (!isset($folded[$bucket])) {
                $folded[$bucket] = ['count' => 0, 'bytes' => 0, 'cache' => 0];
            }
            $folded[$bucket]['count'] += $entry['count'];
            $folded[$bucket]['bytes'] += $entry['bytes'];
            $folded[$bucket]['cache'] += $entry['cache'] ?? 0;
        }

        if ($droppedBytes !== []) {
            LoggerFactory::get(LogChannels::APPLICATION)->error(
                'Storage snapshot DROPPED media types with no stats_storage bucket; their bytes are '
                . 'deliberately not attributed to another bucket, because a wrong number that looks '
                . 'right is worse than a visibly missing one (migration 086). Give the type a bucket '
                . 'in StorageSnapshotHelper::TYPE_TO_BUCKET.',
                ['dropped_bytes_by_type' => $droppedBytes]
            );
        }

        if ($foldedFrom !== []) {
            LoggerFactory::get(LogChannels::APPLICATION)->warning(
                'Storage snapshot media types folded to stats_storage buckets',
                ['folded' => $foldedFrom]
            );
        }

        return $folded;
    }

    /**
     * Get playback stats for a date range.
     *
     * Returns time-series data grouped by day with playback counts and total duration.
     *
     * @param DateTimeInterface $from Start date
     * @param DateTimeInterface $to End date
     *
     * @return array<int, array{date: string, play_count: int, total_duration: int, completed_count: int}>
     *
     * @example
     * ```php
     * $stats = $collector->getPlaybackStats(new \DateTime('-7 days'), new \DateTime());
     * ```
     */
    public function getPlaybackStats(DateTimeInterface $from, DateTimeInterface $to): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT
                 DATE(started_at) AS date,
                 COUNT(*) AS play_count,
                 COALESCE(SUM(duration_seconds), 0) AS total_duration,
                 SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_count
             FROM stats_playback_events
             WHERE started_at >= ? AND started_at <= ?
             GROUP BY DATE(started_at)
             ORDER BY date ASC",
            [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
        );

        return array_map(function (array $row): array {
            return [
                'date' => $this->toString($row['date']),
                'play_count' => $this->toInt($row['play_count']),
                'total_duration' => $this->toInt($row['total_duration']),
                'completed_count' => $this->toInt($row['completed_count']),
            ];
        }, $rows);
    }

    /**
     * Get top users by watch time.
     *
     * Returns users sorted by total watch duration within the given time window.
     *
     * @param int $limit Maximum number of users to return (default: 10)
     * @param DateTimeInterface|null $since Only count activity since this date (default: all time)
     *
     * @return array<int, array{user_id: string, total_watch_time: int, play_count: int}>
     *
     * @example
     * ```php
     * $topUsers = $collector->getTopUsers(10, new \DateTime('-30 days'));
     * ```
     */
    public function getTopUsers(int $limit = 10, ?DateTimeInterface $since = null): array
    {
        // INNER JOIN users so playback events belonging to a since-deleted
        // account are excluded at the query level (S14 orphan guard) — this
        // keeps blank / no-name rows out of the admin "Top Users" leaderboard.
        // users.id is the PK, so the join is 1:1 and does not fan out the
        // COUNT/SUM aggregates.
        $sql = "SELECT
                    e.user_id,
                    COALESCE(SUM(e.duration_seconds), 0) AS total_watch_time,
                    COUNT(*) AS play_count
                FROM stats_playback_events e
                INNER JOIN users u ON e.user_id = u.id";

        $params = [];

        if ($since !== null) {
            $sql .= " WHERE e.started_at >= ?";
            $params[] = $since->format('Y-m-d H:i:s');
        }

        $sql .= " GROUP BY e.user_id ORDER BY total_watch_time DESC LIMIT ?";
        $params[] = $limit;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query($sql, $params);

        return array_map(function (array $row): array {
            return [
                'user_id' => $this->toString($row['user_id']),
                'total_watch_time' => $this->toInt($row['total_watch_time']),
                'play_count' => $this->toInt($row['play_count']),
            ];
        }, $rows);
    }

    /**
     * Get top media items by play count.
     *
     * Returns media items sorted by play count within the given time window.
     *
     * @param int $limit Maximum number of items to return (default: 10)
     * @param DateTimeInterface|null $since Only count plays since this date (default: all time)
     *
     * @return array<int, array{media_item_id: string, play_count: int, total_duration: int}>
     *
     * @example
     * ```php
     * $topMedia = $collector->getTopMedia(10, new \DateTime('-30 days'));
     * ```
     */
    public function getTopMedia(int $limit = 10, ?DateTimeInterface $since = null): array
    {
        // INNER JOIN media_items so plays of a since-deleted item are excluded
        // at the query level (S14 orphan guard) — this keeps blank / no-title
        // rows out of the admin "Top Media" list. media_items.id is the PK, so
        // the join is 1:1 and does not fan out the COUNT/SUM aggregates.
        $sql = "SELECT
                    e.media_item_id,
                    COUNT(*) AS play_count,
                    COALESCE(SUM(e.duration_seconds), 0) AS total_duration
                FROM stats_playback_events e
                INNER JOIN media_items mi ON e.media_item_id = mi.id";

        $params = [];

        if ($since !== null) {
            $sql .= " WHERE e.started_at >= ?";
            $params[] = $since->format('Y-m-d H:i:s');
        }

        $sql .= " GROUP BY e.media_item_id ORDER BY play_count DESC LIMIT ?";
        $params[] = $limit;

        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query($sql, $params);

        return array_map(function (array $row): array {
            return [
                'media_item_id' => $this->toString($row['media_item_id']),
                'play_count' => $this->toInt($row['play_count']),
                'total_duration' => $this->toInt($row['total_duration']),
            ];
        }, $rows);
    }
}
