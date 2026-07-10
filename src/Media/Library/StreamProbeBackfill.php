<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Media\Transcoding\FfmpegRunner;
use Psr\Log\LoggerInterface;

/**
 * Lazily backfills an EXISTING media item's full `media_streams` set the first
 * time its playback info is requested.
 *
 * Items scanned before migration 071 carry at most one video + one audio row
 * and no subtitle rows, so the playback-info track menus (see
 * {@see StreamTrackShaper}) would show a single audio track and zero subtitles
 * regardless of the file's real contents — until a manual rescan. Both
 * playback-info dispatch paths
 * ({@see \Phlix\Server\Http\Controllers\MediaItemController::getPlaybackInfo()}
 * and {@see \Phlix\Server\WebPortal\WebPortalRouter::getPlaybackInfo()}) call
 * {@see ensureFor()} instead, which runs ONE blocking ffprobe (~1s, acceptable
 * once per item) when the stored rows look unprobed, replaces them with the
 * full set derived by {@see MediaScanner::summarizeProbe()} (the exact
 * scan-time logic, so the two paths never drift), and re-reads.
 *
 * Guards — the probe runs AT MOST ONCE per item:
 * - `media_items.streams_probed_at` (migration 071) short-circuits every later
 *   request, INCLUDING items that genuinely have 1 audio + 0 subtitle streams.
 *   It is stamped on success AND on probe failure (failure degrades to the
 *   previously-stored rows but must not retry on every request).
 * - Rows that already look fully probed (any subtitle row, or 2+ audio rows)
 *   are trusted without probing.
 * - A missing file on disk skips the probe without stamping, so the item is
 *   retried once the file re-appears (e.g. a temporarily unmounted share).
 *
 * @since 0.74.0
 */
class StreamProbeBackfill
{
    /** Item repository used to read/replace stream rows and stamp the marker. */
    private ItemRepository $itemRepository;

    /**
     * Probe runner; built lazily from config/ffmpeg.php when not injected
     * (ctor injection is the test seam). Null until first needed.
     */
    private ?FfmpegRunner $ffmpeg;

    /** Whether an FfmpegRunner construction attempt has already happened. */
    private bool $ffmpegResolved;

    /** Media-channel logger (failures are logged, never thrown to callers). */
    private LoggerInterface $logger;

    /**
     * @param ItemRepository       $itemRepository Repository for the streams + marker writes.
     * @param FfmpegRunner|null    $ffmpeg         Optional probe runner (test seam); when null
     *                                             one is built from config/ffmpeg.php on first use.
     * @param LoggerInterface|null $logger         Optional logger (defaults to the MEDIA channel).
     */
    public function __construct(
        ItemRepository $itemRepository,
        ?FfmpegRunner $ffmpeg = null,
        ?LoggerInterface $logger = null
    ) {
        $this->itemRepository = $itemRepository;
        $this->ffmpeg = $ffmpeg;
        $this->ffmpegResolved = $ffmpeg !== null;
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Returns the item's stream rows, probing + persisting the full set first
     * when they look unprobed. Fully guarded: any failure degrades to the
     * given `$streams` unchanged, so playback info always renders.
     *
     * @param array<string, mixed>             $item    Hydrated media_items row
     *                                                  (`id`, `path`, `streams_probed_at`).
     * @param array<int, array<string, mixed>> $streams The item's currently stored
     *                                                  `media_streams` rows.
     *
     * @return array<int, array<string, mixed>> Fresh rows after a successful
     *         backfill, otherwise `$streams` unchanged.
     */
    public function ensureFor(array $item, array $streams): array
    {
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        if ($itemId === '') {
            return $streams;
        }

        // Probed-marker guard: stamped once per item (success OR failure), so
        // the blocking probe never runs twice — even for files that genuinely
        // have one audio track and no subtitles.
        if (!empty($item['streams_probed_at'])) {
            return $streams;
        }

        // Rows already carrying a subtitle track or a second audio track can
        // only have come from a full-set probe — trust them without probing.
        if (self::looksFullyProbed($streams)) {
            return $streams;
        }

        // No file on disk → nothing to probe. Deliberately NOT stamped, so the
        // item gets its one-shot probe once the file re-appears.
        $path = is_string($item['path'] ?? null) ? $item['path'] : '';
        if ($path === '' || !is_file($path)) {
            return $streams;
        }

        try {
            $ffmpeg = $this->resolveFfmpeg();
            if ($ffmpeg === null) {
                return $streams; // no runner available — leave unstamped, degrade
            }

            $probe = $ffmpeg->probe($path);
            if (!is_array($probe)) {
                // Probe ran and failed on a present file: stamp so a broken
                // file does not re-run the blocking probe on every request.
                $this->itemRepository->markStreamsProbed($itemId);
                return $streams;
            }

            $summary = MediaScanner::summarizeProbe($probe);
            if ($summary['streams'] !== []) {
                // Same delete-then-reinsert replacement the scanner uses, so a
                // later rescan stays idempotent with these rows.
                $this->itemRepository->deleteStreamsByItem($itemId);
                foreach ($summary['streams'] as $stream) {
                    $this->itemRepository->addStream($itemId, $stream);
                }
            }
            $this->itemRepository->markStreamsProbed($itemId);

            return $summary['streams'] !== []
                ? $this->itemRepository->getItemStreams($itemId)
                : $streams;
        } catch (\Throwable $e) {
            $this->logger->debug('Lazy stream backfill failed; serving stored rows', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            // Best-effort stamp so a persistently-failing item cannot loop the
            // blocking probe; its own failure is swallowed too.
            try {
                $this->itemRepository->markStreamsProbed($itemId);
            } catch (\Throwable) {
                // Marker write failed (e.g. pre-071 schema) — nothing else to do.
            }
            return $streams;
        }
    }

    /**
     * Whether stored rows can only have come from a full-set probe: any
     * subtitle row, or two or more audio rows (the pre-071 scanner wrote at
     * most one audio row and never subtitles).
     *
     * @param array<int, array<string, mixed>> $streams Stored `media_streams` rows.
     */
    private static function looksFullyProbed(array $streams): bool
    {
        $audio = 0;
        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $type = $stream['stream_type'] ?? null;
            if ($type === 'subtitle') {
                return true;
            }
            if ($type === 'audio' && ++$audio >= 2) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the probe runner, building one from config/ffmpeg.php on first
     * use when none was injected. Null when construction fails (backfill then
     * degrades to the stored rows without stamping).
     */
    private function resolveFfmpeg(): ?FfmpegRunner
    {
        if ($this->ffmpegResolved) {
            return $this->ffmpeg;
        }
        $this->ffmpegResolved = true;

        try {
            $configFile = dirname(__DIR__, 3) . '/config/ffmpeg.php';
            /** @var array<string, mixed> $config */
            $config = is_file($configFile) ? (include $configFile) : [];
            $ffmpegPath = is_string($config['ffmpeg_path'] ?? null) ? $config['ffmpeg_path'] : '/usr/bin/ffmpeg';
            $ffprobePath = is_string($config['ffprobe_path'] ?? null) ? $config['ffprobe_path'] : '/usr/bin/ffprobe';
            $this->ffmpeg = new FfmpegRunner($ffmpegPath, $ffprobePath);
        } catch (\Throwable $e) {
            $this->logger->debug('Lazy stream backfill could not build FfmpegRunner', [
                'error' => $e->getMessage(),
            ]);
            $this->ffmpeg = null;
        }

        return $this->ffmpeg;
    }
}
