<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Common\Uuid;
use Phlix\Common\Util\RowMap;
use Phlix\Media\Streaming\AbrLadder;
use Phlix\Media\Streaming\Rendition;
use Phlix\Media\Streaming\SourceProfile;
use Phlix\Media\Streaming\StreamState;
use Phlix\Media\Transcoding\Subtitles\SubtitleExtractor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Transcode Manager - Manages media transcoding jobs and lifecycle.
 *
 * Coordinates transcoding operations by creating FFmpeg jobs, tracking their
 * status, managing concurrent job limits, and cleaning up stale jobs.
 * Integrates with the streaming system to provide transcoded content.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Job management for FFmpeg-based media transcoding with concurrency limits
 * @see FfmpegRunner For FFmpeg process execution
 * @see EncodingHelper For encoding parameter generation
 */
class TranscodeManager
{
    /** @var Connection Database connection for job persistence */
    private Connection $db;

    /** @var FfmpegRunner FFmpeg execution engine */
    private FfmpegRunner $ffmpeg;

    /** @var EncodingHelper Encoding parameter calculator */
    private EncodingHelper $encodingHelper;

    /** @var string Base directory for transcoded output files */
    private string $transcodeDir;

    /** @var string Base directory for HLS segments */
    private string $segmentDir;

    /** @var array<string, array{id: string, state: StreamState, output_path: string, encoding_params: array<string, mixed>, started_at: int}> Active jobs */
    private array $activeJobs = [];

    /** @var int Maximum concurrent transcode jobs allowed */
    private int $maxConcurrentTranscodes;

    /** @var int Target HLS segment duration in seconds */
    private int $segmentSeconds;

    /** @var int Ceiling on simultaneous on-demand segment encodes across all jobs */
    private int $maxConcurrentSegments;

    /** @var int Size budget (bytes) for the on-demand segment cache before LRU eviction */
    private int $cacheMaxBytes;

    /** @var int Age (seconds) after which an idle segment-job directory is reclaimed */
    private int $cacheMaxAgeSeconds;

    /** @var int Max time (ms) to wait for an on-demand segment encode before giving up */
    private int $segmentMaxWaitMs;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var SubtitleExtractor Detects + builds extraction for embedded text subs */
    private SubtitleExtractor $subtitleExtractor;

    /** @var string Absolute php binary path used by the detached VTT cleaner step */
    private string $phpBinary;

    /** @var string Absolute path to the scripts/clean-vtt.php cleaner CLI */
    private string $cleanVttScript;

    /** @var int|null Unix timestamp of the last reaper run, or null if never run. */
    private ?int $lastReaperRun = null;

    /** @var array<string, array{files: list<string>, expires_at: int}> Cached glob results by directory */
    private static array $globCache = [];

    /** @var int Cache TTL in seconds (5 seconds for transcode cleanup) */
    private const GLOB_CACHE_TTL = 5;

    /**
     * In-worker LRU cache of narrowed transcode_jobs rows, keyed by job id.
     *
     * A job row is written once at creation; thereafter only its `status`/`error`
     * change (completion, cancel, reap), and every such write invalidates the entry
     * ({@see invalidateJobRowCache()}) — this class is the sole writer of the table —
     * so a cache hit is always coherent with the DB. The parsed `variants` ladder is
     * memoised alongside the raw row so per-segment readers ({@see ensureSegment()},
     * {@see getJobVariants()}) never re-`json_decode` it. Bounded by
     * {@see JOB_ROW_CACHE_MAX} with oldest-first eviction to cap memory in long-lived
     * workers.
     *
     * @var array<string, array{row: array<string, mixed>, variants: array<mixed>|null}>
     */
    private array $jobRowCache = [];

    /** @var int Max distinct job rows retained in the in-worker LRU before eviction. */
    private const JOB_ROW_CACHE_MAX = 256;

    /**
     * In-worker set of on-demand segment encodes THIS worker launched and believes
     * are still running, keyed by the absolute final segment path
     * (`.../{jobId}/seg-v{variant}-NNNNN.ts`, which embeds the (jobId, variant,
     * index) tuple) → the monotonic launch time in milliseconds.
     *
     * Backs the memory-based per-segment DEDUP check
     * ({@see segmentEncodeInFlight()}) so a client retry of a slow segment (the
     * common hls.js first-byte-timeout re-request — by far the more frequent of the
     * two hot-path checks) no longer globs `{final}.part-*` per request. **The
     * GLOBAL CAP is intentionally NOT derived from this set** — see
     * {@see countInFlightSegmentEncodes()} for why that check stays a real-time,
     * whole-tree glob. An entry is added the instant an encode is launched and
     * removed when its launcher observes completion or failure
     * ({@see produceSegment()}'s `finally`, which now wraps the launch itself so the
     * removal is guaranteed on every exit path); any drift (e.g. a hard coroutine
     * kill that skips the `finally`) is corrected by
     * {@see reconcileInFlightSegments()}. Because the path carries the variant
     * prefix, accounting is naturally per (jobId, variant).
     *
     * @var array<string, int>
     */
    private array $segmentEncodesInFlight = [];

    /**
     * Last global snapshot of EVERY worker's in-flight segment encodes, keyed by
     * absolute final segment path, refreshed by {@see reconcileInFlightSegments()}
     * at most once per {@see SEGMENT_INFLIGHT_RECONCILE_INTERVAL_MS}.
     *
     * Feeds ONLY the memory-based per-segment dedup ({@see segmentEncodeInFlight()})
     * — a sibling worker's in-flight encode is deduplicated eventually-consistently
     * (bounded by this interval), which is an acceptable staleness because dedup
     * merely avoids a redundant duplicate encode of the exact same segment; it does
     * not gate the GLOBAL CAP (that stays a real-time glob — see
     * {@see countInFlightSegmentEncodes()} for why the cap cannot tolerate this same
     * staleness after the S2 review found that a ≤1s × 14-worker-process product
     * meaningfully widens the seek-cascade protection's overshoot window versus the
     * pre-S2 ~100ms `.part-*`-visibility latency).
     *
     * @var array<string, true>
     */
    private array $globalInFlightSnapshot = [];

    /** @var int|null Monotonic ms of the last in-flight reconciliation glob, or null. */
    private ?int $lastInFlightReconcileMs = null;

    /**
     * Minimum spacing (ms) between in-flight reconciliation globs that refresh
     * {@see $globalInFlightSnapshot} (the DEDUP-only cross-worker view) and self-heal
     * {@see $segmentEncodesInFlight}. Does NOT throttle or otherwise govern the
     * global-cap glob in {@see countInFlightSegmentEncodes()}, which always reads
     * the shared tree live.
     */
    private const SEGMENT_INFLIGHT_RECONCILE_INTERVAL_MS = 1000;

    /**
     * Grace (ms) before a tracked encode with neither a live `.part-*` temp nor a
     * finished segment on disk is treated as dead and dropped from
     * {@see $segmentEncodesInFlight}. Covers the brief launch→temp-file-appears
     * latency so a just-launched encode is never mistaken for a dead one.
     */
    private const SEGMENT_INFLIGHT_STALE_GRACE_MS = 5000;

    /**
     * Columns {@see getJobRow()} selects — the exact set its callers read. Narrowed
     * from `SELECT *` so the per-segment hot path never fetches the wide row under the
     * serialized DB mutex on a cache miss.
     */
    private const JOB_ROW_COLUMNS =
        'id, status, input_path, hls_dir, duration_seconds, segment_seconds, segment_params, subtitle_tracks, variants';

    /**
     * Profile resolution caps used to decide downscaling for HLS jobs.
     *
     * Mirrors {@see \Phlix\Media\Streaming\QualitySelector}'s max_resolution per
     * profile so a job can be encoded for a device class without coupling to the
     * full selector. Unknown profiles fall back to 'web'.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const PROFILE_MAX_RESOLUTION = [
        'generic' => [3840, 2160],
        'mobile-low' => [854, 480],
        'mobile-high' => [1280, 720],
        'web' => [1920, 1080],
        'tv-4k' => [3840, 2160],
    ];

    // Job status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Creates a new TranscodeManager instance.
     *
     * @param Connection $db Database connection
     * @param FfmpegRunner $ffmpeg FFmpeg execution engine
     * @param EncodingHelper $encodingHelper Encoding parameter calculator
     * @param string $transcodeDir Output directory for transcoded files
     * @param string $segmentDir Directory for HLS segments
     * @param LoggerInterface|null $logger Optional PSR logger
     * @param int $segmentSeconds Target HLS segment duration in seconds (default 6)
     *
     * @example
     * ```php
     * $manager = new TranscodeManager($db, $ffmpeg, $helper, '/var/transcodes', '/var/segments');
     * ```
     */
    public function __construct(
        Connection $db,
        FfmpegRunner $ffmpeg,
        EncodingHelper $encodingHelper,
        string $transcodeDir,
        string $segmentDir,
        ?LoggerInterface $logger = null,
        int $segmentSeconds = 6,
        ?SubtitleExtractor $subtitleExtractor = null,
        ?string $phpBinary = null,
        ?string $cleanVttScript = null,
        ?int $maxConcurrentSegments = null,
        ?int $cacheMaxBytes = null,
        ?int $cacheMaxAgeSeconds = null,
        ?int $segmentMaxWaitMs = null
    ) {
        $this->db = $db;
        $this->ffmpeg = $ffmpeg;
        $this->encodingHelper = $encodingHelper;
        $this->transcodeDir = $transcodeDir;
        $this->segmentDir = $segmentDir;
        $this->maxConcurrentTranscodes = 4;
        $this->logger = $logger ?? new NullLogger();
        $this->segmentSeconds = $segmentSeconds > 0 ? $segmentSeconds : 6;
        $this->maxConcurrentSegments = ($maxConcurrentSegments !== null && $maxConcurrentSegments > 0)
            ? $maxConcurrentSegments
            : self::SEGMENT_MAX_INFLIGHT_GLOBAL;
        $this->cacheMaxBytes = ($cacheMaxBytes !== null && $cacheMaxBytes > 0)
            ? $cacheMaxBytes
            : self::SEGMENT_CACHE_MAX_BYTES;
        $this->cacheMaxAgeSeconds = ($cacheMaxAgeSeconds !== null && $cacheMaxAgeSeconds > 0)
            ? $cacheMaxAgeSeconds
            : self::SEGMENT_CACHE_MAX_AGE;
        // Primarily a test seam so the segment poll ceiling can be shortened.
        $this->segmentMaxWaitMs = ($segmentMaxWaitMs !== null && $segmentMaxWaitMs > 0)
            ? $segmentMaxWaitMs
            : self::SEGMENT_MAX_WAIT_MS;
        $this->subtitleExtractor = $subtitleExtractor ?? new SubtitleExtractor();
        // PHP_BINARY is the absolute path to the running interpreter, used by the
        // detached job to invoke the VTT-cleaner CLI.
        $this->phpBinary = $phpBinary ?? PHP_BINARY;
        $this->cleanVttScript = $cleanVttScript ?? (dirname(__DIR__, 3) . '/scripts/clean-vtt.php');
    }

    /**
     * Starts a transcode job for a stream.
     *
     * Creates the output directory, probes the source, calculates encoding
     * parameters, and initiates the transcode process.
     *
     * @param StreamState $state Stream state containing media item reference
     * @param array<string, mixed> $options Additional options (device_profile, etc.)
     *
     * @return string Job ID for tracking
     *
     * @throws \InvalidArgumentException If media item not found
     * @throws \RuntimeException If probing fails or transcode fails to start
     *
     * @example
     * ```php
     * $jobId = $manager->startTranscode($streamState, ['device_profile' => 'mobile-high']);
     * ```
     */
    public function startTranscode(StreamState $state, array $options = []): string
    {
        $this->reapStaleRunningJobs();

        if (count($this->activeJobs) >= $this->maxConcurrentTranscodes) {
            throw new \RuntimeException(
                "Maximum concurrent transcodes ({$this->maxConcurrentTranscodes}) reached"
            );
        }

        $jobId = $this->generateUuid();

        $outputDir = "{$this->transcodeDir}/{$jobId}";
        if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Failed to create transcode directory: {$outputDir}");
        }

        $item = $this->getMediaItem($state->mediaItemId);
        if (!$item) {
            throw new \InvalidArgumentException("Media item not found");
        }

        $itemPath = is_string($item['path'] ?? null) ? (string) $item['path'] : '';
        $sourceInfoRaw = $this->ffmpeg->probe($itemPath);
        if (!$sourceInfoRaw) {
            throw new \RuntimeException("Failed to probe media file");
        }

        $this->persistProbedDuration($state->mediaItemId, $item, $sourceInfoRaw);

        $sourceInfo = $this->normalizeSourceInfo($sourceInfoRaw);

        $profileRaw = $options['device_profile'] ?? [];
        $profile = $this->normalizeProfile(is_array($profileRaw) ? $profileRaw : []);

        $encodingParams = $this->encodingHelper->getEncodingParams($sourceInfo, $profile, $options);

        $container = is_string($encodingParams['container'] ?? null)
            ? (string) $encodingParams['container']
            : 'ts';
        $outputPath = "{$outputDir}/output.{$container}";

        $this->db->query(
            "INSERT INTO transcode_jobs (id, media_item_id, input_path, output_path, status) VALUES (?, ?, ?, ?, 'running')",
            [$jobId, $state->mediaItemId, $itemPath, $outputPath]
        );

        $success = $this->ffmpeg->transcode($itemPath, $outputPath, $encodingParams);

        if (!$success) {
            $this->db->query("UPDATE transcode_jobs SET status = 'failed' WHERE id = ?", [$jobId]);
            $this->invalidateJobRowCache($jobId);
            throw new \RuntimeException("Transcode failed");
        }

        $this->activeJobs[$jobId] = [
            'id' => $jobId,
            'state' => $state,
            'output_path' => $outputPath,
            'encoding_params' => $encodingParams,
            'started_at' => time(),
        ];

        $this->logger->info('Transcode started', ['job_id' => $jobId]);

        return $jobId;
    }

    /**
     * Ensures an HLS transcode job exists for a media item + device profile.
     *
     * This is the entry point the playback layer calls when a file can't be
     * direct-played. It is idempotent: a still-valid job for the same
     * (media item, profile) is reused rather than spawning a duplicate FFmpeg.
     * Otherwise it probes the source, decides per-stream copy-vs-encode, creates
     * the job directory under the shared segment dir, records the row and launches
     * a DETACHED ffmpeg CMAF encode (one pass → both DASH `.mpd` and HLS `.m3u8`
     * from shared `.m4s` segments), so the event loop never blocks.
     *
     * @param string $mediaItemId Media item to transcode.
     * @param string $profileName Device profile name (e.g. 'web', 'mobile-high').
     *
     * @return array{
     *     job_id: string, status: string, master_url: string, hls_url: string, dash_url: string, reused: bool,
     *     subtitles: list<array{index: int, language: string, label: string, default: bool, url: string}>
     * }
     *
     * @throws \InvalidArgumentException If the media item is not found.
     * @throws \RuntimeException If concurrency is exhausted, probing fails, or the
     *                          encode could not be launched.
     *
     * @since 0.23.0
     */
    public function ensureHlsJob(string $mediaItemId, string $profileName = 'web'): array
    {
        $keyHash = sha1($mediaItemId . '|' . $profileName);

        // Self-heal first: drop any dead 'running' ghosts so a fresh play request
        // is not refused by the previous worker's leftovers (see reapStaleRunningJobs).
        $this->reapStaleRunningJobs();

        $existing = $this->findReusableJob($keyHash);
        if ($existing !== null) {
            return [
                'job_id' => $existing,
                'status' => $this->statusOf($existing),
                'master_url' => "/hls/{$existing}/master.m3u8",
                'hls_url' => "/hls/{$existing}/master.m3u8",
                'dash_url' => "/dash/{$existing}/manifest.mpd",
                'reused' => true,
                'subtitles' => $this->subtitleTracksFor($existing),
            ];
        }

        if ($this->getRunningJobCount() >= $this->maxConcurrentTranscodes) {
            throw new \RuntimeException(
                "Maximum concurrent transcodes ({$this->maxConcurrentTranscodes}) reached"
            );
        }

        $item = $this->getMediaItem($mediaItemId);
        if (!$item) {
            throw new \InvalidArgumentException('Media item not found');
        }
        $itemPath = is_string($item['path'] ?? null) ? (string) $item['path'] : '';
        if ($itemPath === '') {
            throw new \RuntimeException('Media item has no source path');
        }

        // S6: reuse A1's persisted source metadata when it is fresh (real dimensions
        // + a stored duration), so play-start no longer HARD-depends on a live probe.
        // The probe below is still issued — it is now coroutine-friendly / non-blocking
        // (see FfmpegRunner::probe()), so it never stalls the worker — because embedded
        // TEXT-subtitle detection needs the live stream list, which A1 does not persist.
        // But when persisted metadata is fresh, the source duration comes straight from
        // the scan (no probe dependency, no redundant persist write), the ABR ladder is
        // built from the persisted profile (see sourceProfileForItem()), and a probe
        // FAILURE is tolerated (we degrade to no embedded-subtitle sidecars) rather than
        // refusing playback for an item we already know how to describe.
        $metadataFresh = $this->sourceMetadataFresh($item);

        $probe = $this->ffmpeg->probe($itemPath);
        if ($probe === null && !$metadataFresh) {
            throw new \RuntimeException('Failed to probe media file');
        }
        // Downstream extractors treat an empty probe as "no streams / no subtitles";
        // this only occurs on the tolerated fresh-metadata + probe-failure path.
        $probe ??= [];

        // On-demand seek-aware VOD. The media playlist is published COMPLETE up front
        // (the full title duration, every segment, EXT-X-ENDLIST) and each MPEG-TS
        // segment is transcoded ONLY when the player fetches it (see ensureSegment()).
        // The player therefore reports the true total length immediately and can seek
        // anywhere — including far past what has been produced — instead of the old
        // single linear CMAF encode's live, ever-growing playlist (which made the
        // duration keep climbing and seeking snap back to the buffered region).
        if ($metadataFresh) {
            // Source length is authoritative from the scan; skip the probe-derived
            // value and the idempotent persist write entirely.
            $duration = $this->persistedDurationSeconds($item);
        } else {
            // Record the precise source duration so the UI shows a correct length.
            $this->persistProbedDuration($mediaItemId, $item, $probe);
            $duration = $this->probedDurationSeconds($probe);
        }
        if ($duration <= 0.0) {
            throw new \RuntimeException('Could not determine media duration for HLS playlist');
        }

        // Legacy single-variant params are still persisted in `segment_params` for
        // BC (older readers + the reuse filter). A5 additionally builds the true
        // multi-variant ABR ladder below and persists it in `variants`.
        $segParams = $this->computeSegmentParams($probe, $profileName);
        $segSeconds = $this->segmentSeconds;

        // A5: resolve the ABR ladder. Prefer A1's persisted source metadata blob
        // (metadata_json['source']) when it carries real dimensions; otherwise derive
        // a SourceProfile from the live probe we already have in hand.
        $sourceProfile = $this->sourceProfileForItem($item, $probe);
        $ladder = (new AbrLadder())->build($sourceProfile, $profileName);
        $streamVariants = $ladder->streamVariants();
        $topVariant = $streamVariants[0] ?? null;
        if ($topVariant === null) {
            // AbrLadder always emits at least one rung; defensive only.
            throw new \RuntimeException('ABR ladder produced no variants');
        }
        $variantsJson = json_encode($ladder->toArray());
        if ($variantsJson === false) {
            throw new \RuntimeException('Failed to encode ABR ladder');
        }

        $jobId = $this->generateUuid();
        $hlsDir = "{$this->segmentDir}/{$jobId}";
        if (!mkdir($hlsDir, 0755, true) && !is_dir($hlsDir)) {
            throw new \RuntimeException("Failed to create HLS directory: {$hlsDir}");
        }

        // The single-variant descriptor columns (variant_width/height/bandwidth) are
        // kept for BC with anything still reading them directly, populated from the
        // TOP (highest, first) master variant.
        $width = $topVariant->width;
        $height = $topVariant->height;
        $bandwidth = $topVariant->bandwidth();
        $playlistPath = "{$hlsDir}/master.m3u8";

        // Publish the complete VOD master (every variant) + one media playlist per
        // variant now — no encode is needed to know the timeline, so this is
        // instantaneous. Segments themselves are produced on demand per variant.
        $this->writeVodPlaylists($hlsDir, $duration, $segSeconds, $width, $height, $bandwidth, $streamVariants);

        // Detect embedded TEXT subtitle tracks (ASS/SRT/mov_text — bitmap PGS/VobSub
        // are skipped). Detection is a cheap parse of the in-memory probe; extraction
        // runs in a detached job below (video no longer needs a background encode).
        //
        // S6 SCOPE NOTE — do not delete the probe call above on the strength of the
        // fresh-metadata skip. The probe above is retained specifically to feed THIS
        // call: A1 persists only the video + primary-audio summary in
        // `metadata_json['source']`/`media_streams` (see sourceMetadataFresh() /
        // persistedSourceMetadata()), never subtitle stream descriptors. So even on
        // the "fresh metadata" fast path (duration + ABR ladder skip the probe
        // entirely), `$probe` is still needed here — `detectTextTracks()` has no
        // other source for the stream list. If a future change persists subtitle
        // descriptors at scan time (the follow-up flagged in S6's changelog entry),
        // THAT is the point at which this call could be removed/short-circuited —
        // removing it first, without that persistence, would silently drop embedded
        // subtitles for every backfilled item that hits the fresh-metadata path.
        $tracks = $this->subtitleExtractor->detectTextTracks($probe);
        $extractCmds = [];
        foreach ($tracks as $track) {
            $extractCmds[] = $this->subtitleExtractor->buildExtractCommand(
                $this->ffmpeg->getFfmpegPath(),
                $this->phpBinary,
                $this->cleanVttScript,
                $itemPath,
                $hlsDir,
                $track['index']
            );
        }
        $tracksJson = $tracks === [] ? null : json_encode($tracks);
        $segParamsJson = json_encode($segParams);
        // $variantsJson was built above from the resolved ABR ladder.

        // Status is 'completed': the job's deliverable — the full VOD playlist — is
        // ready the instant it's written, and segments are produced lazily on request.
        // Marking it completed (not 'running') keeps the stale-job reaper — which only
        // reaps 'running' rows — from tearing the job down while someone is watching.
        $this->db->query(
            "INSERT INTO transcode_jobs
                (id, media_item_id, input_path, output_path, hls_dir, status, progress, profile, key_hash,
                 variant_width, variant_height, variant_bandwidth, subtitle_tracks,
                 duration_seconds, segment_seconds, segment_params, variants, started_at, completed_at)
             VALUES (?, ?, ?, ?, ?, 'completed', 100, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$jobId, $mediaItemId, $itemPath, $playlistPath, $hlsDir, $profileName, $keyHash,
                $width, $height, $bandwidth, $tracksJson,
                (int) round($duration), $segSeconds, $segParamsJson, $variantsJson]
        );

        // Extract subtitles in the background (best-effort). A harmless `true` primary
        // writes the `.complete` marker and the extract commands run after it; because
        // `true` always succeeds, a failing extract can never write `.failed` (which
        // would wrongly mark the whole job failed) — each extract is internally `|| true`.
        if ($extractCmds !== []) {
            $this->ffmpeg->startDetached('true', $hlsDir, $extractCmds);
        }

        $this->logger->info('On-demand HLS job created', [
            'job_id' => $jobId,
            'media_item_id' => $mediaItemId,
            'profile' => $profileName,
            'duration' => (int) round($duration),
            'segment_seconds' => $segSeconds,
            'video_codec' => $segParams['video_codec'] ?? null,
            'audio_codec' => $segParams['audio_codec'] ?? null,
            'subtitle_tracks' => count($tracks),
        ]);

        return [
            'job_id' => $jobId,
            'status' => self::STATUS_COMPLETED,
            'master_url' => "/hls/{$jobId}/master.m3u8",
            'hls_url' => "/hls/{$jobId}/master.m3u8",
            'dash_url' => "/dash/{$jobId}/manifest.mpd",
            'reused' => false,
            'subtitles' => $this->subtitleTrackUrls($jobId, $tracks),
        ];
    }

    /**
     * Interval (ms) between polls while waiting for an on-demand segment to encode.
     * Uses a coroutine-yielding sleep (SWOOLE_HOOK_SLEEP is in the curated hook set),
     * so a waiting request does not block the worker.
     */
    private const SEGMENT_POLL_INTERVAL_MS = 100;

    /**
     * Max time (ms) to wait for a single on-demand segment before giving up (404).
     * A 6 s segment encodes in ~1–3 s at `veryfast` when the box is idle, but a
     * heavy source (e.g. HEVC → H.264) under load can take longer, so the ceiling
     * is generous. Pair it with a matching client fragment first-byte timeout —
     * the server sends nothing until the whole segment is encoded, so first-byte
     * latency equals encode time.
     */
    private const SEGMENT_MAX_WAIT_MS = 30000;

    /**
     * Default ceiling on simultaneous on-demand segment encodes across ALL jobs.
     *
     * Each segment is a full decode+encode; letting an unbounded number run at
     * once (many viewers, or one viewer's timed-out retries each re-launching an
     * encode) saturates the CPU so every encode slows past the client's fragment
     * timeout, and the failures cascade into the "can't play" overlay. Requests
     * over this ceiling fast-fail with {@see SegmentBusyException} (→ HTTP 503 +
     * Retry-After) so the CPU stays free for the encodes already in flight.
     * Overridable via `config['hls']['max_concurrent_segments']`.
     */
    private const SEGMENT_MAX_INFLIGHT_GLOBAL = 8;

    /**
     * Default size budget (bytes) for the on-demand segment cache. On-demand
     * segments accumulate in a (often RAM-backed / tmpfs) directory with no natural
     * lifecycle; without a ceiling they grow until the filesystem fills and every
     * encode then fails with ENOSPC. When the cache exceeds this, the least-recently
     * used job directories are evicted. Overridable via `config['hls']['cache_max_bytes']`.
     */
    private const SEGMENT_CACHE_MAX_BYTES = 8 * 1024 * 1024 * 1024; // 8 GiB

    /**
     * Default age (seconds) after which an idle segment-job directory is reclaimed
     * regardless of the size budget — a session nobody has touched for this long is
     * almost certainly abandoned. Overridable via `config['hls']['cache_max_age']`.
     */
    private const SEGMENT_CACHE_MAX_AGE = 10800; // 3 hours

    /**
     * A job directory touched within this window (seconds) is considered actively
     * watched and is never evicted by the size-budget sweep, so a live session is
     * not pulled out from under a viewer.
     */
    private const SEGMENT_CACHE_ACTIVE_WINDOW = 1800; // 30 minutes

    /**
     * Ensures the Nth MPEG-TS segment of an on-demand HLS job exists on disk,
     * transcoding it if necessary, and returns its absolute path (or null).
     *
     * This is the seek-anywhere core: because the VOD playlist advertises every
     * segment up front, the player may fetch ANY segment — including one far past
     * whatever has been produced so far. A cache hit returns immediately; otherwise
     * a short `-ss` fast-seek encode of exactly this segment's window is launched
     * (detached, written atomically) and the request polls — with a coroutine-
     * yielding sleep — until the file appears or the wait ceiling is hit.
     *
     * A5 made this variant-aware. A job whose `variants` column is populated (created
     * on or after A5) resolves the requested `$variant` (a Rendition id such as
     * `1080p` / `original`) against the persisted ABR ladder and encodes that rung's
     * `seg-v{variant}-NNNNN.ts`. A legacy job (`variants IS NULL`, created before A5)
     * ignores `$variant`, reads the single `segment_params` column, and writes the
     * unprefixed `seg-NNNNN.ts` exactly as before.
     *
     * @param string      $jobId   Transcode job id.
     * @param string|null $variant Rendition id for a multi-variant job (e.g. `720p`,
     *                             `original`); `null` for the legacy single-variant path.
     * @param int         $index   Zero-based segment index.
     *
     * @return string|null Absolute path to the ready segment, or null when the job
     *                     is not on-demand, the variant is unknown/not advertised, the
     *                     index is out of range, or the encode did not finish within
     *                     {@see self::SEGMENT_MAX_WAIT_MS}.
     *
     * @throws SegmentBusyException When the global segment-encode ceiling is reached
     *                     and this segment is not already encoding — a transient,
     *                     retryable state the caller surfaces as HTTP 503.
     */
    public function ensureSegment(string $jobId, ?string $variant, int $index): ?string
    {
        $entry = $this->jobRowEntry($jobId);
        if ($entry === null || $index < 0) {
            return null;
        }
        $row = $entry['row'];

        $variantsRaw = $row['variants'] ?? null;
        if (is_string($variantsRaw) && $variantsRaw !== '') {
            // Multi-variant (A5+) job: resolve the rung from the persisted ladder,
            // reusing the parse memoised in the cache entry.
            $decoded = $entry['variants'];
            if (!is_array($decoded)) {
                return null;
            }
            $rendition = $this->findRenditionArray($decoded, $variant);
            if ($rendition === null) {
                return null; // unknown / non-advertised variant → 404 (mirrors out-of-range)
            }
            $segParams = self::segmentParamsForRendition($rendition);
            return $this->produceSegment($jobId, $row, $variant, $index, $segParams);
        }

        // Legacy single-variant job (variants IS NULL) — byte-identical to pre-A5:
        // ignore $variant, read segment_params, write unprefixed seg-NNNNN.ts.
        $segParamsRaw = $row['segment_params'] ?? null;
        if (!is_string($segParamsRaw) || $segParamsRaw === '') {
            return null; // not an on-demand job (e.g. a legacy CMAF job)
        }
        $decodedParams = json_decode($segParamsRaw, true);
        if (!is_array($decodedParams)) {
            return null;
        }
        // Normalise to string keys so the encode-param contract holds (JSON objects
        // always decode with string keys; this makes that explicit for the type).
        $segParams = [];
        foreach ($decodedParams as $paramKey => $paramValue) {
            $segParams[(string) $paramKey] = $paramValue;
        }

        return $this->produceSegment($jobId, $row, null, $index, $segParams);
    }

    /**
     * Produce-or-serve one on-demand segment given already-resolved encode params.
     *
     * Shared tail of {@see ensureSegment()} for both the legacy single-variant and
     * the multi-variant path — the ONLY difference between the two is the resolved
     * `$segParams` and the `$variantId` that prefixes the segment filename; the
     * timeline (segment count / boundaries / duration) is identical across every
     * variant of a job. The dedup ({@see segmentEncodeInFlight()}), global cap
     * ({@see countInFlightSegmentEncodes()} → {@see SegmentBusyException}), the
     * coroutine-yielding poll, and the LRU touch are unchanged from pre-A5.
     *
     * @param string               $jobId     Transcode job id.
     * @param array<string, mixed>  $row       The transcode_jobs row.
     * @param string|null           $variantId Rendition id (null = legacy unprefixed name).
     * @param int                   $index     Zero-based segment index.
     * @param array<string, mixed>  $segParams Encode params for FfmpegRunner::buildSegmentCommand().
     */
    private function produceSegment(
        string $jobId,
        array $row,
        ?string $variantId,
        int $index,
        array $segParams
    ): ?string {
        $segSeconds = is_numeric($row['segment_seconds'] ?? null)
            ? (int) $row['segment_seconds']
            : $this->segmentSeconds;
        $segSeconds = $segSeconds > 0 ? $segSeconds : $this->segmentSeconds;
        $duration = is_numeric($row['duration_seconds'] ?? null) ? (float) $row['duration_seconds'] : 0.0;
        if ($duration <= 0.0) {
            return null;
        }

        $total = (int) ceil($duration / $segSeconds);
        if ($index >= $total) {
            return null;
        }

        $dir = is_string($row['hls_dir'] ?? null) && $row['hls_dir'] !== ''
            ? (string) $row['hls_dir']
            : "{$this->segmentDir}/{$jobId}";
        $final = $dir . '/' . self::segmentFileName($variantId, $index);

        if (is_file($final)) {
            $this->touchJobDir($dir); // mark the session active for the LRU sweep
            return $final; // cache hit
        }

        $inputPath = is_string($row['input_path'] ?? null) ? (string) $row['input_path'] : '';
        if ($inputPath === '' || !is_file($inputPath)) {
            return null;
        }

        $start = (float) ($index * $segSeconds);
        $segLen = min((float) $segSeconds, $duration - $start);
        if ($segLen <= 0.0) {
            return null;
        }

        // The job dir can be missing here even for a `completed` job: a restart
        // wipes a PrivateTmp /tmp, and the LRU sweep can evict an idle session.
        // Recreate it so an on-demand re-encode still lands somewhere (the DB row,
        // and thus the advertised playlist, still references this segment).
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Refresh the DEDUP-only cross-worker snapshot from the shared segment tree
        // at most once per reconcile window (see reconcileInFlightSegments()). This
        // does NOT feed the global cap (that stays a real-time glob below) — it only
        // lets segmentEncodeInFlight() catch a sibling worker's in-flight encode
        // without a per-request glob, since that dedup check is the more frequent of
        // the two hot-path checks (every retry of a slow segment hits it).
        $this->reconcileInFlightSegments();

        $launched = false;
        try {
            // Only launch an encode if one for THIS exact segment is not already
            // running. Without this, every client retry of a slow segment (hls.js
            // re-requests the same fragment on a first-byte timeout) spawns a
            // duplicate ffmpeg, and the redundant load is exactly what pushes
            // encodes past the client timeout — a self-amplifying cascade. A retry
            // now piggybacks on the in-flight encode instead. `$final` already
            // carries the per-variant name, so dedup + the global cap are naturally
            // scoped per (job, variant).
            if (!$this->segmentEncodeInFlight($final)) {
                // Global ceiling: bound total concurrent encodes so a burst of cold
                // seeks (many viewers, or one frantic scrub) can't saturate the CPU.
                // Over the ceiling we fast-fail (503 + Retry-After) rather than pile
                // on — the client backs off briefly and the in-flight encodes finish
                // fast. This check reads the shared tree LIVE (not the throttled
                // snapshot): a memory-only cap sums to a ≤1s-stale view PER WORKER
                // across all 14 HTTP worker processes, which the S2 review found
                // could let the fleet collectively overshoot the ceiling by up to
                // ~14x during exactly the seek-storm scenario this cap exists to
                // prevent — a materially larger window than the pre-existing
                // ~100ms `.part-*`-visibility latency the original glob-based cap
                // tolerated. Real-time accuracy here is preserved by only ever
                // reaching this glob on an actual launch decision (i.e. after the
                // memory-based dedup check above already found no in-flight encode
                // for this exact segment) — not on every cache-miss/dedup hit — so
                // the higher-frequency check still gets the hot-path relief.
                if ($this->countInFlightSegmentEncodes() >= $this->maxConcurrentSegments) {
                    throw new SegmentBusyException(
                        'Segment encode capacity reached (' . $this->maxConcurrentSegments . ' in flight)'
                    );
                }
                // Record the launch in-worker BEFORE spawning ffmpeg so a concurrent
                // request for this exact segment dedups against it immediately. The
                // add and the two checks above run with no coroutine yield between
                // them, so the gate decision is atomic within this worker's event
                // loop. This, touchJobDir(), and startSegmentEncode() all run inside
                // this try so that ANY throwable after the increment — not just a
                // poll-loop failure — reaches the finally below and releases the
                // slot; an increment that were left outside the try could leak
                // permanently (a worker that leaks and then goes idle never revisits
                // this code path to self-heal).
                $this->segmentEncodesInFlight[$final] = $this->monotonicMs();
                $launched = true;
                $this->touchJobDir($dir);
                $this->ffmpeg->startSegmentEncode($inputPath, $final, $start, $segLen, $segParams);
            }

            // Poll using non-blocking sleep when in Swoole coroutine context.
            // Falls back to usleep when not in coroutine (e.g., Swoole hooks disabled).
            $waited = 0;
            while (!is_file($final) && $waited < $this->segmentMaxWaitMs) {
                if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
                    \Swoole\Coroutine::sleep(self::SEGMENT_POLL_INTERVAL_MS / 1000.0);
                } else {
                    usleep(self::SEGMENT_POLL_INTERVAL_MS * 1000);
                }
                $waited += self::SEGMENT_POLL_INTERVAL_MS;
            }
        } finally {
            // Release this worker's fast-path record for the encode WE launched,
            // whether it completed, failed before/while launching, timed out, or the
            // poll threw. Always removing it here means a launched increment can
            // never leak (no permanent over-count that would wrongly 503 forever).
            // $launched only flips true AFTER the increment, so a throw from the cap
            // check (SegmentBusyException) or anything before it leaves $launched
            // false and this is a correct no-op — nothing was ever recorded to
            // release. A still-running or slow encode does NOT stop counting against
            // the global cap: its `.part-*` temp lives on disk until it renames, so
            // the LIVE glob in countInFlightSegmentEncodes() keeps counting it until
            // it genuinely finishes. The reconcile self-heal remains only a safety
            // net for the dedup-only snapshot (the rare case this finally never runs,
            // e.g. a hard coroutine kill).
            if ($launched) {
                unset($this->segmentEncodesInFlight[$final]);
            }
        }

        return is_file($final) ? $final : null;
    }

    /**
     * Resolve a variant id against a decoded `variants` ladder to its Rendition array.
     *
     * Searches the clamped rungs (`renditions`) first, then the `original` descriptor —
     * but `original` is only resolvable when it is a genuine stream-copy variant
     * (`is_copy === true`), because that is the only case
     * {@see \Phlix\Media\Streaming\LadderResult::streamVariants()} emits it into the
     * master. When `original` merely mirrors the top transcode rung (`is_copy === false`)
     * it is NOT advertised, so nothing ever requests it and this returns null (404).
     *
     * @param array<mixed>  $decoded LadderResult::toArray() shape: `{renditions:[...], original:{...}}`.
     * @param string|null   $variant Requested Rendition id.
     *
     * @return array<string, mixed>|null The matching Rendition array, or null when not found/advertised.
     */
    private function findRenditionArray(array $decoded, ?string $variant): ?array
    {
        if ($variant === null || $variant === '') {
            return null;
        }

        $renditions = $decoded['renditions'] ?? null;
        if (is_array($renditions)) {
            foreach ($renditions as $rung) {
                if (is_array($rung) && ($rung['id'] ?? null) === $variant) {
                    /** @var array<string, mixed> $rung */
                    return $rung;
                }
            }
        }

        $original = $decoded['original'] ?? null;
        if (
            is_array($original)
            && ($original['id'] ?? null) === $variant
            && ($original['is_copy'] ?? false) === true
        ) {
            /** @var array<string, mixed> $original */
            return $original;
        }

        return null;
    }

    /**
     * Build the {@see FfmpegRunner::buildSegmentCommand()} params for one Rendition array.
     *
     * A copy rung yields the minimal `-c copy` contract (A4 skips every other flag on
     * the copy path). A transcode rung yields the capped-CRF H.264 / AAC contract:
     * per-rung scale (`width`/`height`), MB-derived `level` (from the advertised
     * CODECS), and the VBV ceiling (`maxrate`/`bufsize`) derived from the rung's
     * `video_bitrate` via {@see Rendition::MAXRATE_MULTIPLIER} / ::BUFSIZE_MULTIPLIER.
     *
     * @param array<string, mixed> $rendition A Rendition::toArray() shape.
     *
     * @return array<string, mixed> Encode params for FfmpegRunner::buildSegmentCommand().
     */
    private static function segmentParamsForRendition(array $rendition): array
    {
        if (($rendition['is_copy'] ?? false) === true) {
            // Genuine passthrough: A4 emits only `-c:v copy` / `-c:a copy` and skips
            // every other flag on the copy path, so nothing else belongs here.
            return ['video_codec' => 'copy', 'audio_codec' => 'copy'];
        }

        $videoBitrate = self::renditionInt($rendition, 'video_bitrate');
        $codecs = is_string($rendition['codecs'] ?? null) ? (string) $rendition['codecs'] : '';
        $maxrate = (int) round($videoBitrate * Rendition::MAXRATE_MULTIPLIER);
        $bufsize = $maxrate * Rendition::BUFSIZE_MULTIPLIER;

        return [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
            'pix_fmt' => 'yuv420p',
            'profile' => 'high',
            'level' => self::ffmpegLevelFromCodecs($codecs),
            // Always pass the target scale — even at source resolution an explicit
            // -vf scale is harmless and keeps the command uniform across rungs.
            'width' => self::renditionInt($rendition, 'width'),
            'height' => self::renditionInt($rendition, 'height'),
            // Informational (A4 does not turn this into a bare -b:v).
            'video_bitrate' => $videoBitrate,
            'maxrate' => $maxrate,
            'bufsize' => $bufsize,
            'audio_codec' => 'aac',
            'audio_bitrate' => '128k',
            'audio_sample_rate' => 48000,
        ];
    }

    /**
     * Derive the ffmpeg `-level` string (e.g. `4.1`) from an HLS CODECS string.
     *
     * The CODECS produced by {@see \Phlix\Media\Streaming\AbrLadder::h264Codecs()} is
     * always `avc1.6400{LL},mp4a.40.2` where `64`=High profile_idc, `00`=constraint
     * flags, and `LL` is the level_idc as two hex digits. The table mirrors
     * `AbrLadder::H264_LEVELS` as decimal-level strings. Falls back to `4.1`
     * defensively (unreachable given AbrLadder only ever emits these seven strings).
     *
     * @param string $codecs HLS CODECS string.
     */
    private static function ffmpegLevelFromCodecs(string $codecs): string
    {
        $map = [
            '1E' => '3.0',
            '1F' => '3.1',
            '20' => '3.2',
            '29' => '4.1',
            '2A' => '4.2',
            '32' => '5.0',
            '33' => '5.1',
        ];
        // Capture the level_idc: the two hex digits after `avc1.` + profile/constraint.
        if (preg_match('/avc1\.[0-9A-Fa-f]{4}([0-9A-Fa-f]{2})/', $codecs, $m) === 1) {
            $level = strtoupper($m[1]);
            if (isset($map[$level])) {
                return $map[$level];
            }
        }

        return '4.1';
    }

    /**
     * Coerce a Rendition-array field to a non-negative int (0 when absent/non-numeric).
     *
     * @param array<string, mixed> $rendition
     */
    private static function renditionInt(array $rendition, string $key): int
    {
        $value = $rendition[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * True when an on-demand encode for exactly this segment is already running.
     *
     * DEDUP ONLY (does not gate the global cap — see {@see countInFlightSegmentEncodes()}).
     * Reads purely from the in-worker bookkeeping — this worker's exact
     * {@see $segmentEncodesInFlight} launches plus the last global snapshot of every
     * worker's `.part-*` temps ({@see $globalInFlightSnapshot}) — so this,
     * the MORE FREQUENT of the two hot-path checks (every retry of a slow segment
     * hits it, not just genuine new-encode launches), no longer globs
     * `{final}.part-*` on every cache-miss. A same-worker retry (the common hls.js
     * first-byte-timeout re-request) is deduplicated immediately; a sibling worker's
     * in-flight encode is deduplicated eventually-consistently, as of the last
     * {@see reconcileInFlightSegments()} — acceptable staleness here because a missed
     * dedup only costs one redundant duplicate encode of the same segment, never a
     * cap breach. Callers refresh the snapshot (throttled) via
     * reconcileInFlightSegments() immediately before consulting this.
     *
     * @param string $final Absolute path of the target segment (`.../seg-…NNNNN.ts`).
     */
    private function segmentEncodeInFlight(string $final): bool
    {
        return isset($this->segmentEncodesInFlight[$final])
            || isset($this->globalInFlightSnapshot[$final]);
    }

    /**
     * Count on-demand segment encodes in flight across ALL jobs and ALL workers, by
     * globbing the shared segment directory for the `*.part-*` atomic-write temp
     * files. Bounds the global concurrency ceiling in {@see produceSegment()}.
     *
     * INTENTIONALLY a real-time, whole-tree glob — NOT derived from the throttled
     * in-worker snapshot. A prior version of this step summed the exact local set
     * with the ≤1s-stale {@see $globalInFlightSnapshot} for the cap too; review
     * found that because the ceiling is enforced independently by each of the 14 HTTP
     * worker processes, a shared ≤1s staleness window lets the fleet collectively
     * overshoot the advertised ceiling by up to ~14x before every worker's snapshot
     * converges — precisely during the seek-storm scenario this cap exists to
     * prevent (see `[[project_hls_seek_cascade_fix]]`), and a materially larger
     * overshoot window than the pre-existing ~100ms `.part-*`-visibility latency the
     * original (pre-S2) glob-based cap tolerated. This call site is reached ONLY on
     * an actual launch decision — i.e. only after {@see segmentEncodeInFlight()}
     * (the memory-based, much-higher-frequency check) already found no in-flight
     * encode for this exact segment — so the cap glob does not run on every
     * cache-miss/dedup hit, preserving most of the hot-path relief while keeping the
     * enforcement point itself real-time-accurate.
     *
     * @return int Number of segment encodes currently in flight.
     */
    private function countInFlightSegmentEncodes(): int
    {
        $parts = glob("{$this->segmentDir}/*/seg-*.ts.part-*");
        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * Reconcile the in-worker DEDUP bookkeeping against the shared segment tree —
     * throttled to at most one glob per {@see SEGMENT_INFLIGHT_RECONCILE_INTERVAL_MS}.
     * Does NOT feed the global cap (that is always a live glob — see
     * {@see countInFlightSegmentEncodes()}); this only keeps
     * {@see segmentEncodeInFlight()}'s cross-worker dedup view fresh and self-heals
     * {@see $segmentEncodesInFlight}.
     *
     * Two jobs:
     *   1. Refresh {@see $globalInFlightSnapshot} — the set of live `.part-*` temp
     *      files across every worker — so cross-worker dedup keeps working without a
     *      per-request tree glob.
     *   2. Self-heal {@see $segmentEncodesInFlight}: drop any entry whose encode is
     *      provably finished (its final segment exists) or dead (no `.part-*`, no
     *      final, past {@see SEGMENT_INFLIGHT_STALE_GRACE_MS}). An entry still backed
     *      by a live `.part-*` is kept — it is genuinely encoding. This is a secondary
     *      safety net for the dedup set (the `finally` in {@see produceSegment()} is
     *      the primary guard against a leaked increment — this only covers the rare
     *      case that `finally` itself never ran, e.g. a hard coroutine kill).
     */
    private function reconcileInFlightSegments(): void
    {
        $now = $this->monotonicMs();
        if (
            $this->lastInFlightReconcileMs !== null
            && ($now - $this->lastInFlightReconcileMs) < self::SEGMENT_INFLIGHT_RECONCILE_INTERVAL_MS
        ) {
            return; // throttled — the gate is served from memory between refreshes
        }
        // Stamp BEFORE globbing: if the glob yields (hooked file IO) and another
        // coroutine reaches here, it sees the throttle and skips its own glob,
        // keeping each gate decision synchronous and atomic within the worker.
        $this->lastInFlightReconcileMs = $now;

        $snapshot = [];
        $parts = glob("{$this->segmentDir}/*/seg-*.ts.part-*");
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $final = preg_replace('/\.part-[0-9a-f]+$/', '', $part);
                if (is_string($final) && $final !== $part) {
                    $snapshot[$final] = true;
                }
            }
        }
        $this->globalInFlightSnapshot = $snapshot;

        foreach ($this->segmentEncodesInFlight as $final => $launchedAtMs) {
            if (isset($snapshot[$final])) {
                continue; // a live `.part-*` → still encoding, keep counting it
            }
            if (is_file($final)) {
                unset($this->segmentEncodesInFlight[$final]); // completed
                continue;
            }
            if (($now - $launchedAtMs) > self::SEGMENT_INFLIGHT_STALE_GRACE_MS) {
                unset($this->segmentEncodesInFlight[$final]); // died without cleanup
            }
        }
    }

    /**
     * Monotonic millisecond clock for in-flight bookkeeping — immune to wall-clock
     * jumps (NTP / DST), per the repo's `hrtime(true)` convention.
     */
    private function monotonicMs(): int
    {
        return (int) (hrtime(true) / 1_000_000);
    }

    /**
     * Bumps a job directory's mtime so the LRU sweep ({@see sweepSegmentCache()})
     * treats the session as recently active. Called whenever a segment is served
     * or launched, since serving a cached segment does not otherwise touch the dir.
     *
     * @param string $dir Job directory.
     */
    private function touchJobDir(string $dir): void
    {
        if (is_dir($dir)) {
            @touch($dir);
        }
    }

    /**
     * Reclaims on-demand segment directories to bound the segment cache.
     *
     * On-demand segments pile up in a shared (frequently tmpfs / RAM-backed)
     * directory with no natural lifecycle — the jobs are marked `completed` the
     * moment their VOD playlist is written, so nothing ever deletes their segments.
     * Left unchecked the directory grows until the filesystem fills and every encode
     * then fails with ENOSPC. This runs on the reaper tick and:
     *
     *   1. removes any job directory idle longer than {@see $cacheMaxAgeSeconds}
     *      (an abandoned session), then
     *   2. if the total is still over {@see $cacheMaxBytes}, evicts the
     *      least-recently-used directories — skipping any touched within
     *      {@see self::SEGMENT_CACHE_ACTIVE_WINDOW} so a live watch is never pulled
     *      out from under a viewer — until back under budget.
     *
     * Eviction is safe: {@see ensureSegment()} recreates a missing directory and
     * re-encodes on demand, and the sweep only ever touches paths under
     * {@see $segmentDir}.
     *
     * @return int Number of directories reclaimed.
     */
    public function sweepSegmentCache(): int
    {
        $dirs = glob("{$this->segmentDir}/*", GLOB_ONLYDIR);
        if (!is_array($dirs) || $dirs === []) {
            return 0;
        }

        $now = time();
        $ageCutoff = $now - $this->cacheMaxAgeSeconds;
        $activeCutoff = $now - self::SEGMENT_CACHE_ACTIVE_WINDOW;

        /** @var list<array{path: string, mtime: int, size: int}> $entries */
        $entries = [];
        $totalBytes = 0;
        $reaped = 0;

        foreach ($dirs as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime === false) {
                continue;
            }
            // 1) Hard TTL: an idle session past the age cutoff is abandoned.
            if ($mtime < $ageCutoff) {
                $reaped += $this->removeJobDir($dir) ? 1 : 0;
                continue;
            }
            $size = $this->dirSize($dir);
            $totalBytes += $size;
            $entries[] = ['path' => $dir, 'mtime' => $mtime, 'size' => $size];
        }

        // 2) Size budget: evict least-recently-used until under the ceiling,
        // never touching an actively-watched (recently-touched) session.
        if ($totalBytes > $this->cacheMaxBytes) {
            usort($entries, static fn(array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
            foreach ($entries as $entry) {
                if ($totalBytes <= $this->cacheMaxBytes) {
                    break;
                }
                if ($entry['mtime'] >= $activeCutoff) {
                    continue; // live session — leave it alone
                }
                if ($this->removeJobDir($entry['path'])) {
                    $totalBytes -= $entry['size'];
                    $reaped++;
                }
            }
        }

        if ($reaped > 0) {
            $this->logger->info('Swept on-demand segment cache', [
                'reclaimed_dirs' => $reaped,
                'remaining_bytes' => $totalBytes,
            ]);
        }

        return $reaped;
    }

    /**
     * Total byte size of the files directly inside a job directory.
     *
     * @param string $dir Job directory.
     */
    private function dirSize(string $dir): int
    {
        $files = glob("{$dir}/*");
        if (!is_array($files)) {
            return 0;
        }
        $bytes = 0;
        foreach ($files as $file) {
            $size = @filesize($file);
            if ($size !== false) {
                $bytes += $size;
            }
        }
        return $bytes;
    }

    /**
     * Deletes a job directory and its contents. Guarded to stay within
     * {@see $segmentDir} and tolerant of concurrent deletion by another worker.
     *
     * @param string $dir Absolute job directory path.
     *
     * @return bool True if the directory was removed.
     */
    private function removeJobDir(string $dir): bool
    {
        $base = rtrim($this->segmentDir, '/') . '/';
        if (!str_starts_with($dir, $base) || $dir === $this->segmentDir) {
            return false; // never escape the segment dir
        }
        $files = glob("{$dir}/*");
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        return @rmdir($dir);
    }

    /**
     * The on-demand segment filename for a variant + zero-based index.
     *
     * Legacy single-variant jobs (`$variantId === null`) use the unprefixed
     * `seg-00042.ts` — byte-identical to pre-A5. Multi-variant jobs prefix the
     * Rendition id: `seg-v1080p-00042.ts`. Segments stay FLAT in the job dir (no
     * `v{id}/` subdirectory): the `/hls/{job_id}/{file}` route's `{file}` placeholder
     * is `[^/]+`, so a nested path would never match.
     *
     * @param string|null $variantId Rendition id (e.g. `1080p`, `original`) or null (legacy).
     * @param int         $index     Zero-based segment index.
     */
    private static function segmentFileName(?string $variantId, int $index): string
    {
        if ($variantId === null) {
            return sprintf('seg-%05d.ts', $index);
        }

        return sprintf('seg-v%s-%05d.ts', $variantId, $index);
    }

    /**
     * Extracts the source duration (seconds) from a raw ffprobe result.
     *
     * @param array<string, mixed> $probe Raw ffprobe result (expects format.duration).
     *
     * @return float Duration in seconds, or 0.0 when not determinable.
     */
    private function probedDurationSeconds(array $probe): float
    {
        $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];
        $raw = $format['duration'] ?? null;
        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * Computes the per-segment encode parameters for an on-demand HLS job.
     *
     * Starts from {@see self::computeHlsParams()} but forces a real encode: on-demand
     * segments cannot stream-copy (a copy can't force a keyframe at each segment
     * boundary), so a `'copy'` decision is upgraded to a browser-safe H.264 / AAC
     * encode. The variant_width/height/bandwidth descriptors are preserved for the
     * master playlist.
     *
     * @param array<string, mixed> $probe       Raw ffprobe result.
     * @param string               $profileName Device profile name.
     *
     * @return array<string, mixed> Parameters for {@see FfmpegRunner::buildSegmentCommand()}.
     */
    private function computeSegmentParams(array $probe, string $profileName): array
    {
        $params = $this->computeHlsParams($probe, $profileName);

        if (($params['video_codec'] ?? null) === 'copy') {
            $params['video_codec'] = 'libx264';
            $params['preset'] = is_string($params['preset'] ?? null) ? $params['preset'] : 'veryfast';
            $params['crf'] = is_numeric($params['crf'] ?? null) ? (int) $params['crf'] : 23;
            $params['pix_fmt'] = 'yuv420p';
            $params['profile'] = 'high';
            $params['level'] = '4.1';
        }
        if (($params['audio_codec'] ?? null) === 'copy') {
            $params['audio_codec'] = 'aac';
            $params['audio_bitrate'] = is_string($params['audio_bitrate'] ?? null) ? $params['audio_bitrate'] : '128k';
        }

        return $params;
    }

    /**
     * Writes the complete VOD master + media playlists for an on-demand HLS job.
     *
     * All are static: the master advertises every variant, and each media playlist
     * lists every segment with its EXTINF, `EXT-X-PLAYLIST-TYPE:VOD`, and a closing
     * `EXT-X-ENDLIST`, so the player knows the true duration and full seekable range
     * immediately. Segments themselves are produced on demand per variant.
     *
     * Multi-variant path (A5+): `$variants` is the highest-first list from
     * {@see \Phlix\Media\Streaming\LadderResult::streamVariants()} — one master
     * listing every variant plus one `media_v{id}.m3u8` per variant (segment TIMING
     * is IDENTICAL across variants; only the encode target differs). Legacy path
     * (`$variants === null`): the single-variant `master.m3u8` + `media_0.m3u8`
     * write, byte-identical to pre-A5.
     *
     * @param string              $dir        Job directory.
     * @param float               $duration   Source duration in seconds.
     * @param int                 $segSeconds Target segment (EXTINF) length in seconds.
     * @param int|null            $width      Variant pixel width (master RESOLUTION), or null.
     * @param int|null            $height     Variant pixel height, or null.
     * @param int|null            $bandwidth  Variant nominal bandwidth (master BANDWIDTH), or null.
     * @param list<Rendition>|null $variants  Multi-variant list (highest-first), or null for legacy.
     */
    private function writeVodPlaylists(
        string $dir,
        float $duration,
        int $segSeconds,
        ?int $width,
        ?int $height,
        ?int $bandwidth,
        ?array $variants = null
    ): void {
        if ($variants === null) {
            // Legacy single-variant path (BC for pre-A5 jobs / callers).
            file_put_contents("{$dir}/master.m3u8", $this->buildMasterPlaylist($width, $height, $bandwidth));
            file_put_contents("{$dir}/media_0.m3u8", $this->buildMediaPlaylist($duration, $segSeconds, null));
            return;
        }

        // Multi-variant: one master listing every variant + one media playlist per variant.
        file_put_contents("{$dir}/master.m3u8", $this->buildMultiVariantMaster($variants));
        foreach ($variants as $variant) {
            file_put_contents(
                "{$dir}/media_v{$variant->id}.m3u8",
                $this->buildMediaPlaylist($duration, $segSeconds, $variant->id)
            );
        }
    }

    /**
     * Builds the single-variant HLS master playlist text (legacy / pre-A5 jobs).
     *
     * @param int|null $width     Variant pixel width, or null to omit RESOLUTION.
     * @param int|null $height    Variant pixel height, or null to omit RESOLUTION.
     * @param int|null $bandwidth Nominal variant bandwidth (bits/sec).
     *
     * @return string Master playlist text.
     */
    private function buildMasterPlaylist(?int $width, ?int $height, ?int $bandwidth): string
    {
        // avc1.640029 = H.264 High@4.1 (the segment encode target); mp4a.40.2 = AAC-LC.
        $attrs = 'BANDWIDTH=' . ($bandwidth !== null && $bandwidth > 0 ? $bandwidth : 3000000);
        if ($width !== null && $height !== null && $width > 0 && $height > 0) {
            $attrs .= ",RESOLUTION={$width}x{$height}";
        }
        $attrs .= ',CODECS="avc1.640029,mp4a.40.2"';

        return "#EXTM3U\n"
            . "#EXT-X-VERSION:3\n"
            . "#EXT-X-STREAM-INF:{$attrs}\n"
            . "media_0.m3u8\n";
    }

    /**
     * Builds the multi-variant HLS master playlist text.
     *
     * Emits one `#EXT-X-STREAM-INF` (with BANDWIDTH / RESOLUTION / CODECS from the
     * Rendition) + `media_v{id}.m3u8` per variant, preserving the caller's order
     * (highest-first per {@see \Phlix\Media\Streaming\LadderResult::streamVariants()}).
     *
     * @param list<Rendition> $variants Variants in master order (not re-sorted).
     *
     * @return string Master playlist text.
     */
    private function buildMultiVariantMaster(array $variants): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        foreach ($variants as $variant) {
            $attrs = 'BANDWIDTH=' . $variant->bandwidth()
                . ',RESOLUTION=' . $variant->resolution()
                . ',CODECS="' . $variant->codecs . '"';
            $lines[] = '#EXT-X-STREAM-INF:' . $attrs;
            $lines[] = 'media_v' . $variant->id . '.m3u8';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Builds the complete VOD media playlist text for a title of the given duration.
     *
     * Emits one `#EXTINF` + segment entry per segment (the last shorter when the
     * duration is not a whole multiple of the segment length), tagged VOD and
     * terminated with `#EXT-X-ENDLIST` so the player treats it as a fixed-length,
     * fully-seekable stream. The segment TIMELINE (count / EXTINF / duration) is
     * identical for every variant — only the segment FILENAME prefix differs — so
     * hls.js can ABR-switch between variants at any boundary.
     *
     * @param float       $duration   Source duration in seconds.
     * @param int         $segSeconds Target segment length in seconds.
     * @param string|null $variantId  Rendition id for `seg-v{id}-NNNNN.ts`, or null
     *                                for the legacy unprefixed `seg-NNNNN.ts`.
     *
     * @return string Media playlist text.
     */
    private function buildMediaPlaylist(float $duration, int $segSeconds, ?string $variantId): string
    {
        $segSeconds = $segSeconds > 0 ? $segSeconds : 6;
        $count = (int) ceil($duration / $segSeconds);

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXT-X-TARGETDURATION:' . $segSeconds,
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-INDEPENDENT-SEGMENTS',
        ];
        for ($i = 0; $i < $count; $i++) {
            $start = $i * $segSeconds;
            $len = min((float) $segSeconds, $duration - $start);
            if ($len <= 0.0) {
                break;
            }
            $lines[] = '#EXTINF:' . number_format($len, 6, '.', '') . ',';
            $lines[] = self::segmentFileName($variantId, $i);
        }
        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Builds the public subtitle-track descriptors for a job from detector output.
     *
     * Maps each detected track to the playable shape the API returns: the sidecar
     * `url` points at the same /hls/{job}/{file} route that serves the segments.
     *
     * @param string $jobId  Job identifier.
     * @param list<array{index: int, language: string, label: string, default: bool, codec: string, filename: string}> $tracks
     *
     * @return list<array{index: int, language: string, label: string, default: bool, url: string}>
     */
    private function subtitleTrackUrls(string $jobId, array $tracks): array
    {
        $out = [];
        foreach ($tracks as $track) {
            $out[] = [
                'index' => $track['index'],
                'language' => $track['language'],
                'label' => $track['label'],
                'default' => $track['default'],
                'url' => "/hls/{$jobId}/{$track['filename']}",
            ];
        }
        return $out;
    }

    /**
     * Reads the stored subtitle-track descriptors for an existing job.
     *
     * Decodes the `subtitle_tracks` JSON persisted at job creation and maps it to
     * the public {index, language, label, default, url} shape. Returns an empty
     * list when the job has no text subtitles or the column is empty.
     *
     * @param string $jobId Job identifier.
     *
     * @return list<array{index: int, language: string, label: string, default: bool, url: string}>
     */
    public function subtitleTracksFor(string $jobId): array
    {
        $row = $this->getJobRow($jobId);
        if ($row === null) {
            return [];
        }
        return $this->decodeSubtitleTracks($jobId, $row);
    }

    /**
     * Decodes a job row's `subtitle_tracks` JSON into public track descriptors.
     *
     * Subtitle extraction runs detached/async AFTER the encode (and a track may
     * fail to convert), so the persisted descriptor list can advertise more
     * tracks than actually materialized. To guarantee every advertised `url`
     * resolves (no 404s), a track is only returned when its `sub-{index}.vtt`
     * sidecar exists on disk in the job directory at response time.
     *
     * @param string               $jobId Job identifier (for the sidecar URLs).
     * @param array<string, mixed> $row   The transcode_jobs row.
     *
     * @return list<array{index: int, language: string, label: string, default: bool, url: string}>
     */
    private function decodeSubtitleTracks(string $jobId, array $row): array
    {
        $raw = $row['subtitle_tracks'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $dir = is_string($row['hls_dir'] ?? null) && $row['hls_dir'] !== ''
            ? (string) $row['hls_dir']
            : "{$this->segmentDir}/{$jobId}";

        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $index = is_int($entry['index'] ?? null)
                ? $entry['index']
                : (is_numeric($entry['index'] ?? null) ? (int) $entry['index'] : null);
            $filename = is_string($entry['filename'] ?? null) ? $entry['filename'] : null;
            if ($index === null || $filename === null) {
                continue;
            }
            // Only advertise a track whose .vtt actually exists on disk so the
            // returned url is guaranteed to resolve (extraction is async/may fail).
            if (!file_exists($dir . '/' . $filename)) {
                continue;
            }
            $out[] = [
                'index' => $index,
                'language' => is_string($entry['language'] ?? null) ? $entry['language'] : 'und',
                'label' => is_string($entry['label'] ?? null) ? $entry['label'] : 'Unknown',
                'default' => (bool) ($entry['default'] ?? false),
                'url' => "/hls/{$jobId}/{$filename}",
            ];
        }
        return $out;
    }

    /**
     * Reports on-disk readiness of an HLS job for client polling.
     *
     * Reads the completion markers and segment directory written by the detached
     * FFmpeg process (no in-memory process handle is needed, so this survives
     * worker reloads) and syncs the DB row's status on terminal transitions.
     *
     * @param string $jobId Job identifier.
     *
     * @return array{job_id: string, status: string, segments: int, playlist_ready: bool, progress: float,
     *     subtitles: list<array{index: int, language: string, label: string, default: bool, url: string}>}
     *
     * @since 0.23.0
     */
    public function getJobReadiness(string $jobId): array
    {
        $row = $this->getJobRow($jobId);
        if ($row === null) {
            return [
                'job_id' => $jobId,
                'status' => 'not_found',
                'segments' => 0,
                'playlist_ready' => false,
                'progress' => 0.0,
                'subtitles' => [],
            ];
        }

        $dir = is_string($row['hls_dir'] ?? null) ? (string) $row['hls_dir'] : "{$this->segmentDir}/{$jobId}";
        $dbStatus = is_string($row['status'] ?? null) ? (string) $row['status'] : self::STATUS_RUNNING;

        $segments = $this->countSegments($dir);
        // The VOD master playlist is written at job creation (before ensureHlsJob
        // returns), so its presence alone means the player can start — segments are
        // produced on demand (see ensureSegment()), so do NOT require any to exist yet.
        $playlistReady = file_exists("{$dir}/master.m3u8");

        $status = $dbStatus;
        // A completed job never regresses. An on-demand job is 'completed' from
        // creation (its complete VOD playlist IS the deliverable); the disk markers
        // below only describe a legacy linear encode that is still in flight.
        if ($dbStatus === self::STATUS_COMPLETED) {
            $status = self::STATUS_COMPLETED;
        } elseif ($dbStatus !== self::STATUS_CANCELLED) {
            if (file_exists("{$dir}/.failed")) {
                $status = self::STATUS_FAILED;
            } elseif (file_exists("{$dir}/.complete")) {
                $status = self::STATUS_COMPLETED;
            } elseif ($playlistReady) {
                $status = self::STATUS_RUNNING;
            }
        }

        if ($status !== $dbStatus) {
            if ($status === self::STATUS_COMPLETED) {
                $this->db->query(
                    "UPDATE transcode_jobs SET status = 'completed', progress = 100, completed_at = NOW() WHERE id = ?",
                    [$jobId]
                );
                $this->invalidateJobRowCache($jobId);
            } elseif ($status === self::STATUS_FAILED) {
                $error = $this->readFailureReason($dir);
                $this->db->query(
                    "UPDATE transcode_jobs SET status = 'failed', error = ? WHERE id = ?",
                    [$error, $jobId]
                );
                $this->invalidateJobRowCache($jobId);
            }
        }

        $progress = $status === self::STATUS_COMPLETED
            ? 100.0
            : ($playlistReady ? min(95.0, (float) $segments) : 0.0);

        return [
            'job_id' => $jobId,
            'status' => $status,
            'segments' => $segments,
            'playlist_ready' => $playlistReady,
            'progress' => $progress,
            'subtitles' => $this->decodeSubtitleTracks($jobId, $row),
        ];
    }

    /**
     * Returns the PLAYABLE variant list for a job, or null for a legacy
     * (`variants IS NULL`) job.
     *
     * The single source of truth clients read to build a quality picker. It
     * mirrors {@see \Phlix\Media\Streaming\LadderResult::streamVariants()}'s dedup
     * rule exactly: a non-copy "original" merely duplicates the top transcode rung
     * and is NOT listed separately (nothing can request it — see A5/A6's
     * {@see findRenditionArray()}); a genuine stream-copy "original" is a real
     * additional highest variant and is prepended. Each entry is a flat
     * {@see \Phlix\Media\Streaming\Rendition::toArray()} shape with `url` filled to
     * that variant's own media-playlist path (relative + UNSIGNED — the caller,
     * {@see \Phlix\Server\Http\Controllers\TranscodeController}, signs it, the same
     * as `master_url`/`hls_url`).
     *
     * Reads the persisted `variants` column (A5 writes exactly
     * {@see \Phlix\Media\Streaming\LadderResult::toArray()}:
     * `{renditions: [...highest-first], original: {...}}`). Defensive against a
     * missing/empty/corrupt column: any of those yields `null` rather than an
     * exception, so a malformed DB row can never blow up the request.
     *
     * @param string $jobId Transcode job id.
     *
     * @return list<array<string, mixed>>|null The playable variants (each with a
     *                                          relative unsigned `url`), or null
     *                                          for a legacy/malformed job.
     */
    public function getJobVariants(string $jobId): ?array
    {
        $cacheEntry = $this->jobRowEntry($jobId);
        if ($cacheEntry === null) {
            return null;
        }
        $row = $cacheEntry['row'];

        $variantsRaw = $row['variants'] ?? null;
        if (!is_string($variantsRaw) || $variantsRaw === '') {
            return null; // legacy single-variant job (variants IS NULL / empty)
        }

        $decoded = $cacheEntry['variants']; // parse memoised at cache population
        if (!is_array($decoded)) {
            return null; // corrupt/malformed JSON — never crash the request
        }

        // Collect the clamped rungs (highest-first, exactly as persisted).
        $playable = [];
        $rungs = $decoded['renditions'] ?? null;
        if (is_array($rungs)) {
            foreach ($rungs as $rung) {
                if (is_array($rung)) {
                    $playable[] = $rung;
                }
            }
        }

        // Mirror LadderResult::streamVariants(): a copy original is a genuine extra
        // (highest) variant → prepend; a non-copy original just mirrors the top rung
        // and is NOT separately playable → drop it.
        $original = $decoded['original'] ?? null;
        if (is_array($original) && ($original['is_copy'] ?? false) === true) {
            array_unshift($playable, $original);
        }

        // Fill each entry's own media-playlist url (relative, unsigned).
        $out = [];
        foreach ($playable as $entry) {
            $variantId = $entry['id'] ?? null;
            $variantId = is_string($variantId) ? $variantId : (is_scalar($variantId) ? (string) $variantId : '');
            // Normalise to string keys (JSON objects always decode with string keys;
            // this makes the array<string, mixed> shape explicit for the type checker).
            $normalized = [];
            foreach ($entry as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $normalized['url'] = "/hls/{$jobId}/media_v{$variantId}.m3u8";
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * Computes HLS encode parameters from a probe + device profile.
     *
     * Copies a browser-compatible stream when possible (h264 video without a
     * downscale, AAC audio) for a fast remux, otherwise encodes to H.264 / AAC.
     *
     * @param array<string, mixed> $probe       Raw ffprobe result.
     * @param string               $profileName Device profile name.
     *
     * @return array<string, mixed> Parameters for {@see FfmpegRunner::buildHlsCommand()}
     *                              plus variant_width/height/bandwidth descriptors.
     */
    private function computeHlsParams(array $probe, string $profileName): array
    {
        $video = $this->firstStreamOfType($probe, 'video');
        $audio = $this->firstStreamOfType($probe, 'audio');

        $srcV = strtolower(is_string($video['codec_name'] ?? null) ? (string) $video['codec_name'] : '');
        $srcA = strtolower(is_string($audio['codec_name'] ?? null) ? (string) $audio['codec_name'] : '');
        $srcPixFmt = strtolower(is_string($video['pix_fmt'] ?? null) ? (string) $video['pix_fmt'] : '');
        $srcProfile = strtolower(is_string($video['profile'] ?? null) ? (string) $video['profile'] : '');
        $width = $this->intVal($video['width'] ?? null);
        $height = $this->intVal($video['height'] ?? null);
        $channels = $this->intVal($audio['channels'] ?? null);

        [$maxW, $maxH] = self::PROFILE_MAX_RESOLUTION[$profileName] ?? self::PROFILE_MAX_RESOLUTION['web'];

        $needScale = ($width > 0 && $height > 0) && ($width > $maxW || $height > $maxH);
        $targetW = $needScale ? $maxW : $width;
        $targetH = $needScale ? $maxH : $height;

        $params = [
            'variant_index' => 0,
            'segment_seconds' => $this->segmentSeconds,
            // VOD, not 'event': the source is a fixed-length file, so the HLS
            // playlist must advertise EXT-X-PLAYLIST-TYPE:VOD and terminate with
            // EXT-X-ENDLIST. An 'event' (live) playlist makes hls.js treat the
            // stream as open-ended, so the player reports a duration that only
            // grows as segments arrive instead of the real total.
            'playlist_type' => 'vod',
        ];

        // Remux (copy) only a stream the browser can actually decode: 8-bit 4:2:0
        // H.264. A 10-bit (High 10) or 4:2:2/4:4:4 H.264 source copied as-is would
        // load but fail to decode in MSE/hls.js, so it must be re-encoded.
        if ($srcV === 'h264' && self::isBrowserSafeH264($srcPixFmt, $srcProfile) && !$needScale) {
            $params['video_codec'] = 'copy';
        } else {
            $params['video_codec'] = 'libx264';
            $params['preset'] = 'veryfast';
            $params['crf'] = 23;
            // 8-bit 4:2:0 High@4.1 — the browser-decodable baseline (see
            // FfmpegRunner::browserSafeVideoFlags). Explicit here so the policy
            // lives with the rest of the encode decision.
            $params['pix_fmt'] = 'yuv420p';
            $params['profile'] = 'high';
            $params['level'] = '4.1';
            if ($needScale) {
                $params['width'] = $maxW;
                $params['height'] = $maxH;
            }
        }

        if ($srcA === 'aac') {
            $params['audio_codec'] = 'copy';
        } else {
            $params['audio_codec'] = 'aac';
            $params['audio_bitrate'] = '128k';
            $params['audio_sample_rate'] = 48000;
            if ($channels > 0) {
                $params['audio_channels'] = min($channels, 6);
            }
        }

        $params['variant_width'] = $targetW > 0 ? $targetW : null;
        $params['variant_height'] = $targetH > 0 ? $targetH : null;
        $params['variant_bandwidth'] = self::estimateBandwidth($targetH > 0 ? $targetH : 720);

        return $params;
    }

    /**
     * Estimates a nominal HLS variant bandwidth (bits/sec) from height.
     *
     * Advisory only — used for the master playlist's BANDWIDTH attribute.
     *
     * @param int $height Variant pixel height.
     *
     * @return int Nominal bandwidth in bits per second.
     */
    private static function estimateBandwidth(int $height): int
    {
        return match (true) {
            $height >= 2160 => 16000000,
            $height >= 1080 => 5000000,
            $height >= 720 => 2800000,
            $height >= 480 => 1100000,
            default => 700000,
        };
    }

    /**
     * Finds a reusable (running or completed) job whose output still exists.
     *
     * @param string $keyHash sha1(media_item_id|profile) reuse key.
     *
     * @return string|null Job id to reuse, or null when none is usable.
     */
    private function findReusableJob(string $keyHash): ?string
    {
        // Only reuse ON-DEMAND jobs (segment_params IS NOT NULL). A legacy linear
        // CMAF job with the same key_hash left in the table across the upgrade must
        // NOT be reused — its playlist is the old live, ever-growing one — so it is
        // skipped here and a fresh on-demand job is created instead.
        $result = $this->db->query(
            "SELECT id, hls_dir, status FROM transcode_jobs
             WHERE key_hash = ? AND status IN ('running', 'completed') AND segment_params IS NOT NULL
             ORDER BY started_at DESC LIMIT 1",
            [$keyHash]
        );
        $rows = RowMap::listFromMixed($result);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $id = is_string($row['id'] ?? null) ? (string) $row['id'] : '';
        $dir = is_string($row['hls_dir'] ?? null) ? (string) $row['hls_dir'] : '';
        if ($id === '' || $dir === '' || !is_dir($dir)) {
            return null;
        }
        return $id;
    }

    /**
     * Counts the running jobs as recorded in the database.
     *
     * @return int Number of jobs with status='running'.
     */
    private function getRunningJobCount(): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS c FROM transcode_jobs WHERE status = 'running'");
        $rows = RowMap::listFromMixed($result);
        $c = $rows[0]['c'] ?? 0;
        return is_numeric($c) ? (int) $c : 0;
    }

    /**
     * Reads a single transcode_jobs row as an associative array.
     *
     * @param string $jobId Job identifier.
     *
     * @return array<string, mixed>|null The row, or null if not found.
     */
    private function getJobRow(string $jobId): ?array
    {
        $entry = $this->jobRowEntry($jobId);
        return $entry === null ? null : $entry['row'];
    }

    /**
     * Fetches a job's cache entry (narrowed row + parsed variants ladder), populating
     * the in-worker LRU on a miss.
     *
     * Coroutine-safe without an explicit lock: the only yield point is the DB query on
     * a miss, and two coroutines that both miss simply repopulate the same immutable
     * row (harmless last-write-wins). Every map mutation here (touch, insert, evict) is
     * a plain array op with no interleaved yield, so it is atomic under Swoole's
     * cooperative scheduler; the DB query itself is already serialized by the
     * connection's coroutine mutex.
     *
     * @param string $jobId Job identifier.
     *
     * @return array{row: array<string, mixed>, variants: array<mixed>|null}|null
     *         The cache entry, or null if the job does not exist.
     */
    private function jobRowEntry(string $jobId): ?array
    {
        if (isset($this->jobRowCache[$jobId])) {
            $entry = $this->jobRowCache[$jobId];
            unset($this->jobRowCache[$jobId]);
            $this->jobRowCache[$jobId] = $entry; // move to MRU position for LRU eviction
            return $entry;
        }

        $result = $this->db->query(
            'SELECT ' . self::JOB_ROW_COLUMNS . ' FROM transcode_jobs WHERE id = ?',
            [$jobId]
        );
        $rows = RowMap::listFromMixed($result);
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        $variants = null;
        $variantsRaw = $row['variants'] ?? null;
        if (is_string($variantsRaw) && $variantsRaw !== '') {
            $decoded = json_decode($variantsRaw, true);
            $variants = is_array($decoded) ? $decoded : null;
        }

        $entry = ['row' => $row, 'variants' => $variants];
        $this->jobRowCache[$jobId] = $entry;
        if (count($this->jobRowCache) > self::JOB_ROW_CACHE_MAX) {
            $oldest = array_key_first($this->jobRowCache);
            if ($oldest !== null) {
                unset($this->jobRowCache[$oldest]);
            }
        }

        return $entry;
    }

    /**
     * Drops a job's cached row so the next read re-fetches from the DB. Called on
     * every status write (completion, cancel, reap) to keep the cache coherent with
     * the persisted terminal state.
     *
     * @param string $jobId Job identifier.
     */
    private function invalidateJobRowCache(string $jobId): void
    {
        unset($this->jobRowCache[$jobId]);
    }

    /**
     * Returns the current DB status of a job (defaults to 'running').
     *
     * @param string $jobId Job identifier.
     *
     * @return string Status string.
     */
    private function statusOf(string $jobId): string
    {
        $row = $this->getJobRow($jobId);
        return is_string($row['status'] ?? null) ? (string) $row['status'] : self::STATUS_RUNNING;
    }

    /**
     * Counts produced CMAF media segments in a job directory.
     *
     * @param string $dir Job output directory.
     *
     * @return int Number of chunk-*.m4s files (across all representations).
     */
    private function countSegments(string $dir): int
    {
        // Legacy linear CMAF jobs write `chunk-*.m4s`; on-demand jobs write
        // `seg-*.ts` as they are requested (the `seg-*.ts.part-*` temps do not match
        // the `.ts` glob, so half-written segments are not counted).
        $cmaf = glob("{$dir}/chunk-*.m4s");
        $ts = glob("{$dir}/seg-*.ts");
        return (is_array($cmaf) ? count($cmaf) : 0) + (is_array($ts) ? count($ts) : 0);
    }

    /**
     * Reads a short failure reason from the job's ffmpeg log.
     *
     * @param string $dir Job HLS directory.
     *
     * @return string Trimmed tail of the ffmpeg log, or a generic message.
     */
    private function readFailureReason(string $dir): string
    {
        $log = "{$dir}/ffmpeg.log";
        if (is_file($log)) {
            $content = file_get_contents($log);
            if (is_string($content) && $content !== '') {
                return substr(trim($content), -500);
            }
        }
        return 'Transcode failed';
    }

    /**
     * Whether an H.264 stream is one browsers can decode via MSE/hls.js.
     *
     * Browser H.264 decoders handle only 8-bit 4:2:0 (Baseline/Main/High). A
     * 10-/12-bit pixel format (e.g. `yuv420p10le`), a 4:2:2/4:4:4 chroma layout,
     * or a "High 10 / High 4:2:2 / High 4:4:4" profile cannot be decoded and must
     * be re-encoded rather than copied. Unknown/empty values are treated as safe
     * to preserve the fast copy path for ordinary 8-bit files (ffprobe reliably
     * reports `pix_fmt` for H.264, so empty only happens when probing failed).
     */
    private static function isBrowserSafeH264(string $pixFmt, string $profile): bool
    {
        $pix = strtolower($pixFmt);
        if (str_contains($pix, '10') || str_contains($pix, '12')) {
            return false; // 10-/12-bit
        }
        if (str_starts_with($pix, 'yuv422') || str_starts_with($pix, 'yuv444')) {
            return false; // non-4:2:0 chroma
        }
        $prof = strtolower($profile);
        foreach (['high 10', 'high 4:2:2', 'high 4:4:4'] as $unsafe) {
            if (str_contains($prof, $unsafe)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the first stream of the given codec type from a raw probe.
     *
     * @param array<string, mixed> $probe Raw ffprobe result.
     * @param string               $type  'video' or 'audio'.
     *
     * @return array<string, mixed> The stream, or an empty array.
     */
    private function firstStreamOfType(array $probe, string $type): array
    {
        $streams = $probe['streams'] ?? [];
        if (!is_array($streams)) {
            return [];
        }
        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
                /** @var array<string, mixed> $stream */
                return $stream;
            }
        }
        return [];
    }

    /**
     * Coerces a mixed probe value to a non-negative int (0 when not numeric).
     *
     * @param mixed $value Raw value.
     *
     * @return int Parsed int, or 0.
     */
    private function intVal(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * Resolve the {@see SourceProfile} the ABR ladder is built from.
     *
     * Prefers A1's persisted `metadata_json['source']` blob when it carries real
     * dimensions (width + height both > 0), so the ladder is built without leaning on
     * the live probe. Falls back to deriving the profile from the probe we already
     * have in hand for older items that predate the A1 backfill.
     *
     * @param array<string, mixed> $item  The media_items row (carries metadata_json).
     * @param array<string, mixed> $probe Raw ffprobe result.
     */
    private function sourceProfileForItem(array $item, array $probe): SourceProfile
    {
        $source = $this->persistedSourceMetadata($item);
        if (
            $source !== null
            && $this->intVal($source['width'] ?? null) > 0
            && $this->intVal($source['height'] ?? null) > 0
        ) {
            return SourceProfile::fromSourceMetadata($source);
        }

        return $this->sourceProfileFromProbe($probe);
    }

    /**
     * Whether A1's persisted source metadata is fresh enough to build the HLS job
     * without leaning on a live probe.
     *
     * "Fresh" means the scan captured real source dimensions (width + height both
     * > 0, mirroring {@see sourceProfileForItem()}'s gate so the ladder source and
     * the skip decision never disagree) AND a positive source duration is stored
     * under `metadata_json['duration_seconds']`. Older items that predate the A1
     * backfill fail this test and fall back to the (now non-blocking) probe.
     *
     * @param array<string, mixed> $item The media_items row (carries metadata_json).
     */
    private function sourceMetadataFresh(array $item): bool
    {
        $source = $this->persistedSourceMetadata($item);
        if (
            $source === null
            || $this->intVal($source['width'] ?? null) <= 0
            || $this->intVal($source['height'] ?? null) <= 0
        ) {
            return false;
        }

        return $this->persistedDurationSeconds($item) > 0.0;
    }

    /**
     * Read the persisted source duration (seconds) from `metadata_json`.
     *
     * Reads the same `duration_seconds` key {@see persistProbedDuration()} writes,
     * so the scan- and transcode-time paths agree on the stored length. Returns
     * 0.0 when absent or non-positive.
     *
     * @param array<string, mixed> $item The media_items row (carries metadata_json).
     */
    private function persistedDurationSeconds(array $item): float
    {
        $metaRaw = $item['metadata_json'] ?? null;
        $meta = null;
        if (is_string($metaRaw) && $metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($metaRaw)) {
            $meta = $metaRaw;
        }
        if ($meta === null) {
            return 0.0;
        }

        $raw = $meta['duration_seconds'] ?? null;
        $seconds = is_numeric($raw) ? (float) $raw : 0.0;

        return $seconds > 0.0 ? $seconds : 0.0;
    }

    /**
     * Extract A1's persisted `metadata_json['source']` descriptor from a media_items row.
     *
     * @param array<string, mixed> $item The media_items row.
     *
     * @return array<string, mixed>|null The source blob, or null when absent/malformed.
     */
    private function persistedSourceMetadata(array $item): ?array
    {
        $metaRaw = $item['metadata_json'] ?? null;
        $meta = null;
        if (is_string($metaRaw) && $metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($metaRaw)) {
            $meta = $metaRaw;
        }
        if ($meta === null) {
            return null;
        }

        $source = $meta['source'] ?? null;
        if (!is_array($source)) {
            return null;
        }

        // Normalise to string keys for the SourceProfile contract.
        $out = [];
        foreach ($source as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Derive a {@see SourceProfile} from a live ffprobe result.
     *
     * Mirrors {@see computeHlsParams()}'s field extraction: first video/audio stream
     * codec name / dimensions / pix_fmt, and the video bitrate from the stream's
     * `bit_rate` (falling back to `format.bit_rate` when the stream omits it). Absent
     * or non-positive fields map to null so the ladder's source-clamp sees "unknown".
     *
     * @param array<string, mixed> $probe Raw ffprobe result.
     */
    private function sourceProfileFromProbe(array $probe): SourceProfile
    {
        $video = $this->firstStreamOfType($probe, 'video');
        $audio = $this->firstStreamOfType($probe, 'audio');
        $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];

        $width = $this->intVal($video['width'] ?? null);
        $height = $this->intVal($video['height'] ?? null);
        $videoBitrate = $this->intVal($video['bit_rate'] ?? null);
        if ($videoBitrate <= 0) {
            $videoBitrate = $this->intVal($format['bit_rate'] ?? null);
        }
        $audioBitrate = $this->intVal($audio['bit_rate'] ?? null);

        return new SourceProfile(
            width: $width > 0 ? $width : null,
            height: $height > 0 ? $height : null,
            videoCodec: $this->probeString($video['codec_name'] ?? null),
            videoBitrate: $videoBitrate > 0 ? $videoBitrate : null,
            audioCodec: $this->probeString($audio['codec_name'] ?? null),
            audioBitrate: $audioBitrate > 0 ? $audioBitrate : null,
            pixFmt: $this->probeString($video['pix_fmt'] ?? null),
        );
    }

    /**
     * Coerce a mixed probe value to a non-empty string, or null.
     */
    private function probeString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Normalize an untyped probe payload to the shape EncodingHelper expects.
     *
     * @param array<string, mixed> $sourceInfo
     * @return array{streams: array<int, array{codec_type: string, codec?: string, width?: int, height?: int, bitrate?: int, channels?: int}>, format?: array{format_name?: string}}
     */
    private function normalizeSourceInfo(array $sourceInfo): array
    {
        $streamsRaw = $sourceInfo['streams'] ?? [];
        $streams = [];
        if (is_array($streamsRaw)) {
            foreach ($streamsRaw as $streamRaw) {
                if (!is_array($streamRaw)) {
                    continue;
                }
                $stream = ['codec_type' => is_string($streamRaw['codec_type'] ?? null) ? (string) $streamRaw['codec_type'] : ''];
                if (isset($streamRaw['codec']) && is_string($streamRaw['codec'])) {
                    $stream['codec'] = $streamRaw['codec'];
                }
                if (isset($streamRaw['width']) && is_int($streamRaw['width'])) {
                    $stream['width'] = $streamRaw['width'];
                }
                if (isset($streamRaw['height']) && is_int($streamRaw['height'])) {
                    $stream['height'] = $streamRaw['height'];
                }
                if (isset($streamRaw['bitrate']) && is_int($streamRaw['bitrate'])) {
                    $stream['bitrate'] = $streamRaw['bitrate'];
                }
                if (isset($streamRaw['channels']) && is_int($streamRaw['channels'])) {
                    $stream['channels'] = $streamRaw['channels'];
                }
                $streams[] = $stream;
            }
        }

        $out = ['streams' => $streams];
        $formatRaw = $sourceInfo['format'] ?? null;
        if (is_array($formatRaw) && isset($formatRaw['format_name']) && is_string($formatRaw['format_name'])) {
            $out['format'] = ['format_name' => $formatRaw['format_name']];
        }

        return $out;
    }

    /**
     * Normalize an untyped device profile to the shape EncodingHelper expects.
     *
     * @param array<int|string, mixed> $profile
     * @return array{max_bitrate?: int, max_resolution?: array<int, int>, direct_play?: array<string>, transcode?: array<string>}
     */
    private function normalizeProfile(array $profile): array
    {
        $out = [];

        if (isset($profile['max_bitrate']) && is_int($profile['max_bitrate'])) {
            $out['max_bitrate'] = $profile['max_bitrate'];
        }

        if (isset($profile['max_resolution']) && is_array($profile['max_resolution'])) {
            $res = [];
            foreach ($profile['max_resolution'] as $dim) {
                if (is_int($dim)) {
                    $res[] = $dim;
                }
            }
            if (count($res) >= 2) {
                $out['max_resolution'] = [$res[0], $res[1]];
            }
        }

        foreach (['direct_play', 'transcode'] as $key) {
            if (!isset($profile[$key]) || !is_array($profile[$key])) {
                continue;
            }
            $codecs = [];
            foreach ($profile[$key] as $codec) {
                if (is_string($codec)) {
                    $codecs[] = $codec;
                }
            }
            $out[$key] = $codecs;
        }

        return $out;
    }

    /**
     * Stops a running transcode job.
     *
     * Terminates the job, deletes output files, and updates the database
     * status to 'cancelled'.
     *
     * @param string $jobId Job identifier
     */
    public function stopTranscode(string $jobId): void
    {
        if (!isset($this->activeJobs[$jobId])) {
            return;
        }

        $job = $this->activeJobs[$jobId];

        $dir = dirname($job['output_path']);
        if (is_dir($dir)) {
            // Use cached glob results if available and not expired
            $now = time();
            if (isset(self::$globCache[$dir]) && self::$globCache[$dir]['expires_at'] > $now) {
                $files = self::$globCache[$dir]['files'];
            } else {
                $files = glob("{$dir}/*") ?: [];
                self::$globCache[$dir] = [
                    'files' => $files,
                    'expires_at' => $now + self::GLOB_CACHE_TTL,
                ];
            }

            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir);

            // Clean up cache entry for this directory
            unset(self::$globCache[$dir]);
        }

        $this->db->query("UPDATE transcode_jobs SET status = 'cancelled' WHERE id = ?", [$jobId]);
        $this->invalidateJobRowCache($jobId);

        unset($this->activeJobs[$jobId]);

        $this->logger->info('Transcode cancelled', ['job_id' => $jobId]);
    }

    /**
     * Gets the status of a transcode job.
     *
     * @param string $jobId Job identifier
     *
     * @return array{
     *     id: string,
     *     status: string,
     *     output_path?: string
     * }|null Job status array or null if not found
     */
    public function getTranscodeStatus(string $jobId): ?array
    {
        if (isset($this->activeJobs[$jobId])) {
            return [
                'id' => $jobId,
                'status' => self::STATUS_RUNNING,
                'output_path' => $this->activeJobs[$jobId]['output_path'],
            ];
        }

        $result = $this->db->query("SELECT * FROM transcode_jobs WHERE id = ?", [$jobId]);
        $rows = RowMap::listFromMixed($result);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $status = [
            'id' => is_string($row['id'] ?? null) ? (string) $row['id'] : $jobId,
            'status' => is_string($row['status'] ?? null) ? (string) $row['status'] : self::STATUS_PENDING,
        ];
        if (isset($row['output_path']) && is_string($row['output_path'])) {
            $status['output_path'] = $row['output_path'];
        }

        return $status;
    }

    /**
     * Gets count of currently running transcode jobs.
     *
     * Any entry in {@see self::$activeJobs} is by definition running — completed,
     * failed, and cancelled jobs are removed from the map.
     *
     * @return int Number of active transcodes
     */
    public function getActiveTranscodeCount(): int
    {
        return count($this->activeJobs);
    }

    /**
     * Returns the configured maximum concurrent transcode budget.
     *
     * @return int Concurrency limit applied by {@see self::startTranscode()}
     */
    public function getMaxConcurrentTranscodes(): int
    {
        return $this->maxConcurrentTranscodes;
    }

    /**
     * Returns the configured HLS segment directory.
     *
     * @return string Base directory where segments are written by external HLS
     *                writers when transcoding to HLS variants.
     */
    public function getSegmentDir(): string
    {
        return $this->segmentDir;
    }

    /**
     * Cleans up stale transcode jobs older than max age.
     *
     * Identifies jobs that have been running longer than the specified
     * threshold and stops them to free resources.
     *
     * @param int $maxAgeSeconds Maximum job age in seconds (default: 3600)
     */
    public function cleanupStaleJobs(int $maxAgeSeconds = 3600): void
    {
        $cutoff = time() - $maxAgeSeconds;

        foreach ($this->activeJobs as $jobId => $job) {
            if ($job['started_at'] < $cutoff) {
                $this->stopTranscode($jobId);
                $this->logger->warning('Cleaned up stale transcode job', ['job_id' => $jobId]);
            }
        }

        // Also reap ghosts recorded only in the DB (see reapStaleRunningJobs).
        $this->reapStaleRunningJobs($maxAgeSeconds);
    }

    /**
     * Interval (seconds) between reaper runs — keeps the concurrency gate clean.
     */
    public const REAPER_INTERVAL = 45;

    /**
     * Age after which a still-'running' job is considered stale and reaped.
     *
     * Defaults to 120 s so a wedged encode frees its concurrency slot promptly
     * without being so aggressive that a legitimately slow encode (e.g. 4K on
     * a low-power machine) is killed mid-flight.
     *
     * @var int
     */
    public const STALE_JOB_MAX_AGE = 120;

    /**
     * Window (seconds) within which at least one CMAF segment must appear.
     *
     * A job that has been running longer than this without producing any
     * segment is almost certainly wedged — ffmpeg is stuck and will never make
     * progress, so the slot is freed immediately rather than waiting for the
     * stale-age timer to fire.
     *
     * @var int
     */
    public const SEGMENT_PRODUCTION_TIMEOUT = 60;

    /**
     * Reap DB-recorded 'running' jobs that are no longer alive, marking them
     * 'failed' so they stop counting against {@see $maxConcurrentTranscodes}.
     *
     * The concurrency gate counts `status='running'` rows in the DB, but
     * {@see cleanupStaleJobs()} historically only knew about the in-memory
     * {@see $activeJobs} map. When the worker restarts (e.g. after a deploy) that
     * map is empty while the DB still holds the previous run's 'running' rows —
     * ghosts with no live ffmpeg behind them. Enough of them
     * ({@see $maxConcurrentTranscodes}) and every new playback request is refused
     * with "Maximum concurrent transcodes reached", which surfaces to the user as
     * a video that simply will not play. A job is considered dead when:
     *
     *   - its working directory is gone (a live job creates that dir before it
     *     inserts the row, so a 'running' row without one is a corpse), or
     *   - it started more than STALE_JOB_MAX_AGE seconds ago (a wedged/abandoned
     *     encode that has been running too long without completing), or
     *   - it started more than SEGMENT_PRODUCTION_TIMEOUT seconds ago and has
     *     produced zero segments (ffmpeg is stuck at the very beginning).
     *
     * @param int $maxAgeSeconds Age after which a still-'running' job is stale
     *                           (default STALE_JOB_MAX_AGE; pass a higher value
     *                           to override for one call only).
     *
     * @return int Number of jobs reaped.
     */
    public function reapStaleRunningJobs(int $maxAgeSeconds = self::STALE_JOB_MAX_AGE): int
    {
        $result = $this->db->query(
            "SELECT id, hls_dir, output_path, started_at FROM transcode_jobs WHERE status = 'running'"
        );
        $rows = RowMap::listFromMixed($result);
        $cutoff = time() - max(0, $maxAgeSeconds);
        $segmentTimeoutCutoff = time() - self::SEGMENT_PRODUCTION_TIMEOUT;
        $reaped = 0;

        foreach ($rows as $row) {
            $id = is_string($row['id'] ?? null) ? (string) $row['id'] : '';
            if ($id === '') {
                continue;
            }

            $dir = is_string($row['hls_dir'] ?? null) && $row['hls_dir'] !== ''
                ? (string) $row['hls_dir']
                : (is_string($row['output_path'] ?? null) ? dirname((string) $row['output_path']) : '');
            $dirGone = $dir !== '' && !is_dir($dir);

            $startedAt = is_string($row['started_at'] ?? null) ? strtotime((string) $row['started_at']) : false;
            $tooOld = $startedAt !== false && $startedAt < $cutoff;

            // No segment produced within the timeout window → wedged at startup.
            $noSegmentWithinTimeout = false;
            if (!$dirGone && $startedAt !== false && $startedAt < $segmentTimeoutCutoff) {
                $segmentFiles = glob("{$dir}/chunk-*.m4s");
                $noSegmentWithinTimeout = ($segmentFiles === false || count($segmentFiles) === 0);
            }

            if (!$dirGone && !$tooOld && !$noSegmentWithinTimeout) {
                continue;
            }

            $error = match (true) {
                $dirGone => 'reaped: working directory missing (dead process)',
                $noSegmentWithinTimeout => 'reaped: no segment produced within ' . self::SEGMENT_PRODUCTION_TIMEOUT . 's (wedged)',
                default => 'reaped: exceeded max age',
            };

            $this->db->query(
                "UPDATE transcode_jobs SET status = 'failed', error = ? WHERE id = ? AND status = 'running'",
                [$error, $id]
            );
            $this->invalidateJobRowCache($id);
            unset($this->activeJobs[$id]);
            $reaped++;
        }

        if ($reaped > 0) {
            $this->logger->warning('Reaped stale transcode jobs', ['count' => $reaped]);
        }

        // F6: track when the reaper last ran.
        $this->lastReaperRun = time();

        return $reaped;
    }

    /**
     * Return statistics about currently-running transcode jobs.
     *
     * F6: Back the /admin/health/jobs endpoint. Returns the count of jobs in
     * the `running` state and the age of the oldest one.
     *
     * @return array{running: int, oldest_age_seconds: int|null, oldest_started_at: string|null}
     *         running: Number of jobs currently in `running` state.
     *         oldest_age_seconds: Seconds since the oldest running job started, or null if none.
     *         oldest_started_at: ISO-8601 timestamp of the oldest running job, or null.
     *
     * @since F6
     */
    public function getTranscodeJobStats(): array
    {
        $result = $this->db->query(
            "SELECT id, started_at FROM transcode_jobs WHERE status = 'running' ORDER BY started_at ASC"
        );
        $rows = RowMap::listFromMixed($result);
        $running = count($rows);

        if ($running === 0) {
            return [
                'running' => 0,
                'oldest_age_seconds' => null,
                'oldest_started_at' => null,
            ];
        }

        $oldestRow = $rows[0];
        $startedAt = is_string($oldestRow['started_at'] ?? null)
            ? strtotime((string) $oldestRow['started_at'])
            : false;

        return [
            'running' => $running,
            'oldest_age_seconds' => $startedAt !== false ? time() - $startedAt : null,
            'oldest_started_at' => $startedAt !== false
                ? date('c', $startedAt)
                : null,
        ];
    }

    /**
     * Return the Unix timestamp of when the reaper last ran, or null if never.
     *
     * F6: Surface the last reaper run time in the /admin/health/jobs response.
     *
     * @return int|null Unix timestamp, or null if reapStaleRunningJobs() has
     *                  never been called in this process lifetime.
     *
     * @since F6
     */
    public function getLastReaperRun(): ?int
    {
        return $this->lastReaperRun;
    }

    /**
     * Persist the precise source duration (seconds) probed at transcode time onto
     * the media item's metadata, so the rest of the app has an authoritative
     * length without re-probing. Stored under `duration_seconds` to avoid
     * clobbering TMDB's `runtime` (which is in minutes). Idempotent: a value that
     * is already present is left untouched.
     *
     * @param string               $mediaItemId Media item UUID.
     * @param array<string, mixed> $item        The media_items row (carries metadata_json).
     * @param array<string, mixed> $probe       Raw ffprobe result (expects format.duration).
     */
    private function persistProbedDuration(string $mediaItemId, array $item, array $probe): void
    {
        $format = is_array($probe['format'] ?? null) ? $probe['format'] : [];
        $rawDuration = $format['duration'] ?? null;
        if (!is_numeric($rawDuration)) {
            return;
        }
        $duration = (int) round((float) $rawDuration);
        if ($duration <= 0) {
            return;
        }

        $metaRaw = $item['metadata_json'] ?? null;
        $meta = [];
        if (is_string($metaRaw) && $metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($metaRaw)) {
            $meta = $metaRaw;
        }

        if (isset($meta['duration_seconds']) && is_numeric($meta['duration_seconds'])) {
            return;
        }
        $meta['duration_seconds'] = $duration;

        $encoded = json_encode($meta);
        if ($encoded === false) {
            return;
        }

        $this->db->query(
            "UPDATE media_items SET metadata_json = ? WHERE id = ?",
            [$encoded, $mediaItemId]
        );
    }

    /**
     * Retrieves media item from database.
     *
     * @param string $itemId Media item identifier
     *
     * @return array<string, mixed>|null Media item row or null
     */
    private function getMediaItem(string $itemId): ?array
    {
        $result = $this->db->query("SELECT * FROM media_items WHERE id = ?", [$itemId]);
        $rows = RowMap::listFromMixed($result);
        return $rows[0] ?? null;
    }

    /**
     * Starts a periodic timer that reaps stale transcode jobs.
     *
     * Runs every REAPER_INTERVAL seconds indefinitely via Workerman's
     * Timer::add. Safe to call multiple times — the timer is self-de-duplicating
     * by interval on the Workerman side. Logs any reaper errors so a stuck
     * reaper can never crash the worker.
     *
     * @param int|null $interval Override the interval in seconds
     *
     * @return void
     *
     * @since 0.26.0
     */
    public function startReaperTimer(?int $interval = null): void
    {
        $intervalSeconds = $interval ?? self::REAPER_INTERVAL;

        \Workerman\Timer::add(
            $intervalSeconds,
            function (): void {
                try {
                    $this->reapStaleRunningJobs();
                    $this->sweepSegmentCache();
                } catch (\Throwable $e) {
                    $this->logger->error('Reaper timer failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            },
        );
    }

    /**
     * Generates a UUID v4 identifier.
     *
     * @return string UUID string
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
