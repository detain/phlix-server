<?php

/**
 * Phlix media server component: Transcoding.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelProfileFactory;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlix\Media\Transcoding\HardwareAccelerator;
use Phlix\Media\Transcoding\Subtitles\SubtitleBurner;
use Phlix\Media\Transcoding\Subtitles\SubtitleFormat;
use Phlix\Media\Transcoding\Subtitles\SubtitleTrack;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FFmpeg Runner - Executes FFmpeg and FFprobe commands for media transcoding.
 *
 * Provides a clean interface for probing media files and running transcode
 * operations with proper process management and error handling.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description FFmpeg/FFprobe process execution with command building and error handling
 * @see https://ffmpeg.org/documentation.html
 */
class FfmpegRunner
{
    /**
     * Grace (seconds) `timeout` waits after its initial SIGTERM before it
     * escalates to SIGKILL of the encode's process group (SV-4.2 finding #4:
     * `timeout -k <grace> -s TERM`). Short — a stuck segment encode is
     * disposable and should die quickly once the transcode timeout fires.
     */
    private const TIMEOUT_KILL_GRACE_SECONDS = 10;

    /** @var string Path to FFmpeg binary */
    private string $ffmpegPath;

    /** @var string Path to FFprobe binary */
    private string $ffprobePath;

    /** @var string Default directory for transcoded output */
    private string $transcodeDir;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var HwaccelRegistry|null Hardware acceleration registry */
    private ?HwaccelRegistry $hwaccelRegistry = null;

    /** @var HwaccelProfileFactory|null Lazily-built factory for per-vendor encoder profiles */
    private ?HwaccelProfileFactory $hwaccelProfileFactory = null;

    /** @var bool Whether hardware acceleration has been probed */
    private static bool $hwaccelProbed = false;

    /** @var array<string, HardwareAccelerator>|null Cached hardware accelerators */
    private ?array $hardwareAccelerators = null;

    /** @var string|null Preferred accelerator name from config (e.g., 'cuda', 'qsv') */
    private ?string $preferredAccelerator = null;

    /** @var array<mixed, mixed> Transcoding configuration */
    private array $config = [];

    /**
     * Per-worker probe memoization cache: keyed by "path:mtime", value is the
     * cached probe result. Caps ffprobe invocations to at most one per file per
     * worker lifetime — critical for the tone-map decision path where multiple
     * segment encodes can ask about the same file in quick succession.
     *
     * @var array<string, array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}|null>
     */
    private array $probeMemo = [];

    /** @var bool|null Whether FFmpeg has libplacebo filter support (null = not yet checked) */
    private ?bool $hasLibplacebo = null;

    /**
     * Lazily-built {@see SubtitleBurner} used by {@see resolveSubtitleBurnInFilter()}
     * to turn a `subtitle_burn_in` segment param into a filtergraph-escaped
     * `subtitles=`/`ass=` filter fragment for the LIVE per-segment pipeline
     * (SV-1.6). Constructed on first use rather than injected, since
     * {@see SubtitleBurner}'s only dependency on this class
     * ({@see extractSubtitle()}) is unused by the filter-building path this
     * consumes — avoiding any circular-construction concern.
     */
    private ?SubtitleBurner $subtitleBurner = null;

    /**
     * Per-worker registry of detached segment-encode PIDs, keyed by cancel key,
     * so an abandoned/timed-out on-demand encode can be killed instead of
     * running to completion (SV-4.2 [S-F23]). Null until wired via
     * {@see setSegmentProcessRegistry()}; when null, segment PID tracking is a
     * no-op (the `timeout <n>` wrapper still bounds the encode).
     */
    private ?SegmentProcessRegistry $segmentRegistry = null;

    /**
     * Creates a new FFmpegRunner instance.
     *
     * @param string $ffmpegPath Path to FFmpeg binary (default: /usr/bin/ffmpeg)
     * @param string $ffprobePath Path to FFprobe binary (default: /usr/bin/ffprobe)
     * @param string $transcodeDir Default output directory (default: /var/transcodes)
     * @param LoggerInterface|null $logger Optional PSR logger
     *
     * @example
     * ```php
     * $runner = new FfmpegRunner('/usr/local/bin/ffmpeg', '/usr/local/bin/ffprobe', '/tmp/transcodes');
     * ```
     */
    public function __construct(
        string $ffmpegPath = '/usr/bin/ffmpeg',
        string $ffprobePath = '/usr/bin/ffprobe',
        string $transcodeDir = '/var/transcodes',
        ?LoggerInterface $logger = null
    ) {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
        $this->transcodeDir = $transcodeDir;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Wire the per-worker segment-process registry used to track and cancel
     * detached on-demand encodes (SV-4.2 [S-F23]).
     *
     * Injected via a setter (mirroring {@see setConfig()}) so the constructor
     * signature — relied on by tests and both bootstraps — is unchanged.
     *
     * @param SegmentProcessRegistry $registry Shared per-worker registry.
     *
     * @since SV-4.2
     */
    public function setSegmentProcessRegistry(SegmentProcessRegistry $registry): void
    {
        $this->segmentRegistry = $registry;
    }

    /**
     * Drop the tracked PID(s) for a completed segment encode without killing
     * (the caller observed the segment published on its own). No-op when no
     * registry is wired. Callers MUST invoke this (or {@see killSegmentProcess()})
     * for every launched cancel key so the registry never leaks.
     *
     * @param string $cancelKey The key passed to {@see startSegmentEncode()}.
     *
     * @since SV-4.2
     */
    public function releaseSegmentProcess(string $cancelKey): void
    {
        $this->segmentRegistry?->release($cancelKey);
    }

    /**
     * Wait-timeout release for the transcode poll loop: stop tracking the encode
     * WE launched but do NOT kill it — a single request's poll wait timing out is
     * not abandonment, so a slow-but-wanted encode is left to finish and publish
     * for a retrying requester (SV-4.2 finding #2). If the encode already died
     * without publishing, its orphaned `.part-*` temp is cleaned (finding #1).
     * No-op when no registry is wired.
     *
     * @param string $cancelKey The key passed to {@see startSegmentEncode()}.
     *
     * @since SV-4.2
     */
    public function releaseSegmentProcessAfterWaitTimeout(string $cancelKey): void
    {
        $this->segmentRegistry?->releaseAfterWaitTimeout($cancelKey);
    }

    /**
     * Kill the detached ffmpeg process group(s) tracked for a cancel key
     * (SIGTERM → SIGKILL to the group, coroutine-safe), remove the orphaned
     * `.part-*` temp, and drop the entry. Used by cancel/disconnect hooks on
     * genuine abandonment. No-op when no registry is wired or the key is unknown.
     *
     * @param string $cancelKey The key passed to {@see startSegmentEncode()}.
     *
     * @return int Number of PIDs signalled.
     *
     * @since SV-4.2
     */
    public function killSegmentProcess(string $cancelKey): int
    {
        return $this->segmentRegistry?->kill($cancelKey) ?? 0;
    }

    /**
     * Returns the configured FFprobe binary path.
     *
     * @return string Absolute path to the ffprobe binary.
     *
     * @since 0.13.0
     */
    public function getFfprobePath(): string
    {
        return $this->ffprobePath;
    }

    /**
     * Probes a media file for technical information.
     *
     * Uses FFprobe to extract stream details and format information. The actual
     * command execution is delegated to {@see runProbeCommand()}, which is
     * coroutine-aware (non-blocking under Swoole, S6) — this method's parsing and
     * return shape are unaffected by that and have not changed.
     *
     * @param string $inputPath Path to the media file to probe
     *
     * @return array{
     *     streams: array<int, array<string, mixed>>,
     *     format: array<string, mixed>
     * }|null Probe results or null if probing fails
     *
     * @example
     * ```php
     * $info = $runner->probe('/path/to/video.mkv');
     * $videoStream = $info['streams'][0] ?? null;
     * ```
     */
    public function probe(string $inputPath): ?array
    {
        // Memoize by path + mtime so at most one probe per file per worker
        // lifetime. The mtime guard handles the case where the file changes
        // on disk (e.g. after a re-encode).
        $mtime = is_file($inputPath) ? (string) @filemtime($inputPath) : '';
        $cacheKey = $inputPath . ':' . $mtime;
        if (isset($this->probeMemo[$cacheKey])) {
            return $this->probeMemo[$cacheKey];
        }

        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            escapeshellarg($this->ffprobePath),
            escapeshellarg($inputPath)
        );

        $output = $this->runProbeCommand($cmd);
        if ($output === null || $output === '') {
            $this->probeMemo[$cacheKey] = null;
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            $this->probeMemo[$cacheKey] = null;
            return null;
        }

        $rawStreams = $data['streams'] ?? [];
        $rawFormat = $data['format'] ?? [];
        if (!is_array($rawStreams) || !is_array($rawFormat)) {
            $this->probeMemo[$cacheKey] = null;
            return null;
        }

        $streams = [];
        foreach ($rawStreams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $normalized = [];
            foreach ($stream as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $streams[] = $normalized;
        }

        $format = [];
        foreach ($rawFormat as $key => $value) {
            if (is_string($key)) {
                $format[$key] = $value;
            }
        }

        $result = [
            'streams' => $streams,
            'format' => $format,
        ];
        $this->probeMemo[$cacheKey] = $result;
        return $result;
    }

    /**
     * Executes an ffprobe command, coroutine-friendly under the Swoole runtime.
     *
     * The phlix server runs on Workerman with a Swoole coroutine event loop and a
     * deliberately curated hook mask ({@see \Phlix\Server\Runtime\SwooleRuntime})
     * that intentionally does NOT hook `proc_open`/`exec`/`shell_exec` — so a plain
     * `shell_exec()` here would block the whole worker (every concurrent connection)
     * for the full duration of the ffprobe process. That stall is exactly what S6
     * removes: on the hot paths (`ensureHlsJob` on play-start, the scanner per file)
     * ffprobe can take tens to hundreds of milliseconds.
     *
     * When running inside a coroutine, {@see \Swoole\Coroutine\System::exec()} is
     * used instead. It drives the child process through Swoole's async scheduler and
     * yields to the event loop while ffprobe runs, so other connections keep being
     * served. It is a native coroutine primitive and therefore works regardless of
     * the runtime hook mask (it does not rely on the `proc_open` hook being enabled).
     *
     * Outside a coroutine — the CLI scanner/backfill, unit tests, or any non-Swoole
     * runtime — it falls back to a blocking `shell_exec()`, which is correct there
     * (no event loop to stall). Both paths run the command via `/bin/sh -c`, so the
     * `escapeshellarg()`-quoted arguments and the `2>/dev/null` redirect behave
     * identically; only stdout (the ffprobe JSON) is captured.
     *
     * @param string $cmd Fully built, shell-escaped ffprobe command line.
     *
     * @return string|null Captured stdout, or null when execution failed.
     */
    private function runProbeCommand(string $cmd): ?string
    {
        if (
            extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0
        ) {
            /** @var array{code?: int, signal?: int, output?: string}|false $result */
            $result = \Swoole\Coroutine\System::exec($cmd);
            if (is_array($result) && isset($result['output']) && is_string($result['output'])) {
                return $result['output'];
            }
            return null;
        }

        $output = shell_exec($cmd);

        return is_string($output) ? $output : null;
    }

    /**
     * Runs an FFmpeg command in a coroutine-aware manner.
     *
     * When inside a Swoole coroutine (getCid() > 0), uses
     * {@see \Swoole\Coroutine\System::exec()} to avoid blocking the worker
     * event loop. In CLI/testing contexts (getCid() <= 0), falls back to
     * blocking {@see exec()}.
     *
     * @param string       $cmd      Fully built, shell-escaped ffmpeg command line.
     * @param list<string> &$output  Captured stdout lines (passthrough for exec compat).
     * @param int          &$exitCode Process exit code (passthrough for exec compat).
     *
     * @return bool True when the process exited with code 0.
     */
    private function runCoroutineAwareCommand(string $cmd, array &$output, int &$exitCode): bool
    {
        if (
            extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0
        ) {
            /** @var array{code?: int, signal?: int, output?: string} $result */
            $result = \Swoole\Coroutine\System::exec($cmd);
            $exitCode = (int) ($result['code'] ?? 1);
            $output = (isset($result['output']) && $result['output'] !== '') ? explode("\n", $result['output']) : [];
            return $exitCode === 0;
        }

        exec($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Runs an FFmpeg command returning output as a string, in a coroutine-aware manner.
     *
     * Same as {@see runCoroutineAwareCommand()} but returns the full stdout string
     * instead of exit code, for commands where only the output matters.
     *
     * @param string $cmd Fully built, shell-escaped ffmpeg command line.
     *
     * @return string|null Captured stdout string, or null when execution failed.
     */
    private function runCoroutineAwareShellExec(string $cmd): ?string
    {
        if (
            extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0
        ) {
            /** @var array{code?: int, signal?: int, output?: string} $result */
            $result = \Swoole\Coroutine\System::exec($cmd);
            return $result['output'] ?? null;
        }

        $output = shell_exec($cmd);
        return is_string($output) ? $output : null;
    }

    /**
     * Checks if a media file requires HDR tone mapping.
     *
     * Probes the file's color metadata to determine if it contains
     * HDR content (HLG or HDR10) that needs tone mapping for SDR displays.
     *
     * @param string $inputPath Path to the media file to check
     *
     * @return bool True if the input is HDR (HLG or HDR10) and needs tone mapping
     *
     * @example
     * ```php
     * if ($runner->needsToneMapping('/path/to/hdr-video.mkv')) {
     *     // Apply tone mapping filter
     * }
     * ```
     *
     * @since 0.36.0
     */
    public function needsToneMapping(string $inputPath): bool
    {
        $probe = $this->probe($inputPath);
        if ($probe === null) {
            return false;
        }

        return $this->isHdrColorMeta($this->extractColorMetadata($probe));
    }

    /**
     * Decides whether a probe's color metadata identifies HDR content that
     * needs tone-mapping to SDR — WITHOUT re-probing.
     *
     * HDR content is identified by bt2020 color space/primaries paired with an
     * HDR transfer function:
     *  - HLG: arib-std-b67 transfer
     *  - HDR10: smpte2084 (PQ) transfer
     *
     * Extracted from {@see needsToneMapping()} so the identical decision can be
     * made from an already-known probe (SV-1.1(b): the tone-map filter is
     * resolved once at job-creation time from the probe already in hand, instead
     * of being re-derived — with a fresh probe — for every segment).
     *
     * @param array<string, mixed> $colorMeta Color metadata from {@see extractColorMetadata()}.
     *
     * @return bool True when the content is HDR (HLG or HDR10) and needs tone mapping.
     */
    private function isHdrColorMeta(array $colorMeta): bool
    {
        $isHdr = in_array($colorMeta['color_transfer'], ['smpte2084', 'arib-std-b67'], true);
        $isBt2020 = $colorMeta['color_primaries'] === 'bt2020'
            || $colorMeta['color_space'] === 'bt2020nc'
            || $colorMeta['color_space'] === 'bt2020_ncl';

        return $isHdr && $isBt2020;
    }

    /**
     * Generates the HDR tone mapping filter profile for a transcode operation.
     *
     * For HDR content (HLG or HDR10), generates the appropriate zscale/tonemap
     * filter chain to convert to SDR. Returns null if no tone mapping is needed
     * or if tone mapping mode is disabled.
     *
     * The filter chain is determined by the configured tone_mapping_mode:
     *  - 'zscale': Uses zscale for CPU-based HDR→SDR conversion
     *  - 'libplacebo': Uses libplacebo for high-quality tone mapping (if available)
     *
     * @param string $inputPath  Source file path
     * @param string $outputPath Destination file path
     * @param string $codec      Video codec being used for encoding
     *
     * @return string|null FFmpeg video filter chain for tone mapping, or null if not needed
     *
     * @example
     * ```php
     * $toneMapFilter = $runner->getToneMappingProfile($input, $output, 'libx264');
     * if ($toneMapFilter !== null) {
     *     $cmd .= ' -vf "' . $toneMapFilter . '"';
     * }
     * ```
     *
     * @since 0.36.0
     */
    public function getToneMappingProfile(string $inputPath, string $outputPath, string $codec): ?string
    {
        // SV-1.1(b): the resolution logic now lives in
        // resolveToneMapFilterFromProbe() so the exact same filter STRING can be
        // computed either from a path (this legacy, per-segment fallback — which
        // probes, memoised by path+mtime) OR from an already-known probe at
        // job-creation time ({@see \Phlix\Media\Transcoding\TranscodeManager}),
        // which threads the result through segment_params so no per-segment
        // re-derive happens. `$outputPath` is (and always was) unused.
        return $this->resolveToneMapFilterFromProbe($this->probe($inputPath), $codec);
    }

    /**
     * Resolves the HDR tone-map filter STRING from an ALREADY-KNOWN ffprobe
     * result — without probing.
     *
     * This is the single source of truth for the tone-map graph;
     * {@see getToneMappingProfile()} is simply the path-based (probing) wrapper
     * around it. The output is byte-identical to {@see getToneMappingProfile()}
     * for the same probe + codec — SV-1.1(b) relies on that equality so that
     * threading the string through `segment_params` yields the exact same ffmpeg
     * `-vf` graph as the legacy per-segment re-derive.
     *
     * @param array<string, mixed>|null $probe Raw ffprobe result (as returned by
     *                                          {@see probe()}), or null.
     * @param string                     $codec Video codec being used for encoding.
     *
     * @return string|null FFmpeg video filter chain for tone mapping, or null if
     *                     not needed / not HDR / probe unavailable.
     *
     * @since SV-1.1(b)
     */
    public function resolveToneMapFilterFromProbe(?array $probe, string $codec): ?string
    {
        return $this->resolveToneMapFilterFromColorMeta(
            $probe === null ? null : $this->extractColorMetadata($probe),
            $codec
        );
    }

    /**
     * Resolves the HDR tone-map filter STRING from ALREADY-KNOWN color metadata —
     * without probing.
     *
     * This is the color-metadata core that {@see resolveToneMapFilterFromProbe()}
     * delegates to (that method just runs {@see extractColorMetadata()} first).
     * SV-1.1(a) calls it directly with the metadata sourced from the persisted
     * `media_streams` color columns
     * ({@see \Phlix\Media\Library\ItemRepository::getVideoStreamColorMetadata()}),
     * so a scanned item's tone-map filter is resolved with ZERO probe involvement.
     * Because the filter graph is decided by {@see isHdrColorMeta()} + config +
     * codec — NOT by the specific color values (both {@see buildZscaleToneMapFilter()}
     * and {@see buildLibplaceboToneMapFilter()} ignore them) — the string this
     * returns for column-sourced metadata is byte-identical to the probe-derived
     * string for the same file.
     *
     * @param array<string, mixed>|null $colorMeta Color metadata as returned by
     *                                             {@see extractColorMetadata()} /
     *                                             {@see \Phlix\Media\Library\ItemRepository::getVideoStreamColorMetadata()},
     *                                             or null.
     * @param string                     $codec    Video codec being used for encoding.
     *
     * @return string|null FFmpeg video filter chain for tone mapping, or null if
     *                     not needed / not HDR / metadata unavailable.
     *
     * @since SV-1.1(a)
     */
    public function resolveToneMapFilterFromColorMeta(?array $colorMeta, string $codec): ?string
    {
        if ($colorMeta === null) {
            return null;
        }
        if (!$this->isHdrColorMeta($colorMeta)) {
            return null;
        }

        // Determine tone mapping mode from config (default: zscale)
        $toneMapMode = $this->config['tone_mapping_mode'] ?? 'zscale';

        // If prefer_hdr_output is true and codec supports HDR, return null
        // (output will be HDR10 instead of SDR tone mapping)
        if (($this->config['prefer_hdr_output'] ?? false) === true) {
            // Check if codec can handle HDR (hevc Main 10 or similar)
            if (in_array($codec, ['libx265', 'hevc_nvenc', 'hevc_vaapi', 'hevc_qsv'], true)) {
                return null;
            }
        }

        if ($toneMapMode === 'none') {
            return null;
        }

        // Generate the appropriate tone mapping filter chain
        return match ($toneMapMode) {
            'zscale' => $this->buildZscaleToneMapFilter($colorMeta),
            'libplacebo' => $this->buildLibplaceboToneMapFilter($colorMeta),
            default => null,
        };
    }

    /**
     * Detects whether FFmpeg was built with libplacebo filter support.
     *
     * Checks by running `ffmpeg -filters 2>/dev/null` and looking for the
     * libplacebo filter in the output. Result is cached after first check.
     *
     * @return bool True if libplacebo filter is available
     *
     * @since 0.36.0
     */
    private function detectLibplacebo(): bool
    {
        if ($this->hasLibplacebo !== null) {
            return $this->hasLibplacebo;
        }

        $output = shell_exec(escapeshellarg($this->ffmpegPath) . ' -filters 2>/dev/null');

        $this->hasLibplacebo = is_string($output)
            && preg_match('/\blibplacebo\b/', $output) === 1;

        $this->logger->debug('Libplacebo detection', [
            'available' => $this->hasLibplacebo,
            'ffmpeg' => $this->ffmpegPath,
        ]);

        return $this->hasLibplacebo;
    }

    /**
     * Builds a zscale-based tone mapping filter chain.
     *
     * @param array<string, mixed> $colorMeta Color metadata from extractColorMetadata()
     *
     * @return string FFmpeg filter chain for zscale tone mapping
     *
     * @since 0.36.0
     */
    private function buildZscaleToneMapFilter(array $colorMeta): string
    {
        // Full HDR→SDR tone-map graph using zscale + hable tonemap:
        // 1. zscale=t=linear:npl=100   — convert to linear with npl (normalized peak light)
        // 2. format=gbrpf32le         — 32-bit float planar RGB for precision
        // 3. zscale=p=bt709           — set output primaries to BT.709
        // 4. tonemap=hable:desat=0    — hable tonemap curve, no desaturation
        // 5. zscale=t=bt709:m=bt709:r=tv — convert transfer & matrix to BT.709 TV range
        // 6. format=yuv420p          — 8-bit 4:2:0 for browser compatibility
        return 'zscale=t=linear:npl=100,format=gbrpf32le,'
            . 'zscale=p=bt709,tonemap=hable:desat=0,'
            . 'zscale=t=bt709:m=bt709:r=tv,format=yuv420p';
    }

    /**
     * Builds a libplacebo-based tone mapping filter chain.
     *
     * When FFmpeg was built with libplacebo support, emits a real
     * `libplacebo=tonemapping=...` filter graph for high-quality GPU-assisted
     * HDR→SDR tone mapping. When libplacebo is not available, falls back to
     * the zscale+tonemap software filter chain from SV-1.4.
     *
     * SV-1.5 fix: the previous graph used `peak=`/`input_color_space=`/
     * `input_primaries=`/`input_trc=`/`output_color_space=`/
     * `output_primaries=`/`output_trc=` — NONE of these option names exist on
     * the real `libplacebo` filter (confirmed on this box by running
     * `ffmpeg -h filter=libplacebo` and by actually executing the old filter
     * against a synthetic HDR-tagged input, which failed immediately with
     * `Error applying option 'peak' to filter 'libplacebo': Option not
     * found`). The real filter:
     *  - has NO `input_*`/`output_*` split — it reads the input color tags
     *    (space/primaries/transfer) directly off the decoded frame, and the
     *    `colorspace=`/`color_primaries=`/`color_trc=` options instead select
     *    the DESIRED OUTPUT tagging (mirroring what {@see buildZscaleToneMapFilter()}
     *    achieves with `zscale=t=bt709:m=bt709`), plus `range=` for the
     *    output color range (`tv` = legal/limited range, matching the zscale
     *    graph's trailing `r=tv`);
     *  - does NOT support manually setting a `peak` luminance value at all —
     *    peak detection is automatic via the (default-on) `peak_detect`
     *    option, so no peak option is emitted here.
     * `tonemapping=hable` is kept (the same filmic tone-mapping curve
     * {@see buildZscaleToneMapFilter()} uses via `tonemap=hable`).
     *
     * Verified end-to-end (not just option parsing) by constructing a
     * synthetic BT.2020/PQ-tagged test source and running this exact filter
     * string through real ffmpeg with a Vulkan device available
     * (`ffmpeg -f lavfi -i "testsrc,setparams=color_primaries=bt2020:
     * color_trc=smpte2084:colorspace=bt2020nc" -vf "<this filter>" -f null -`):
     * it exits 0 and produces `yuv420p(tv, bt709, progressive)` output — a
     * real, successful HDR→SDR tone-map, not merely a parseable command line.
     *
     * @param array<string, mixed> $colorMeta Color metadata from extractColorMetadata()
     *                                        (unused here — the real filter reads
     *                                        the input's own color tags from the
     *                                        frame rather than a manually-supplied
     *                                        input_* option; kept for signature
     *                                        parity with the zscale builder and
     *                                        potential future use, e.g. a
     *                                        source-primaries-aware output choice).
     *
     * @return string FFmpeg filter chain for libplacebo tone mapping (or zscale fallback)
     *
     * @since 0.36.0
     */
    private function buildLibplaceboToneMapFilter(array $colorMeta): string
    {
        // Fall back to zscale+tonemap if FFmpeg lacks libplacebo support.
        // The zscale path is the same software graph used by SV-1.4.
        if (!$this->detectLibplacebo()) {
            return $this->buildZscaleToneMapFilter($colorMeta);
        }

        // Real, verified libplacebo tone-mapping graph: `tonemapping=hable`
        // selects the tone-map curve; `colorspace=`/`color_primaries=`/
        // `color_trc=bt709` + `range=tv` request BT.709 SDR legal-range
        // output (peak luminance is auto-detected — no `peak` option exists).
        return 'libplacebo=tonemapping=hable:colorspace=bt709:'
            . 'color_primaries=bt709:color_trc=bt709:range=tv,format=yuv420p';
    }

    /**
     * Lazily builds the {@see SubtitleBurner} used for per-segment burn-in.
     *
     * @since SV-1.6
     */
    private function subtitleBurner(): SubtitleBurner
    {
        return $this->subtitleBurner ??= new SubtitleBurner($this);
    }

    /**
     * Resolves a `subtitle_burn_in` segment param into a filtergraph-escaped
     * `subtitles=`/`ass=` filter fragment for the LIVE per-segment pipeline
     * (SV-1.6) — wires {@see SubtitleBurner} into
     * {@see buildSegmentCommand()} / {@see buildHwaccelSegmentCommand()},
     * which previously never referenced subtitles at all.
     *
     * Gated on the caller supplying `$params['subtitle_burn_in']` — an array
     * shaped `['path' => string, 'format' => ?string, 'style' =>
     * ?array<string, mixed>]` — populated by
     * {@see \Phlix\Media\Transcoding\TranscodeManager} from a job's
     * (optional) `subtitle_burn_in_index` option, resolved against the
     * already-extracted `sub-{index}.vtt` sidecar. When the sidecar has not
     * been extracted yet (a background, best-effort step) this degrades
     * silently to "no burn-in for this segment" rather than failing the
     * encode, mirroring the existing subtitle-extraction failure tolerance.
     *
     * @param array<string, mixed> $params Segment params (see {@see buildSegmentCommand()}).
     *
     * @return string|null The burn-in filter fragment (e.g. `subtitles=/path/to/sub.vtt`),
     *                      or null when burn-in is not configured or the subtitle file
     *                      is not (yet) available on disk.
     *
     * @since SV-1.6
     */
    private function resolveSubtitleBurnInFilter(array $params): ?string
    {
        $burnIn = $params['subtitle_burn_in'] ?? null;
        if (!is_array($burnIn)) {
            return null;
        }

        $path = $burnIn['path'] ?? null;
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return null;
        }

        $formatValue = $burnIn['format'] ?? null;
        $format = is_string($formatValue) ? SubtitleFormat::tryFrom($formatValue) : null;
        $format ??= SubtitleFormat::VTT;

        $style = $burnIn['style'] ?? [];
        if (!is_array($style)) {
            $style = [];
        }
        /** @var array{
         *     font_name?: string,
         *     font_size?: int,
         *     primary_color?: string,
         *     outline_color?: string,
         *     outline_thickness?: int,
         *     position?: string,
         *     margin?: int
         * } $style
         */
        $track = new SubtitleTrack(
            index: '0',
            language: 'und',
            label: '',
            format: $format,
            path: $path,
        );

        return $this->subtitleBurner()->getBurnInFilter($track, $style);
    }

    /**
     * Builds an FFmpeg command that muxes the input to CMAF (fMP4) output that
     * serves BOTH DASH and HLS from a single encode.
     *
     * Uses FFmpeg's DASH muxer with `-hls_playlist 1`, so one pass writes:
     *   - `manifest.mpd`              (DASH manifest)
     *   - `master.m3u8` + `media_N.m3u8` (HLS master + media playlists, HLS v7 fMP4)
     *   - `init-N.m4s` + `chunk-N-NNNNN.m4s` (shared CMAF init + media segments)
     * All playlists/manifest reference segments by relative filename, so a generic
     * per-job file server delivers them with no URI rewriting.
     *
     * Per-stream copy vs encode follows the same `video_codec` / `audio_codec`
     * convention as {@see self::buildHlsCommand()} (`'copy'` to remux, an encoder
     * name to transcode).
     *
     * @param string               $inputPath Source media file path.
     * @param string               $outDir    Directory the manifest/playlists/segments are written to.
     * @param array<string, mixed> $params    Encoding / segmenting parameters (see buildHlsCommand()).
     *
     * @return string Complete FFmpeg CMAF command.
     *
     * @since 0.24.0
     */
    public function buildCmafCommand(string $inputPath, string $outDir, array $params): string
    {
        $segSeconds = self::paramInt($params, 'segment_seconds') ?? 6;
        if ($segSeconds < 1) {
            $segSeconds = 6;
        }

        $cmd = sprintf('%s -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Explicit mapping: ONLY the first video + the first audio track. Anime/TV
        // MKVs often carry embedded subtitle streams AND font ATTACHMENT / data
        // (`bin_data`) streams; if the DASH/CMAF muxer pulls any of those into the
        // A/V encode it produces extra representations or fails the mux outright.
        // `-dn -sn` drop data + subtitle streams and `-map -0:t?` excludes any
        // attachment so only the playable video+audio reach the CMAF output.
        $cmd .= ' -map 0:v:0 -map 0:a:0? -map -0:t? -dn -sn';

        // Video: copy a compatible stream as-is, otherwise encode.
        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === 'copy') {
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? $defaultCrf);
            }
            $width = self::paramInt($params, 'width');
            $height = self::paramInt($params, 'height');
            if ($width !== null && $height !== null) {
                $cmd .= ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease"';
            }
            // Closed, fixed-size GOP so segments are keyframe-aligned across both protocols.
            $cmd .= ' -g 48 -keyint_min 48 -sc_threshold 0';
            // Force an 8-bit 4:2:0 H.264 profile so a 10-bit (High 10) source is
            // re-encoded into a stream browsers can actually decode.
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: copy AAC as-is, otherwise encode to AAC.
        $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === 'copy') {
            $cmd .= ' -c:a copy';
        } else {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
            $cmd .= ' -ar ' . (self::paramInt($params, 'audio_sample_rate') ?? 48000);
            $audioChannels = self::paramInt($params, 'audio_channels');
            if ($audioChannels !== null) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        }

        // DASH muxer with HLS playlist generation — one encode, both protocols.
        $cmd .= ' -f dash';
        $cmd .= ' -seg_duration ' . $segSeconds;
        $cmd .= ' -use_template 1 -use_timeline 1';
        $cmd .= ' -init_seg_name ' . escapeshellarg('init-$RepresentationID$.m4s');
        $cmd .= ' -media_seg_name ' . escapeshellarg('chunk-$RepresentationID$-$Number%05d$.m4s');
        $cmd .= ' -hls_playlist 1 -hls_master_name master.m3u8';
        $cmd .= ' ' . escapeshellarg($outDir . '/manifest.mpd');

        return $cmd;
    }

    /**
     * Starts a CMAF transcode (DASH + HLS) as a detached background process.
     *
     * @param string               $inputPath Source media file path.
     * @param string               $outDir    Output directory.
     * @param array<string, mixed> $params    Parameters for {@see self::buildCmafCommand()}.
     *
     * @return int OS process id of the launched job (0 if launch failed).
     *
     * @since 0.24.0
     */
    public function startCmafTranscode(string $inputPath, string $outDir, array $params): int
    {
        return $this->startDetached($this->buildCmafCommand($inputPath, $outDir, $params), $outDir);
    }

    /**
     * Starts a CMAF transcode and, in the same detached job, extracts the given
     * text subtitle tracks to cleaned `sub-{index}.vtt` sidecars.
     *
     * The subtitle extraction commands are appended to the CMAF command with
     * `&&` so they run AFTER the A/V encode (each is internally `|| true` so a
     * failed track never aborts the job — the video is the hard requirement).
     * The whole chain is launched once via {@see self::startDetached()}, so the
     * Workerman worker never blocks on FFmpeg.
     *
     * @param string                       $inputPath     Source media file path.
     * @param string                       $outDir        Output directory.
     * @param array<string, mixed>         $params        Parameters for {@see self::buildCmafCommand()}.
     * @param array<int, string>           $extractCmds   Extra `&&`-chainable extraction commands
     *                                                    (built by {@see \Phlix\Media\Transcoding\Subtitles\SubtitleExtractor::buildExtractCommand()}).
     *
     * @return int OS process id of the launched job (0 if launch failed).
     *
     * @since 0.25.0
     */
    public function startCmafTranscodeWithSubtitles(
        string $inputPath,
        string $outDir,
        array $params,
        array $extractCmds = []
    ): int {
        $command = $this->buildCmafCommand($inputPath, $outDir, $params);

        // Subtitle extraction is a TRAILING step that runs ONLY when the CMAF
        // encode succeeded (see startDetached() — these go inside the `then`
        // branch, AFTER `.complete` is written). Each extract group is itself
        // `|| true`, so a failed track can never alter the already-decided job
        // status, and the `|| true` can never bridge back to a failed encode.
        $trailing = [];
        foreach ($extractCmds as $extract) {
            if (is_string($extract) && $extract !== '') {
                $trailing[] = $extract;
            }
        }

        return $this->startDetached($command, $outDir, $trailing);
    }

    /**
     * Launches an FFmpeg command fully detached from the PHP process.
     *
     * Phlix runs on a long-lived Workerman event loop, so the synchronous
     * {@see self::probe()} (proc_open + stream_get_contents + proc_close)
     * MUST NOT be used in the request path — it would block the worker for the
     * entire encode. This backgrounds the command with `nohup ... &`, captures
     * output to `$outDir/ffmpeg.log`, and writes a `.complete` / `.failed`
     * marker on exit so readiness can be polled from disk (surviving worker
     * reloads, which an in-memory process handle would not).
     *
     * The marker decision uses an unambiguous `if <command>; then ...; else ...; fi`
     * form so it reflects ONLY the primary command's exit status, regardless of
     * `set -e` state. `$trailingCmds` (e.g. subtitle extraction) run inside the
     * `then` branch AFTER `.complete` is written — so they execute only on a
     * successful primary command and their own exit status can never flip the
     * job to `.complete`/`.failed`. (Contrast the old `cmd && extract || true &&
     * touch .complete` chain, where a left-associative `|| true` after the
     * extract group could mask a FAILED primary command and still touch
     * `.complete`.)
     *
     * @param string             $command      The full primary FFmpeg command to run.
     * @param string             $outDir       Directory for the log and completion markers.
     * @param array<int, string> $trailingCmds Commands to run after a SUCCESSFUL primary
     *                                          command (each should be internally `|| true`
     *                                          so it cannot abort the post-success sequence).
     *
     * @return int OS process id of the background job (0 if launch failed).
     *
     * @since 0.23.0
     * @since SV-4.2 Applies transcode_timeout wrapper via buildDetachedCommand.
     */
    public function startDetached(string $command, string $outDir, array $trailingCmds = []): int
    {
        $timeoutSecs = $this->getTranscodeTimeout();
        $full = $this->buildDetachedCommand($command, $outDir, $trailingCmds, $timeoutSecs);

        $this->logger->debug('Starting detached HLS transcode', [
            'command' => $command,
            'timeout_secs' => $timeoutSecs,
        ]);

        $pid = shell_exec($full);
        if (!is_string($pid)) {
            $this->logger->error('Failed to launch detached transcode');
            return 0;
        }

        return (int) trim($pid);
    }

    /**
     * Get the configured transcode timeout in seconds.
     *
     * Loads from ffmpeg.php config on first call, then caches.
     *
     * @return int Timeout in seconds (0 = no timeout)
     *
     * @since SV-4.2
     */
    private function getTranscodeTimeout(): int
    {
        static $timeout = null;
        if ($timeout === null) {
            $configPath = defined('PHLIX_CONFIG_PATH') ? PHLIX_CONFIG_PATH : __DIR__ . '/../../../config';
            $configFile = $configPath . '/ffmpeg.php';
            if (file_exists($configFile)) {
                /** @var array<string, mixed> $config */
                $config = include $configFile;
                $timeoutSecs = $config['transcode_timeout'] ?? null;
                $timeout = match (true) {
                    is_int($timeoutSecs) => $timeoutSecs,
                    is_string($timeoutSecs) && is_numeric($timeoutSecs) => (int) $timeoutSecs,
                    default => 0,
                };
            } else {
                $timeout = 0;
            }
        }
        return $timeout;
    }

    /**
     * Builds the full backgrounded launch string used by {@see self::startDetached()}.
     *
     * Factored out so the unambiguous marker structure can be asserted in tests
     * without actually spawning a process. The marker decision is an
     * `if <command>; then touch .complete; <trailing...>; else touch .failed; fi`
     * form: `.complete` is written ONLY when `$command` exits 0, and the
     * `$trailingCmds` (subtitle extraction) run inside `then` AFTER `.complete`,
     * so neither a `|| true` extract wrapper nor a failing extract can ever bridge
     * a FAILED primary command to `.complete`, nor flip a success to `.failed`.
     *
     * The entire command chain is wrapped in `timeout <seconds>` so the
     * transcode is killed if it exceeds the configured timeout
     * (SV-4.2: detached-ffmpeg cancellation + apply transcode_timeout).
     *
     * @param string             $command      The primary command.
     * @param string             $outDir       Marker / log directory.
     * @param array<int, string> $trailingCmds Post-success commands (each internally `|| true`).
     * @param int                $timeoutSecs Timeout in seconds (0 = no timeout).
     *
     * @return string The full `nohup sh -c ... & echo $!` launch string.
     *
     * @since 0.25.0
     * @since SV-4.2 Applies transcode_timeout wrapper.
     */
    public function buildDetachedCommand(string $command, string $outDir, array $trailingCmds = [], int $timeoutSecs = 0): string
    {
        $then = 'touch ' . escapeshellarg($outDir . '/.complete');
        foreach ($trailingCmds as $trailing) {
            if (is_string($trailing) && $trailing !== '') {
                $then .= '; ' . $trailing;
            }
        }
        $inner = 'if ' . $command . '; then ' . $then
            . '; else touch ' . escapeshellarg($outDir . '/.failed') . '; fi';

        // SV-4.2: wrap in timeout to enforce transcode_timeout
        if ($timeoutSecs > 0) {
            $inner = 'timeout ' . (int) $timeoutSecs . ' sh -c ' . escapeshellarg($inner);
        } else {
            $inner = 'sh -c ' . escapeshellarg($inner);
        }

        return sprintf(
            'nohup %s > %s 2>&1 & echo $!',
            $inner,
            escapeshellarg($outDir . '/ffmpeg.log')
        );
    }

    /**
     * Checks whether a process id is still running.
     *
     * Uses `posix_kill($pid, 0)` when ext-posix is present, otherwise probes
     * `/proc/{pid}`. Returns false for non-positive pids.
     *
     * @param int $pid Process id to check.
     *
     * @return bool True if the process appears to be alive.
     *
     * @since 0.23.0
     */
    public function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }

    /**
     * Generates a thumbnail image from a video.
     *
     * @param string $inputPath Source video path
     * @param string $outputPath Destination image path
     * @param int|array<int> $timeSeconds Timestamp(s) to capture frame (default: 10)
     *
     * @return bool True if thumbnail generation succeeded
     *
     * @example
     * ```php
     * $success = $runner->generateThumbnail('/video.mkv', '/thumb.jpg', 30);
     * ```
     *
     * @since 0.11.0 Supports array of timestamps for batch extraction
     */
    public function generateThumbnail(string $inputPath, string $outputPath, int|array $timeSeconds = 10): bool
    {
        if (is_array($timeSeconds)) {
            return $this->generateThumbnailBatch($inputPath, $timeSeconds, $outputPath);
        }

        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -ss %d -vframes 1 -q:v 2 -f image2 %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $timeSeconds,
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        $this->runCoroutineAwareCommand($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Builds the FFmpeg command line for {@see generateThumbnailBatch()}.
     *
     * Extracted as its own public builder (matching {@see buildCmafCommand()} /
     * {@see buildSegmentCommand()} etc.) so the command SHAPE is directly
     * testable without invoking a real FFmpeg binary.
     *
     * Each timestamp gets its **own** `-ss <timestamp> -i <inputPath>` pair so
     * every seek stays **input-side** (fast, demuxer-level keyframe seeking)
     * even though all N frames are pulled by a single FFmpeg process — FFmpeg
     * supports repeating `-i` for the same file with different per-input
     * options. All output groups are declared after every input, each pinned
     * to its own input occurrence via an explicit `-map <index>:v:0`; without
     * that, FFmpeg's default per-output stream auto-selection is free to pick
     * *any* already-open input, and since every input here is literally the
     * same file re-opened at a different offset, an unmapped output could
     * silently grab the wrong (or a duplicate) timestamp's frame.
     *
     * @param string $inputPath Source video path
     * @param array<int> $timestamps Array of timestamps (seconds) to capture
     * @param string $outputDir Directory for output images (named frame_00000.jpg, frame_00001.jpg, etc.)
     *
     * @return string The fully built, shell-escaped FFmpeg command line.
     *
     * @since SV-0.9
     */
    public function buildThumbnailBatchCommand(string $inputPath, array $timestamps, string $outputDir): string
    {
        $timestamps = array_values($timestamps);

        $inputArgs = '';
        $outputArgs = '';
        foreach ($timestamps as $index => $timestamp) {
            $framePath = $outputDir . '/frame_' . str_pad((string) $index, 5, '0', STR_PAD_LEFT) . '.jpg';

            // Input-side seek: `-ss` before its own `-i` re-open of the same
            // file performs a fast demuxer-level seek instead of decoding
            // from the start of the stream.
            $inputArgs .= sprintf(' -ss %d -i %s', (int) $timestamp, escapeshellarg($inputPath));

            // Explicit map ties this output to the input occurrence that
            // carries its matching seek offset (see docblock above).
            $outputArgs .= sprintf(' -map %d:v:0 -vframes 1 %s', $index, escapeshellarg($framePath));
        }

        return sprintf(
            '%s -y -hide_banner -loglevel error%s%s',
            escapeshellarg($this->ffmpegPath),
            $inputArgs,
            $outputArgs
        );
    }

    /**
     * Generates multiple thumbnails at different timestamps in a single command.
     *
     * Uses FFmpeg's capability to open the same input multiple times (once per
     * requested timestamp, each with its own fast input-side `-ss`) and emit
     * one image per input via an explicit `-map`, so N frames are captured by
     * a single FFmpeg process invocation. See {@see buildThumbnailBatchCommand()}
     * for the exact command shape.
     *
     * @param string $inputPath Source video path
     * @param array<int> $timestamps Array of timestamps to capture
     * @param string $outputDir Directory for output images (images named frame_00000.jpg, frame_00001.jpg, etc.)
     *
     * @return bool Best-effort / partial-success: true if at least one requested
     *              timestamp produced a non-empty frame file — so a batch mixing
     *              in-range and out-of-range timestamps still returns true, with
     *              only the reachable frames written. False only when the ffmpeg
     *              process failed outright (exit != 0) or not a single requested
     *              frame was written.
     *
     * @since 0.11.0
     * @since SV-0.9 Fixed the multi-timestamp command shape (all seek/output
     *        groups were previously bunched before one shared `-i`, which relied
     *        on ffmpeg's lenient tolerance of an input declared after the outputs
     *        plus a slow output-side seek — an arrangement not guaranteed correct
     *        by ffmpeg's documented option ordering; it did still render frames on
     *        ffmpeg 6.1.1, just via that non-guaranteed, slower path). Also
     *        verified at least one output file was actually written: FFmpeg exits
     *        0 even when every requested `-ss` lands past end-of-file (it just
     *        logs "Output file is empty" and skips that output), so exit code
     *        alone is not a reliable success signal for this command shape.
     */
    public function generateThumbnailBatch(string $inputPath, array $timestamps, string $outputDir): bool
    {
        if (empty($timestamps)) {
            return true;
        }

        $timestamps = array_values($timestamps);
        $cmd = $this->buildThumbnailBatchCommand($inputPath, $timestamps, $outputDir);

        $output = [];
        $exitCode = 0;
        $this->runCoroutineAwareCommand($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return false;
        }

        foreach (array_keys($timestamps) as $index) {
            $framePath = $outputDir . '/frame_' . str_pad((string) $index, 5, '0', STR_PAD_LEFT) . '.jpg';
            if (is_file($framePath) && filesize($framePath) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets the configured FFmpeg binary path.
     *
     * @return string Path to the FFmpeg binary
     *
     * @since 0.11.0
     */
    public function getFfmpegPath(): string
    {
        return $this->ffmpegPath;
    }

    /**
     * Extracts a subtitle stream to a file.
     *
     * @param string $inputPath Source video path
     * @param string $outputPath Destination subtitle file path
     * @param int $streamIndex Subtitle stream index (default: 0)
     *
     * @return bool True if extraction succeeded
     *
     * @example
     * ```php
     * $success = $runner->extractSubtitle('/video.mkv', '/subs.srt', 0);
     * ```
     */
    public function extractSubtitle(string $inputPath, string $outputPath, int $streamIndex = 0): bool
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -map 0:s:%d -c:s copy %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $streamIndex,
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        $this->runCoroutineAwareCommand($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Extracts one embedded TEXT subtitle stream to a WebVTT file, transcoding
     * the subtitle codec (ASS/SRT/mov_text → WebVTT) rather than copying it.
     *
     * Used by the on-demand subtitle endpoint so a direct-play client can fetch
     * a selectable caption track without a full transcode. Bitmap subtitles
     * (PGS/VobSub) have no text and will fail (return false).
     *
     * @param string $inputPath   Source media path
     * @param string $outputPath  Destination .vtt path
     * @param int    $streamIndex Per-type subtitle ordinal (the `0:s:{index}` selector)
     *
     * @return bool True when ffmpeg exits cleanly
     */
    public function extractSubtitleVtt(string $inputPath, string $outputPath, int $streamIndex = 0): bool
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -map 0:s:%d -c:s webvtt -f webvtt %s',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($inputPath),
            $streamIndex,
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        $this->runCoroutineAwareCommand($cmd, $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Checks if FFmpeg is available and executable.
     *
     * @return bool True if FFmpeg binary exists and is executable
     *
     * @example
     * ```php
     * if (!$runner->isAvailable()) {
     *     throw new RuntimeException('FFmpeg not installed');
     * }
     * ```
     */
    public function isAvailable(): bool
    {
        return file_exists($this->ffmpegPath) && is_executable($this->ffmpegPath);
    }

    /**
     * Gets the FFmpeg version string.
     *
     * @return string|null Version string or null if unavailable
     *
     * @example
     * ```php
     * $version = $runner->getVersion(); // "6.1"
     * ```
     */
    public function getVersion(): ?string
    {
        // Try shell fallback if isAvailable() is false (is_executable may fail in web context)
        if (!$this->isAvailable()) {
            $output = shell_exec('ffmpeg -version 2>&1');
            if (is_string($output) && preg_match('/ffmpeg version (\S+)/', $output, $m) === 1) {
                return $m[1];
            }
            return null;
        }

        $output = shell_exec(escapeshellarg($this->ffmpegPath) . ' -version 2>/dev/null');
        if (!is_string($output)) {
            return null;
        }
        if (preg_match('/ffmpeg version (\S+)/', $output, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Probes hardware acceleration capabilities and populates the registry.
     *
     * This method should be called at startup to detect available hardware
     * acceleration. It initializes the HwaccelRegistry singleton with the
     * detected capabilities.
     *
     * @param HwaccelRegistry|null $registry Optional custom registry (uses singleton if null)
     *
     * @return array<string, HwaccelCapability> Map of vendor name to capability
     *
     * @since 0.11.0
     */
    public function probeHardwareAcceleration(?HwaccelRegistry $registry = null): array
    {
        if (self::$hwaccelProbed) {
            return HwaccelRegistry::getInstance()->getAll();
        }

        $this->logger->error(sprintf(
            '[HWACCEL_DEBUG][%s][PID:%d][TRACE:%s] probeHardwareAcceleration EXECUTING (first time in this process)',
            $timestamp,
            $pid,
            $traceId
        ));

        $this->hwaccelRegistry = $registry ?? HwaccelRegistry::getInstance();
        $capabilities = $this->hwaccelRegistry->getAll();

        // Set preferred accelerator from merged config if specified.
        // This affects getBestAcceleratorForCodec() selection order.
        $preferredAccelerator = $this->config['preferred_accelerator'] ?? null;
        if (is_string($preferredAccelerator) && $preferredAccelerator !== '') {
            $this->setPreferredAccelerator($preferredAccelerator);
        }

        $this->logger->info('Hardware acceleration probed', [
            'vendors' => array_keys($capabilities),
            'preferred' => $preferredAccelerator,
            'trace_id' => $traceId,
            'pid' => $pid,
            'timestamp' => $timestamp,
        ]);

        self::$hwaccelProbed = true;

        $this->logger->error(sprintf(
            '[HWACCEL_DEBUG][%s][PID:%d][TRACE:%s] probeHardwareAcceleration COMPLETE | vendors=%s | preferred=%s',
            $timestamp,
            $pid,
            $traceId,
            implode(',', array_keys($capabilities)),
            $preferredAccelerator ?? 'none'
        ));

        return $capabilities;
    }

    /**
     * Gets the hardware acceleration registry.
     *
     * @return HwaccelRegistry|null
     *
     * @since 0.11.0
     */
    public function getHwaccelRegistry(): ?HwaccelRegistry
    {
        return $this->hwaccelRegistry;
    }

    /**
     * Extracts color metadata from a probe result.
     *
     * Parses the ffprobe JSON output to extract HDR-related color
     * information from the video stream.
     *
     * @param array<string, mixed> $probeResult Result from probe()
     *
     * @return array{
     *     color_space: string,
     *     color_transfer: string,
     *     color_primaries: string,
     *     max_luminance: float,
     *     avg_luminance: float
     * } Color metadata or defaults if not present
     *
     * @since 0.11.0
     */
    public function extractColorMetadata(array $probeResult): array
    {
        // Find the video stream
        $videoStream = null;
        $streams = $probeResult['streams'] ?? [];
        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (!is_array($stream)) {
                    continue;
                }
                if (($stream['codec_type'] ?? '') === 'video') {
                    $videoStream = $stream;
                    break;
                }
            }
        }

        if ($videoStream === null) {
            return [
                'color_space' => 'bt2020nc',
                'color_transfer' => 'bt709',
                'color_primaries' => 'bt2020',
                'max_luminance' => 1000.0,
                'avg_luminance' => 200.0,
            ];
        }

        $colorSpace = is_string($videoStream['color_space'] ?? null)
            ? $videoStream['color_space']
            : 'bt2020nc';
        $colorTransfer = is_string($videoStream['color_transfer'] ?? null)
            ? $videoStream['color_transfer']
            : 'bt709';
        $colorPrimaries = is_string($videoStream['color_primaries'] ?? null)
            ? $videoStream['color_primaries']
            : 'bt2020';

        // Default luminance values
        $maxLuminance = 1000.0;
        $avgLuminance = 200.0;

        // Try to extract luminance from side data or tags
        $tags = $videoStream['tags'] ?? null;
        if (is_array($tags)) {
            $masteringLuminance = $tags['mastering_display_luminance'] ?? null;
            if (is_string($masteringLuminance)) {
                if (preg_match('/max:(\d+(\.\d+)?)/', $masteringLuminance, $matches)) {
                    $maxLuminance = (float) $matches[1];
                }
            }

            $ambientLuminance = $tags['ambient_luminance'] ?? null;
            if (is_string($ambientLuminance)) {
                if (preg_match('/avg:(\d+(\.\d+)?)/', $ambientLuminance, $matches)) {
                    $avgLuminance = (float) $matches[1];
                }
            }
        }

        // Also check for max_luminance directly (some FFmpeg versions)
        if (isset($videoStream['max_luminance']) && is_numeric($videoStream['max_luminance'])) {
            $maxLuminance = (float) $videoStream['max_luminance'];
        }

        if (isset($videoStream['avg_luminance']) && is_numeric($videoStream['avg_luminance'])) {
            $avgLuminance = (float) $videoStream['avg_luminance'];
        }

        return [
            'color_space' => $colorSpace,
            'color_transfer' => $colorTransfer,
            'color_primaries' => $colorPrimaries,
            'max_luminance' => $maxLuminance,
            'avg_luminance' => $avgLuminance,
        ];
    }

    /**
     * Gets the configured default transcode output directory.
     *
     * @return string Transcode output directory
     *
     * @since 0.11.0
     */
    public function getTranscodeDir(): string
    {
        return $this->transcodeDir;
    }

    /**
     * Browser-safe pixel-format / profile flags for a software H.264/H.265 encode.
     *
     * Browser Media Source Extensions (and hls.js) can only decode 8-bit 4:2:0
     * H.264 (Baseline/Main/High). A 10-bit (High 10) or 4:2:2/4:4:4 source —
     * common for HEVC "Main 10" remuxes — would otherwise produce an output the
     * player loads but cannot decode, surfacing as "couldn't prepare a playable
     * version". Forcing `-pix_fmt yuv420p` (8-bit 4:2:0) plus an H.264
     * `-profile:v`/`-level` guarantees a decodable stream. Callers may override
     * any of `pix_fmt` / `profile` / `level` via $params (e.g. an HDR profile).
     *
     * @param array<string, mixed> $params
     */
    private static function browserSafeVideoFlags(string $videoCodec, array $params): string
    {
        if ($videoCodec === 'libx264') {
            return ' -pix_fmt ' . (self::paramString($params, 'pix_fmt') ?? 'yuv420p')
                . ' -profile:v ' . (self::paramString($params, 'profile') ?? 'high')
                . ' -level ' . (self::paramString($params, 'level') ?? '4.1');
        }
        if ($videoCodec === 'libx265') {
            $pixFmt = self::paramString($params, 'pix_fmt');
            return $pixFmt !== null ? ' -pix_fmt ' . $pixFmt : '';
        }
        return '';
    }

    /**
     * Extracts a string value from a mixed parameter array.
     *
     * Returns the value when it is a non-empty string; otherwise null. Numeric
     * scalars are coerced to their string form to remain backwards-compatible
     * with callers that pass numbers as strings (e.g. '128k').
     *
     * @param array<string, mixed> $params
     */
    public static function paramString(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Extracts an integer value from a mixed parameter array.
     *
     * Returns the value when it is an int or a numeric string; otherwise null.
     *
     * @param array<string, mixed> $params
     */
    public static function paramInt(array $params, string $key): ?int
    {
        $value = $params[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Builds an FFmpeg command that produces ONE on-demand HLS segment.
     *
     * This backs the seek-aware VOD pipeline: instead of a single linear encode
     * that grows a live playlist, the media playlist is published complete up front
     * and each MPEG-TS segment is produced only when the player fetches it. Any
     * segment — including one far past what has been produced so far — is produced
     * directly by fast-seeking the source, so the user can seek anywhere.
     *
     * Two segment shapes are produced, selected per-stream by the codec params:
     *
     * 1. TRANSCODED RUNG (the ABR-ladder default; validated on 10-bit HEVC MKVs).
     *    Video is re-encoded with a capped-CRF recipe:
     *      - `-ss {start} -i input` — accurate fast input seek to the segment start.
     *      - `-t {duration}` — encode exactly this segment's window.
     *      - `-preset`/`-crf` — quality-driven encode (libx264/libx265 defaults kept
     *        when the caller omits them, so legacy callers are unaffected).
     *      - `-maxrate`/`-bufsize` — a hard VBV ceiling derived by A2's
     *        `Rendition::maxrate()` (≈1.07×target) / `Rendition::bufsize()` (2×maxrate)
     *        so the stream never exceeds the rung's advertised `BANDWIDTH`. Emitted
     *        ONLY when the caller passes `maxrate`/`bufsize`; absent them the encode
     *        stays CRF-only (unchanged legacy behaviour). A bare `-b:v` is
     *        deliberately NOT set — it would disable CRF mode; the cap is the VBV pair.
     *      - `-vf scale=…` — the per-rung downscale (`width`/`height`).
     *      - `-force_key_frames expr:gte(t,0)` — the first output frame is an IDR, so
     *        every segment is independently decodable (EXT-X-INDEPENDENT-SEGMENTS).
     *      - `browserSafeVideoFlags()` — `-pix_fmt`/`-profile:v`/`-level` (the `level`
     *        is honoured per-rung from `params['level']`).
     *    The `-force_key_frames`, `-t`, `-output_ts_offset`, and `-muxdelay`/
     *    `-muxpreload` framing are IDENTICAL across every transcoded rung — only the
     *    scale/bitrate/level differ — so hls.js can ABR-switch between rungs at any
     *    segment boundary seamlessly.
     *
     * 2. STREAM-COPY "Original" (plan §1 D4). When `video_codec` is `copy` the segment
     *    is a genuine `-c:v copy` (near-zero CPU): no `-preset`/`-crf`/scale/`-maxrate`/
     *    `-bufsize`/`browserSafeVideoFlags()`, and — critically — NO `-force_key_frames`
     *    (a stream copy cannot synthesise a keyframe at an arbitrary point). Because
     *    `-ss` before `-i` fast-seeks to the nearest PRECEDING source keyframe, a copy
     *    segment's actual start may drift up to one source GOP length from the nominal
     *    boundary. That is acceptable for a manually-pinned "Original" variant (the
     *    source is already HLS-safe H.264 + AAC) but is exactly why copy is NOT used
     *    for the ABR-switching rungs, which must stay frame-aligned. `-output_ts_offset`
     *    and `-muxdelay 0 -muxpreload 0` still apply to anchor the segment's PTS for
     *    playlist concatenation.
     *
     * Audio mirrors the same split: `audio_codec = copy` → `-c:a copy` (no
     * `-b:a`/`-ac`); otherwise re-encode to AAC (re-encoding avoids priming/alignment
     * artefacts at the boundary a fast-seek copy would introduce). The video and audio
     * codec decisions are INDEPENDENT, so mixed segments are fully supported — an
     * H.264 source with non-AAC audio yields video copy + audio re-encode, and the
     * reverse yields video re-encode + audio copy.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute path to write the .ts segment to.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    Encode params: `video_codec`
     *                                         (`libx264`/`libx265`/`copy`), `preset`,
     *                                         `crf`, `video_bitrate`/`maxrate`/`bufsize`
     *                                         (bps; capped-CRF VBV ceiling — `-maxrate`/
     *                                         `-bufsize` are emitted, never a bare
     *                                         `-b:v`), `pix_fmt`/`profile`/`level`,
     *                                         `width`/`height` (downscale),
     *                                         `audio_codec` (`aac`/…/`copy`),
     *                                         `audio_bitrate`, `audio_channels`,
     *                                         `subtitle_burn_in` (SV-1.6; optional
     *                                         `['path' => string, 'format' => ?string,
     *                                         'style' => ?array]` — see
     *                                         {@see resolveSubtitleBurnInFilter()}).
     *
     * @return string The complete FFmpeg segment command.
     */
    public function buildSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): string {
        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);

        // P3B multi-audio: when the master carries a shared AUDIO group, every video
        // variant segment is VIDEO-ONLY (`-an`) — sound travels in the audio-only
        // renditions instead, so muxing a (possibly wrong) audio track here would
        // duplicate and desync it.
        $videoOnly = ($params['video_only'] ?? false) === true;

        // Accurate input seek BEFORE -i so the decode starts at the segment boundary.
        $cmd = sprintf('%s -nostdin -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Video: first video track always. Audio: either a specific AUDIO-RELATIVE
        // stream index (P3B-S3 multi-audio, via audio_stream_index param — the N of
        // `-map 0:a:N`, NOT the global ffprobe index) or the first audio track.
        $audioStreamIndex = self::paramInt($params, 'audio_stream_index');
        $cmd .= ' -map 0:v:0';
        if (!$videoOnly) {
            if ($audioStreamIndex !== null && $audioStreamIndex >= 0) {
                // Map the Nth AUDIO stream (audio-relative, e.g. -map 0:a:1 = second audio track).
                $cmd .= sprintf(' -map 0:a:%d', $audioStreamIndex);
            } else {
                // Default: first audio track (backward compatible).
                $cmd .= ' -map 0:a:0?';
            }
        }
        $cmd .= ' -dn -sn';

        // Video: a transcoded rung (capped-CRF) OR a genuine stream copy for "Original".
        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === '') {
            $videoCodec = 'libx264';
        }
        if ($videoCodec === 'copy') {
            // Genuine passthrough: no encoder/scale flags and NO force_key_frames
            // (a copy cannot synthesise a keyframe mid-GOP). See the method docblock
            // for why this is Original-only and never used for ABR-switching rungs.
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? $defaultCrf);
            }
            // Per-rung VBV ceiling (capped CRF): a quality-driven encode that still
            // never exceeds the rung's advertised BANDWIDTH. Emitted only when the
            // caller supplies the rung cap; no bare -b:v (it would disable CRF mode).
            $maxrate = self::paramInt($params, 'maxrate');
            if ($maxrate !== null && $maxrate > 0) {
                $cmd .= ' -maxrate ' . $maxrate;
            }
            $bufsize = self::paramInt($params, 'bufsize');
            if ($bufsize !== null && $bufsize > 0) {
                $cmd .= ' -bufsize ' . $bufsize;
            }
            $width = self::paramInt($params, 'width');
            $height = self::paramInt($params, 'height');

            // P6: HDR tone-mapping — apply when hwaccel is enabled (passed in params) and
            // the source is HDR. Software encoders (libx264/libx265) output 8-bit 4:2:0 and
            // cannot encode HDR directly, so HDR sources always need tone-mapping to SDR.
            // This mirrors the logic in buildHwaccelSegmentCommand for the fallback path
            // when hwaccel is enabled but temporarily unavailable (returns null).
            $require_hdr_tone_map = ($params['require_hdr_tone_map'] ?? false) === true;
            $filters = [];
            // SV-1.1(b): prefer the tone-map filter STRING resolved ONCE at
            // job-creation time (TranscodeManager::computeSegmentParams) and
            // threaded through segment_params — using it directly means ZERO
            // per-segment probe()/needsToneMapping()/getToneMappingProfile()
            // re-derivation (which is what caused the per-segment ffprobe storm).
            $threadedToneMapFilter = self::paramString($params, 'tone_map_filter');
            if ($require_hdr_tone_map && $threadedToneMapFilter !== null) {
                $filters[] = $threadedToneMapFilter;
            } elseif ($require_hdr_tone_map || $this->needsToneMapping($inputPath)) {
                // Legacy fallback: pre-SV-1.1(b) persisted params / un-rescanned
                // items where the filter string was not threaded. Re-derive from
                // the input; probe() is memoised by path+mtime, so this is at most
                // one ffprobe per file per worker lifetime.
                $toneMapFilter = $this->getToneMappingProfile($inputPath, $outFile, $videoCodec);
                if ($toneMapFilter !== null && $toneMapFilter !== '') {
                    $filters[] = $toneMapFilter;
                }
            }
            // SV-1.6: subtitle burn-in — a software (libass) filter, so it must run
            // BEFORE any scale (scale operates on the already-composited frame) and,
            // for the hwaccel builder below, before the hardware-surface hwupload.
            $subtitleBurnInFilter = $this->resolveSubtitleBurnInFilter($params);
            if ($subtitleBurnInFilter !== null) {
                $filters[] = $subtitleBurnInFilter;
            }
            if ($width !== null && $height !== null) {
                $filters[] = "scale={$width}:{$height}:force_original_aspect_ratio=decrease";
            }
            if (!empty($filters)) {
                $cmd .= ' -vf "' . implode(',', $filters) . '"';
            }
            // IDR at the segment start → independently decodable, frame-aligned segment.
            $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: dropped entirely for a video-only (audio-group) variant segment,
        // else re-encode to AAC by default, or a genuine stream copy when asked.
        if ($videoOnly) {
            $cmd .= ' -an';
        } else {
            $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
            if ($audioCodec === '') {
                $audioCodec = 'aac';
            }
            if ($audioCodec === 'copy') {
                $cmd .= ' -c:a copy';
            } else {
                $cmd .= ' -c:a ' . $audioCodec;
                $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
                $audioChannels = self::paramInt($params, 'audio_channels');
                if ($audioChannels !== null && $audioChannels > 0) {
                    $cmd .= ' -ac ' . $audioChannels;
                }

                // SV-3.3: loudness normalization (ebur128/loudnorm filter)
                $loudnormFilter = $this->buildLoudnormFilter($params);
                if ($loudnormFilter !== null) {
                    $cmd .= ' -af "' . $loudnormFilter . '"';
                }
            }
        }

        // Anchor PTS to the absolute timeline position; no mux pre-roll.
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Builds an FFmpeg command that produces ONE on-demand AUDIO-ONLY HLS segment
     * (P3B multi-audio: the `seg-a{N}-NNNNN.ts` renditions behind each
     * `#EXT-X-MEDIA:TYPE=AUDIO` entry).
     *
     * Contract: `-vn` (no video decode/encode at all — an audio segment must be
     * near-free to produce), `-map 0:a:{n}` where `n` is the AUDIO-RELATIVE stream
     * index (`audio_stream_index` param, default 0), AAC at `audio_bitrate`
     * (default 128k), muxed as MPEG-TS with the same `-ss`/`-t`/`-output_ts_offset`
     * segment framing as {@see buildSegmentCommand()} so audio and video segments
     * stay aligned on the shared VOD timeline.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute path to write the .ts segment to.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    `audio_stream_index` (audio-relative),
     *                                        `audio_codec` (default aac),
     *                                        `audio_bitrate` (default 128k),
     *                                        `audio_channels` (optional).
     *
     * @return string The complete FFmpeg audio-only segment command.
     */
    public function buildAudioSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): string {
        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);
        $audioStreamIndex = self::paramInt($params, 'audio_stream_index') ?? 0;
        if ($audioStreamIndex < 0) {
            $audioStreamIndex = 0;
        }

        $cmd = sprintf('%s -nostdin -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;
        // No video at all; map exactly the requested audio track (audio-relative N).
        $cmd .= ' -vn -dn -sn';
        $cmd .= sprintf(' -map 0:a:%d', $audioStreamIndex);

        $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === '' || $audioCodec === 'copy') {
            // Audio renditions must stay AAC so every #EXT-X-MEDIA entry matches the
            // advertised mp4a.40.2 — a copy could smuggle a non-HLS codec through.
            $audioCodec = 'aac';
        }
        $cmd .= ' -c:a ' . $audioCodec;
        $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
        $audioChannels = self::paramInt($params, 'audio_channels');
        if ($audioChannels !== null && $audioChannels > 0) {
            $cmd .= ' -ac ' . $audioChannels;
        }

        // SV-3.3: loudness normalization (ebur128/loudnorm filter)
        $loudnormFilter = $this->buildLoudnormFilter($params);
        if ($loudnormFilter !== null) {
            $cmd .= ' -af "' . $loudnormFilter . '"';
        }

        // Same timeline anchoring as the video segments.
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Builds an FFmpeg loudnorm filter string for audio loudness normalization.
     *
     * The loudnorm filter performs EBU R128 loudness normalization. It can be
     * used in single-pass mode (with target values only) or two-pass mode
     * (first pass measures, second pass applies with measured values).
     *
     * Common target values:
     *   - Podcast/Online: I=-16, LRA=11, TP=-1.5
     *   - Broadcast: I=-23, LRA=7, TP=-2.0
     *   - Streaming (Netflix-style): I=-27, LRA=2.0, TP=-2.0
     *
     * @param array<string, mixed> $params Params with 'loudnorm' key:
     *                                      - I (float): Integrated loudness target (dB LUFS)
     *                                      - LRA (float): Loudness range target (dB LU)
     *                                      - TP (float): True peak maximum (dBTP)
     *                                      - measured_I (float): Measured integrated (2nd pass)
     *                                      - measured_LRA (float): Measured LRA (2nd pass)
     *                                      - measured_TP (float): Measured true peak (2nd pass)
     *                                      - measured_thresh (float): Measured threshold (2nd pass)
     *
     * @return string|null FFmpeg audio filter string, or null when not configured
     *
     * @since 0.74.0
     */
    public function buildLoudnormFilter(array $params): ?string
    {
        $loudnorm = $params['loudnorm'] ?? null;
        if (!is_array($loudnorm)) {
            return null;
        }

        $integrated = $loudnorm['I'] ?? null;
        if (!is_numeric($integrated)) {
            return null;
        }

        $parts = [];
        $parts[] = 'I=' . (float) $integrated;

        if (isset($loudnorm['LRA']) && is_numeric($loudnorm['LRA'])) {
            $parts[] = 'LRA=' . (float) $loudnorm['LRA'];
        }
        if (isset($loudnorm['TP']) && is_numeric($loudnorm['TP'])) {
            $parts[] = 'TP=' . (float) $loudnorm['TP'];
        }

        // Second-pass measured values (from first pass analysis)
        if (isset($loudnorm['measured_I']) && is_numeric($loudnorm['measured_I'])) {
            $parts[] = 'measured_I=' . (float) $loudnorm['measured_I'];
        }
        if (isset($loudnorm['measured_LRA']) && is_numeric($loudnorm['measured_LRA'])) {
            $parts[] = 'measured_LRA=' . (float) $loudnorm['measured_LRA'];
        }
        if (isset($loudnorm['measured_TP']) && is_numeric($loudnorm['measured_TP'])) {
            $parts[] = 'measured_TP=' . (float) $loudnorm['measured_TP'];
        }
        if (isset($loudnorm['measured_thresh']) && is_numeric($loudnorm['measured_thresh'])) {
            $parts[] = 'measured_thresh=' . (float) $loudnorm['measured_thresh'];
        }

        return 'loudnorm=' . implode(':', $parts);
    }

    /**
     * Builds a hardware-accelerated segment encode command.
     *
     * Uses the hwaccel registry to select the best encoder for the codec,
     * and builds the appropriate command with hardware-specific flags for
     * segment production. Falls back to null if no hardware encoder is
     * available.
     *
     * HDR tone-mapping is applied when:
     * - The source content is HDR (HLG or HDR10)
     * - AND either `require_hdr_tone_map` is set in params, OR the hardware
     *   encoder does not natively support HDR output (all current hwaccel
     *   encoders output 8-bit 4:2:0, so HDR content always needs tone-map)
     *
     * @param string $inputPath Source media file path
     * @param string $outFile Absolute final segment path
     * @param float $start Segment start offset in seconds
     * @param float $duration Segment length in seconds
     * @param array<string, mixed> $params Encode params (same as buildSegmentCommand)
     *
     * @return string|null The complete FFmpeg segment command, or null if hwaccel unavailable
     *
     * @since 0.36.0
     */
    public function buildHwaccelSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): ?string {
        // MASSIVE DEBUG: Trace buildHwaccelSegmentCommand calls
        $traceId = substr(md5((string)mt_rand() . microtime(true)), 0, 12);
        $timestamp = date('Y-m-d H:i:s.v');
        $pid = getmypid();
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
        $callerChain = implode(' <- ', array_map(
            fn($f) => basename($f['file'] ?? '??') . ':' . ($f['line'] ?? '?') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '::') . ($f['function'] ?? '??'),
            array_slice($backtrace, 1, 5)
        ));

        $this->logger->error(sprintf(
            '[HWACCEL_DEBUG][%s][PID:%d][TRACE:%s] buildHwaccelSegmentCommand called | hwaccelProbed=%s | inputPath=%s | outFile=%s | callers=%s',
            $timestamp,
            $pid,
            $traceId,
            self::$hwaccelProbed ? 'YES' : 'NO',
            basename($inputPath),
            basename($outFile),
            $callerChain
        ));

        if (!self::$hwaccelProbed) {
            $this->logger->error(sprintf(
                '[HWACCEL_DEBUG][%s][PID:%d][TRACE:%s] buildHwaccelSegmentCommand triggering probeHardwareAcceleration (was not probed)',
                $timestamp,
                $pid,
                $traceId
            ));
            $this->probeHardwareAcceleration();
        } else {
            $this->logger->error(sprintf(
                '[HWACCEL_DEBUG][%s][PID:%d][TRACE:%s] buildHwaccelSegmentCommand skipping probe (already probed)',
                $timestamp,
                $pid,
                $traceId
            ));
        }

        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';

        // Map software codec name to generic codec name for registry lookup
        $codecMap = [
            'libx264' => 'h264',
            'libx265' => 'hevc',
        ];
        $codec = $codecMap[$videoCodec] ?? 'h264';

        $require_hdr_tone_map = ($params['require_hdr_tone_map'] ?? false) === true;

        $capability = $this->hwaccelRegistry?->getEncoder($codec, $require_hdr_tone_map);
        if ($capability === null) {
            $this->logger->warning('No hardware encoder found', ['codec' => $codec]);
            return null;
        }

        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);

        // Base FFmpeg invocation with hardware input flags
        $cmd = sprintf(
            '%s -nostdin -y -hide_banner -loglevel error',
            escapeshellarg($this->ffmpegPath)
        );
        $cmd .= $this->buildHwaccelInputFlags($capability);
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Map video (first video track) and audio (first audio track, optional).
        // A video-only (audio-group) variant segment maps/encodes NO audio at all —
        // sound travels in the separate audio-only renditions.
        $videoOnly = ($params['video_only'] ?? false) === true;
        $audioStreamIndex = self::paramInt($params, 'audio_stream_index');
        $cmd .= ' -map 0:v:0';
        if (!$videoOnly) {
            if ($audioStreamIndex !== null && $audioStreamIndex >= 0) {
                // Audio-relative index: -map 0:a:N selects the Nth AUDIO stream.
                $cmd .= sprintf(' -map 0:a:%d', $audioStreamIndex);
            } else {
                $cmd .= ' -map 0:a:0?';
            }
        }
        $cmd .= ' -dn -sn';

        // Hardware video encoder
        $cmd .= ' -c:v ' . $capability->encoder;

        // Vendor-specific preset tuning
        switch ($capability->vendor) {
            case 'nvenc':
                $cmd .= ' -preset:v p4';
                break;
            case 'vaapi':
            case 'qsv':
                $cmd .= ' -preset:v fast';
                break;
            default:
                $cmd .= ' -preset:v medium';
        }

        // Rate control: CRF when supplied, and VBV ceiling when supplied
        $crf = self::paramInt($params, 'crf');
        if ($crf !== null) {
            $cmd .= ' -crf ' . $crf;
        }
        $maxrate = self::paramInt($params, 'maxrate');
        if ($maxrate !== null && $maxrate > 0) {
            $cmd .= ' -maxrate ' . $maxrate;
        }
        $bufsize = self::paramInt($params, 'bufsize');
        if ($bufsize !== null && $bufsize > 0) {
            $cmd .= ' -bufsize ' . $bufsize;
        }

        // Determine if HDR tone-mapping is needed:
        // All current hardware encoders (nvenc/vaapi/qsv/videotoolbox/amf/v4l2)
        // output 8-bit 4:2:0, so HDR content always requires tone-mapping to SDR.
        $hdrCapableEncoders = [
            'hevc_nvenc',
            'hevc_vaapi',
            'hevc_qsv',
            'hevc_videotoolbox',
            'hevc_amf',
        ];
        // Build filter chain: tone-mapping (if needed) + scale (if dimensions supplied)
        $filters = [];

        // SV-1.1(b): prefer the tone-map filter STRING resolved ONCE at
        // job-creation time (TranscodeManager::computeSegmentParams) and threaded
        // through segment_params — using it directly means ZERO per-segment
        // probe()/needsToneMapping()/getToneMappingProfile() re-derivation.
        $threadedToneMapFilter = self::paramString($params, 'tone_map_filter');
        if ($require_hdr_tone_map && $threadedToneMapFilter !== null) {
            $filters[] = $threadedToneMapFilter;
        } else {
            // Legacy fallback: pre-SV-1.1(b) persisted params / un-rescanned items
            // where the filter string was not threaded. Re-derive from the input;
            // probe() is memoised by path+mtime, so this is at most one ffprobe per
            // file per worker lifetime. HDR-capable hardware encoders pass HDR
            // through directly, so they only tone-map when the job-level
            // require_hdr_tone_map flag forces it.
            $needsToneMap = $require_hdr_tone_map || (
                $this->needsToneMapping($inputPath)
                && !in_array($capability->encoder, $hdrCapableEncoders, true)
            );
            if ($needsToneMap) {
                $toneMapFilter = $this->getToneMappingProfile($inputPath, $outFile, $videoCodec);
                if ($toneMapFilter !== null && $toneMapFilter !== '') {
                    $filters[] = $toneMapFilter;
                }
            }
        }

        // SV-1.6: subtitle burn-in — a software (libass) filter that MUST run
        // before the frame is uploaded to a hardware surface (a VAAPI/QSV hw
        // surface cannot be processed by a software filter — the same ordering
        // fix applied to SubtitleBurner's own VAAPI branch), so it is inserted
        // here, before both the scale filter and the hwupload filter below.
        $subtitleBurnInFilter = $this->resolveSubtitleBurnInFilter($params);
        if ($subtitleBurnInFilter !== null) {
            $filters[] = $subtitleBurnInFilter;
        }

        $width = self::paramInt($params, 'width');
        $height = self::paramInt($params, 'height');
        if ($width !== null && $height !== null) {
            $filters[] = "scale={$width}:{$height}:force_original_aspect_ratio=decrease";
        }

        // Upload the (system-memory) frames to a HW surface for encoders that
        // require it (VAAPI/QSV). This MUST come after the software scale/tonemap
        // filters and is emitted even when no other filter is present (the decode
        // path no longer keeps frames on a HW surface). NVENC/etc. return ''.
        $uploadFilter = $this->hwaccelUploadFilter($capability->vendor);
        if ($uploadFilter !== '') {
            $filters[] = $uploadFilter;
        }

        if (!empty($filters)) {
            $cmd .= ' -vf "' . implode(',', $filters) . '"';
        }

        // IDR at the segment start for independently decodable segments
        $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');

        // Audio: dropped for a video-only (audio-group) variant segment, else
        // re-encode to AAC by default, or stream copy when requested
        if ($videoOnly) {
            $cmd .= ' -an';
        } else {
            $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
            if ($audioCodec === 'copy') {
                $cmd .= ' -c:a copy';
            } else {
                $cmd .= ' -c:a ' . $audioCodec;
                $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
                $audioChannels = self::paramInt($params, 'audio_channels');
                if ($audioChannels !== null && $audioChannels > 0) {
                    $cmd .= ' -ac ' . $audioChannels;
                }
            }
        }

        // Anchor PTS to the absolute timeline position; no mux pre-roll.
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Builds the ffmpeg INPUT/decode hardware-acceleration flags for a segment
     * encode by delegating to the resolved vendor profile.
     *
     * These flags are placed BEFORE `-i` (input-side). Rather than re-deriving
     * per-vendor flags here (which historically diverged from the profiles and
     * emitted `-hwaccel_output_format cuda`/`vaapi`, forcing decoded frames to
     * stay as HARDWARE surfaces incompatible with the software `scale=`/tonemap
     * filters the segment command appends), this delegates to the resolved
     * profile's
     * {@see \Phlix\Media\Transcoding\Hwaccel\Profiles\HwaccelEncoderProfileInterface::getInputDeviceArgs()}
     * as the single source of truth for per-vendor input-device flags, so the
     * segment command's input-side flags cannot drift from the profiles.
     *
     * None of the profiles emit `-hwaccel_output_format`, so decoded frames land
     * in system memory where the software scale/tonemap filters are valid. Any
     * re-upload required by a HW encoder (VAAPI/QSV) is added to the filter
     * chain by {@see hwaccelUploadFilter()}, not here.
     *
     * @param HwaccelCapability $capability The probed hardware capability
     *
     * @return string The leading input-side hwaccel flags (empty for software)
     *
     * @since 0.36.0
     */
    private function buildHwaccelInputFlags(HwaccelCapability $capability): string
    {
        if ($this->hwaccelRegistry === null) {
            return '';
        }

        $this->hwaccelProfileFactory ??= new HwaccelProfileFactory($this->hwaccelRegistry);

        $profile = $this->hwaccelProfileFactory->getProfileForVendor($capability->vendor);
        if ($profile === null) {
            return '';
        }

        return $profile->getInputDeviceArgs($capability);
    }

    /**
     * Returns the filter needed to upload system-memory frames to a hardware
     * frames context for encoders that cannot consume system-memory frames.
     *
     * After the input flags decode into system memory (no
     * `-hwaccel_output_format`) and the software scale/tonemap filters run,
     * VAAPI and QSV encoders (`h264_vaapi`, `h264_qsv`, …) still require the
     * frames to be uploaded to a HW surface. NVENC / VideoToolbox / AMF
     * encoders accept system-memory frames directly, so no upload is needed and
     * an empty string is returned.
     *
     * @param string $vendor The hardware vendor identifier
     *
     * @return string The trailing filter (empty when no upload is required)
     *
     * @since 0.36.0
     */
    private function hwaccelUploadFilter(string $vendor): string
    {
        return match (strtolower($vendor)) {
            'vaapi' => 'format=nv12,hwupload',
            'qsv' => 'format=nv12,hwupload=extra_hw_frames=64',
            default => '',
        };
    }

    /**
     * Returns a summary of the probed hardware-acceleration state, suitable for
     * a one-time boot log of the chosen accelerator.
     *
     * @return array{
     *     enabled: bool,
     *     prefer_hardware: bool,
     *     available: list<string>,
     *     preferred: string|null,
     *     chosen_vendor: string|null,
     *     chosen_encoder: string|null
     * }
     *
     * @since 0.36.0
     */
    public function getHardwareAccelerationSummary(): array
    {
        $enabled = ($this->config['enabled'] ?? false) === true;
        $preferHardware = ($this->config['prefer_hardware'] ?? true) === true;

        $available = [];
        $chosenVendor = null;
        $chosenEncoder = null;

        if ($this->hwaccelRegistry !== null) {
            $available = array_values(array_map(
                static fn(HwaccelCapability $cap): string => $cap->vendor,
                $this->hwaccelRegistry->getAll()
            ));

            $best = $this->hwaccelRegistry->getEncoder('h264');
            if ($best !== null) {
                $chosenVendor = $best->vendor;
                $chosenEncoder = $best->encoder;
            }
        }

        return [
            'enabled' => $enabled,
            'prefer_hardware' => $preferHardware,
            'available' => $available,
            'preferred' => $this->preferredAccelerator,
            'chosen_vendor' => $chosenVendor,
            'chosen_encoder' => $chosenEncoder,
        ];
    }

    /**
     * Launches an on-demand segment encode as a detached background process,
     * writing atomically so a reader never sees a half-written segment.
     *
     * FFmpeg writes to a unique `.part` temp file; on success it is `mv`'d to the
     * final path (an atomic rename on the same filesystem), on failure the temp is
     * removed. The caller therefore treats `is_file($outFile)` as "segment ready".
     * The launch itself returns immediately (the `& echo $!` backgrounds ffmpeg),
     * so the worker never blocks on the encode — the caller polls for the file with
     * a coroutine-yielding sleep.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute final segment path.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    Encode params (see buildSegmentCommand()).
     * @param string|null          $cancelKey Optional cancel key: when a segment
     *                                         registry is wired, the spawned PID is
     *                                         tracked under this key so the encode
     *                                         can be released on wait-timeout and
     *                                         killed on cancel (SV-4.2). Defaults
     *                                         to $outFile.
     * @param string|null          $cancelGroup Optional cancel-group id (the relay
     *                                         channel/request id) so an HTTP_CANCEL
     *                                         can kill the encode by channel id
     *                                         (SV-4.2 / X1). Null for direct-LAN
     *                                         requests.
     *
     * @return int OS process id of the launched job (0 if launch failed).
     *
     * @since SV-4.2 Wraps the encode in `timeout <transcode_timeout>` (launched
     *        under setsid) and tracks the process-group PID for cancellation.
     */
    public function startSegmentEncode(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params,
        ?string $cancelKey = null,
        ?string $cancelGroup = null
    ): int {
        $tmp = $outFile . '.part-' . bin2hex(random_bytes(4));
        $cancelKey ??= $outFile;

        // P3B multi-audio: an audio-only rendition segment never touches the video
        // pipeline (no hwaccel, no -c:v) — it is a cheap -vn AAC extract/encode.
        if (($params['audio_only'] ?? false) === true) {
            $encode = $this->buildAudioSegmentCommand($inputPath, $tmp, $start, $duration, $params);
            return $this->launchDetachedSegment($encode, $tmp, $outFile, $cancelKey, $cancelGroup);
        }

        // Try hardware acceleration first if enabled AND preferred in config.
        // prefer_hardware=false means prefer software even if hwaccel is available.
        // The merged HwAccelConfig is flat (enabled, prefer_hardware at root level).
        $hwaccelEnabled = ($this->config['enabled'] ?? false) === true;
        $preferHardware = ($this->config['prefer_hardware'] ?? true) === true;
        $encode = null;

        if ($hwaccelEnabled && $preferHardware) {
            $encode = $this->buildHwaccelSegmentCommand($inputPath, $tmp, $start, $duration, $params);
        }

        // Fall back to software encoding if hwaccel is disabled, unavailable, or returned null
        if ($encode === null) {
            $encode = $this->buildSegmentCommand($inputPath, $tmp, $start, $duration, $params);
        }

        return $this->launchDetachedSegment($encode, $tmp, $outFile, $cancelKey, $cancelGroup);
    }

    /**
     * Launches a built segment encode command detached, with the shared
     * atomic-publish tail (mv temp → final on success, rm temp on failure).
     *
     * SV-4.2 ([S-F23]): the atomic-publish chain is wrapped in
     * `timeout <transcode_timeout>` so an abandoned scrub-storm encode can never
     * run unbounded, and the spawned PID is registered under `$cancelKey` so the
     * poll loop / a cancel hook can kill it early. Without a wired registry the
     * `timeout` wrapper alone still bounds the encode.
     *
     * @param string      $encode      The complete FFmpeg command writing to `$tmp`.
     * @param string      $tmp         The `.part-*` temp path the command writes.
     * @param string      $outFile     The final segment path published on success.
     * @param string|null $cancelKey   Key to track the PID under (null = $outFile).
     * @param string|null $cancelGroup Cancel-group id (relay channel/request id).
     *
     * @return int OS process id of the launched job (0 if launch failed).
     */
    private function launchDetachedSegment(
        string $encode,
        string $tmp,
        string $outFile,
        ?string $cancelKey = null,
        ?string $cancelGroup = null
    ): int {
        $full = $this->buildDetachedSegmentCommand($encode, $tmp, $outFile, $this->getTranscodeTimeout());

        $pid = shell_exec($full);
        if (!is_string($pid) || trim($pid) === '') {
            $this->logger->error('Failed to launch on-demand segment encode', ['segment' => $outFile]);
            return 0;
        }

        $childPid = (int) trim($pid);
        if ($childPid > 0) {
            // The PID is the `setsid` group leader (PGID == PID), so the registry
            // can signal the whole group and reach ffmpeg directly. Thread this
            // launcher's OWN `.part-<hex>` temp so a kill/dead-release removes only
            // this exact temp, never the whole `{$final}.part-*` family — a sibling
            // worker's live temp for the same final path must survive (SV-4.2
            // re-review Low).
            $this->segmentRegistry?->register($cancelKey ?? $outFile, $childPid, $cancelGroup, $tmp);
        }
        return $childPid;
    }

    /**
     * Builds the backgrounded launch string for a detached on-demand segment
     * encode. Factored out so the `timeout` wrapper and atomic-publish tail can
     * be asserted in tests without spawning a process (SV-4.2).
     *
     * The atomic-publish chain (`encode && mv || rm`) runs inside a single
     * `sh -c`; that whole `sh -c` is wrapped in `timeout -k <grace> -s TERM
     * <seconds>` when a positive timeout is configured, so a hung/abandoned
     * encode is TERM'd and then force-KILLed by `timeout` itself after the grace
     * (SV-4.2 finding #4 — `timeout` self-escalates rather than relying on an
     * external SIGKILL reaching only the wrapper).
     *
     * The whole command runs under `setsid` so it becomes its own process-group
     * leader (PGID == the returned PID). This lets a cancel/disconnect signal the
     * whole group (negative PID) and reach the ffmpeg grandchild directly, not
     * just the `timeout`/`sh` wrapper. The launch itself is `nohup ... & echo $!`,
     * so the worker never blocks on the encode.
     *
     * NOTE: when `timeout` (or an external signal) terminates the chain, the
     * trailing `|| rm` does NOT run — the shell is killed mid-flight — so the
     * `.part-*` temp survives. Callers clean it explicitly on kill / dead release
     * (see {@see SegmentProcessRegistry::kill()} /
     * {@see SegmentProcessRegistry::releaseAfterWaitTimeout()}). The `|| rm` only
     * fires when ffmpeg exits nonzero on its own.
     *
     * @param string $encode      The FFmpeg command writing to `$tmp`.
     * @param string $tmp         The `.part-*` temp path.
     * @param string $outFile     The final published segment path.
     * @param int    $timeoutSecs Timeout in seconds (0 = no timeout wrapper).
     *
     * @return string The full `nohup setsid ... & echo $!` launch string.
     *
     * @since SV-4.2
     */
    public function buildDetachedSegmentCommand(
        string $encode,
        string $tmp,
        string $outFile,
        int $timeoutSecs = 0
    ): string {
        // Atomic publish: rename on success, clean the temp on failure.
        $inner = $encode
            . ' && mv -f ' . escapeshellarg($tmp) . ' ' . escapeshellarg($outFile)
            . ' || rm -f ' . escapeshellarg($tmp);

        // SV-4.2: wrap in timeout to enforce transcode_timeout for on-demand
        // segment encodes (previously only whole-file/CMAF/recording paths did).
        // `-k <grace> -s TERM` makes timeout escalate to SIGKILL of its own child
        // group if the encode ignores the TERM (finding #4 backstop).
        if ($timeoutSecs > 0) {
            $launched = 'timeout -k ' . self::TIMEOUT_KILL_GRACE_SECONDS . ' -s TERM '
                . $timeoutSecs . ' sh -c ' . escapeshellarg($inner);
        } else {
            $launched = 'sh -c ' . escapeshellarg($inner);
        }

        $log = dirname($outFile) . '/ffmpeg-segments.log';
        // `setsid` → own process group so a cancel can group-signal ffmpeg directly.
        return sprintf('nohup setsid %s >> %s 2>&1 & echo $!', $launched, escapeshellarg($log));
    }

    /**
     * Formats a seconds value for an FFmpeg time argument (millisecond precision,
     * no scientific notation, trimmed trailing zeros). Negative inputs clamp to 0.
     */
    private static function seconds(float $value): string
    {
        if ($value < 0) {
            $value = 0.0;
        }
        $formatted = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Generates trickplay sprite sheets for chapter-based scrubbing preview.
     *
     * Produces a 6-column sprite sheet grid (default 60 thumbs = 6x10) at 160x90
     * each, plus a JSON timeline mapping each thumbnail index to its (x, y)
     * position within the sprite and the video timestamp it represents.
     *
     * @param string $videoPath  Source video file path.
     * @param string $outputDir  Directory to write sprite.jpg and timeline.json.
     * @param int    $count      Number of thumbnails to generate (default 60).
     *
     * @return array{0: string, 1: string}|null Absolute paths to [sprite, timeline]
     *         on success, or null on failure (missing FFmpeg, probe error, encode error).
     *
     * @since 0.35.0
     */
    public function generateTrickplaySprites(string $videoPath, string $outputDir, int $count = 60): ?array
    {
        $probe = $this->probe($videoPath);
        if (!is_array($probe)) {
            return null;
        }

        $format = $probe['format'] ?? null;
        if (!is_array($format)) {
            return null;
        }

        $rawDuration = $format['duration'] ?? null;
        if (!is_numeric($rawDuration)) {
            return null;
        }

        $duration = (float) $rawDuration;
        if ($duration <= 0) {
            return null;
        }

        $interval = $duration / $count;
        $cols = 6;
        $rows = (int) ceil($count / $cols);
        $thumbW = 160;
        $thumbH = 90;
        $margin = 2;
        $padding = 1;

        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                return null;
            }
        }

        $spritePath = $outputDir . '/sprite.jpg';
        $timelinePath = $outputDir . '/timeline.json';

        // Use the midpoint timestamp so the first frame capture is not black
        // (often an opening slate/blank) when seeking to time 0.
        $captureTime = self::seconds($duration / 2.0);

        $vfFilter = sprintf(
            'fps=1/%s,scale=%d:%d,tile=%d:%d:margin=%d:padding=%d',
            escapeshellarg(self::seconds($interval)),
            $thumbW,
            $thumbH,
            $cols,
            $rows,
            $margin,
            $padding
        );
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -ss %s -i %s '
            . '-vf "%s" -frames:v 1 %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($captureTime),
            escapeshellarg($videoPath),
            $vfFilter,
            escapeshellarg($spritePath)
        );

        $outputLines = [];
        $exitCode = 0;
        $this->runCoroutineAwareCommand($cmd, $outputLines, $exitCode);
        if ($exitCode !== 0 || !is_file($spritePath)) {
            $output = implode("\n", $outputLines);
            $this->logger->warning('Trickplay sprite generation failed', [
                'video' => $videoPath,
                'output' => $output,
            ]);
            return null;
        }

        // Build timeline JSON: each entry maps thumbnail index to pixel offset
        // within the sprite sheet and the corresponding video timestamp.
        $timeline = [];
        for ($i = 0; $i < $count; $i++) {
            $row = (int) floor($i / $cols);
            $col = $i % $cols;
            $x = $col * ($thumbW + $margin + $padding);
            $y = $row * ($thumbH + $margin + $padding);
            $time = $i * $interval;
            $timeline[] = ['time' => $time, 'x' => $x, 'y' => $y];
        }

        $json = json_encode($timeline, JSON_THROW_ON_ERROR);
        file_put_contents($timelinePath, $json);

        return [$spritePath, $timelinePath];
    }

    /**
     * Sets the preferred hardware accelerator from configuration.
     *
     * When set, {@see getBestAcceleratorForCodec()} will prefer this accelerator
     * over others when it supports the requested codec.
     *
     * @param string|null $name Accelerator name (e.g., 'cuda', 'qsv', 'vaapi') or null to clear
     *
     * @since 0.36.0
     */
    public function setPreferredAccelerator(?string $name): void
    {
        $this->preferredAccelerator = $name;
    }

    /**
     * Sets the transcoding configuration.
     *
     * Configuration should include:
     *  - tone_mapping_mode: 'none' | 'zscale' | 'libplacebo'
     *  - prefer_hdr_output: bool
     *
     * @param array<mixed, mixed> $config Transcoding configuration array
     *
     * @since 0.36.0
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Loads transcoding configuration from the config file.
     *
     * @param string $configPath Path to the transcoding.php config file
     *
     * @return void
     *
     * @since 0.36.0
     */
    public function loadConfig(string $configPath): void
    {
        if (is_file($configPath) && is_readable($configPath)) {
            $config = include $configPath;
            if (is_array($config)) {
                $this->config = $config;
            }
        }
    }

    /**
     * Returns the current transcoding configuration.
     *
     * @return array<string, mixed>
     *
     * @since 0.36.0
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Returns the preferred accelerator name from configuration.
     *
     * @return string|null
     *
     * @since 0.36.0
     */
    public function getPreferredAccelerator(): ?string
    {
        return $this->preferredAccelerator;
    }

    /**
     * Detects and returns all available hardware accelerators on this machine.
     *
     * Runs `ffmpeg -hide_banner -hwaccels` to discover available hwaccel methods,
     * then probes each for specific encoders using `ffmpeg -hide_banner -encoders`.
     *
     * Results are cached after the first call.
     *
     * @return array<string, HardwareAccelerator> Map of accelerator name to accelerator object
     *
     * @since 0.36.0
     */
    public function getHardwareAccelerators(): array
    {
        if ($this->hardwareAccelerators !== null) {
            return $this->hardwareAccelerators;
        }

        $accelerators = [];

        // Get list of available hwaccels from ffmpeg
        $cmd = sprintf(
            '%s -hide_banner -hwaccels 2>/dev/null',
            escapeshellarg($this->ffmpegPath)
        );

        $output = $this->runCoroutineAwareShellExec($cmd);
        if (!is_string($output)) {
            $this->hardwareAccelerators = [];
            return [];
        }

        $lines = explode("\n", trim($output));
        // First line is "Hardware accelerator methods:" or empty, skip it
        $hwaccelNames = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'Hardware accelerator methods:') {
                continue;
            }
            $hwaccelNames[] = $line;
        }

        // Get all available encoders in one shot
        $encodersCmd = sprintf(
            '%s -hide_banner -encoders 2>/dev/null',
            escapeshellarg($this->ffmpegPath)
        );
        $encodersOutput = $this->runCoroutineAwareShellExec($encodersCmd);
        $allEncoders = is_string($encodersOutput) ? $encodersOutput : '';

        // Map hwaccel name → encoder suffixes to check
        $encoderMap = [
            'cuda'          => ['h264_nvenc', 'hevc_nvenc'],
            'qsv'           => ['h264_qsv', 'hevc_qsv'],
            'vaapi'         => ['h264_vaapi', 'hevc_vaapi'],
            'opencl'        => ['hevc_cl'],
            'videotoolbox'  => ['h264_videotoolbox', 'hevc_videotoolbox'],
            'amf'           => ['h264_amf', 'hevc_amf'],
            'd3d11va'       => ['h264_d3d11va', 'hevc_d3d11va'],
            'dxva2'         => ['h264_dxva2', 'hevc_dxva2'],
            'v4l2m2m'       => ['h264_v4l2m2m', 'hevc_v4l2m2m'],
        ];

        foreach ($hwaccelNames as $name) {
            if (!isset($encoderMap[$name])) {
                // Unknown hwaccel — still expose it with no known encoders
                $accelerators[$name] = new HardwareAccelerator($name, [], true);
                continue;
            }

            $encoderSuffixes = $encoderMap[$name];
            $foundEncoders = [];

            foreach ($encoderSuffixes as $suffix) {
                if (str_contains($allEncoders, $suffix)) {
                    $foundEncoders[] = $suffix;
                }
            }

            $accelerators[$name] = new HardwareAccelerator($name, $foundEncoders, true);
        }

        $this->hardwareAccelerators = $accelerators;
        $this->logger->info('Hardware accelerators detected', [
            'accelerators' => array_keys($accelerators),
        ]);

        return $accelerators;
    }

    /**
     * Returns all available hardware accelerators as a flat array.
     *
     * Alias for {@see getHardwareAccelerators()} for callers that need a list.
     *
     * @return array<string, HardwareAccelerator>
     *
     * @since 0.36.0
     */
    public function getAvailableHardwareAccelerators(): array
    {
        return $this->getHardwareAccelerators();
    }

    /**
     * Returns the accelerator with the given name, or null if not available.
     *
     * @param string $name Accelerator name (e.g., 'cuda', 'qsv', 'vaapi')
     *
     * @return HardwareAccelerator|null
     *
     * @since 0.36.0
     */
    public function getAcceleratorByName(string $name): ?HardwareAccelerator
    {
        $accelerators = $this->getHardwareAccelerators();

        return $accelerators[$name] ?? null;
    }

    /**
     * Builds a gapless-compatible FFmpeg HLS segment command.
     *
     * Gapless playback requires sample-accurate boundaries and no decoder
     * flush frames. This method extends buildSegmentCommand() with:
     * - `-no_dtk` flag to disable decoder flush (gapless key)
     * - Accurate timestamp anchoring for concatenation
     * - Uses -muxdelay 0 -muxpreload 0 for precise timing
     *
     * The resulting segments are designed to be concatenated without gaps
     * or overlaps in the audio timeline.
     *
     * @param string               $inputPath Source media file.
     * @param string               $outFile   Absolute path to write the .ts segment to.
     * @param float                $start     Segment start offset in seconds.
     * @param float                $duration  Segment length in seconds.
     * @param array<string, mixed> $params    Encode params (same as buildSegmentCommand).
     *
     * @return string The complete FFmpeg gapless segment command.
     *
     * @see self::buildSegmentCommand() For the standard (non-gapless) segment command
     *
     * @since 0.37.0
     */
    public function buildGaplessSegmentCommand(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params
    ): string {
        $startArg = self::seconds($start);
        $durArg = self::seconds($duration);

        // Gapless: no decoder flush frames + accurate seeking
        $cmd = sprintf('%s -nostdin -y -hide_banner -loglevel error', escapeshellarg($this->ffmpegPath));
        $cmd .= ' -no_dtk';
        $cmd .= ' -ss ' . $startArg;
        $cmd .= ' -i ' . escapeshellarg($inputPath);
        $cmd .= ' -t ' . $durArg;

        // Only the first video + first audio track
        $cmd .= ' -map 0:v:0 -map 0:a:0? -dn -sn';

        // Video: same as buildSegmentCommand
        $videoCodec = self::paramString($params, 'video_codec') ?? 'libx264';
        if ($videoCodec === '') {
            $videoCodec = 'libx264';
        }
        if ($videoCodec === 'copy') {
            $cmd .= ' -c:v copy';
        } else {
            $cmd .= ' -c:v ' . $videoCodec;
            if ($videoCodec === 'libx264' || $videoCodec === 'libx265') {
                $cmd .= ' -preset ' . (self::paramString($params, 'preset') ?? 'veryfast');
                $defaultCrf = $videoCodec === 'libx265' ? 28 : 23;
                $cmd .= ' -crf ' . (self::paramInt($params, 'crf') ?? $defaultCrf);
            }
            $width = self::paramInt($params, 'width');
            $height = self::paramInt($params, 'height');
            if ($width !== null && $height !== null) {
                $cmd .= ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease"';
            }
            $cmd .= ' -force_key_frames ' . escapeshellarg('expr:gte(t,0)');
            $cmd .= self::browserSafeVideoFlags($videoCodec, $params);
        }

        // Audio: re-encode for gapless (no priming issues with copy)
        $audioCodec = self::paramString($params, 'audio_codec') ?? 'aac';
        if ($audioCodec === '') {
            $audioCodec = 'aac';
        }
        if ($audioCodec === 'copy') {
            $cmd .= ' -c:a copy';
        } else {
            $cmd .= ' -c:a ' . $audioCodec;
            $cmd .= ' -b:a ' . (self::paramString($params, 'audio_bitrate') ?? '128k');
            $audioChannels = self::paramInt($params, 'audio_channels');
            if ($audioChannels !== null && $audioChannels > 0) {
                $cmd .= ' -ac ' . $audioChannels;
            }
        }

        // Gapless timestamp anchoring: zero mux delay for sample-accurate concatenation
        $cmd .= ' -muxdelay 0 -muxpreload 0';
        $cmd .= ' -output_ts_offset ' . $startArg;
        $cmd .= ' -f mpegts ' . escapeshellarg($outFile);

        return $cmd;
    }

    /**
     * Returns the best accelerator for a given codec.
     *
     * Preference order:
     *  1. Configured preferred accelerator (if it supports the codec)
     *  2. First available accelerator that has an encoder for the codec
     *
     * @param string $codec Codec name (e.g., 'h264', 'hevc', 'av1')
     *
     * @return HardwareAccelerator|null Best matching accelerator or null if none support the codec
     *
     * @since 0.36.0
     */
    public function getBestAcceleratorForCodec(string $codec): ?HardwareAccelerator
    {
        $accelerators = $this->getHardwareAccelerators();

        // Prefer configured preferred accelerator first
        if ($this->preferredAccelerator !== null) {
            $preferred = $accelerators[$this->preferredAccelerator] ?? null;
            if ($preferred !== null && $preferred->supportsCodec($codec)) {
                return $preferred;
            }
        }

        // Fall back to first accelerator that has an encoder for this codec
        foreach ($accelerators as $accelerator) {
            if ($accelerator->supportsCodec($codec)) {
                return $accelerator;
            }
        }

        return null;
    }
}
