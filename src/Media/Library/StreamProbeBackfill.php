<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Media\Transcoding\FfmpegRunner;
use Psr\Log\LoggerInterface;

/**
 * Lazily backfills a missing VIDEO codec on an EXISTING media item the first
 * time its detail is requested ({@see ensureVideoCodecFor()}).
 *
 * Items scanned before migration 071 carry at most one video + one audio row
 * and no subtitle rows, so the playback-info track menus (see
 * {@see StreamTrackShaper}) show a single audio track and zero subtitles until
 * the item is rescanned.
 *
 * Item C: playback info does NOT call this class. Both playback-info dispatch
 * paths ({@see \Phlix\Server\Http\Controllers\MediaItemController::getPlaybackInfo()}
 * and {@see \Phlix\Server\WebPortal\WebPortalRouter::getPlaybackInfo()}) serve the
 * stored `media_streams` rows and never fork ffprobe inside the request.
 * Consequently, legacy AUDIO and SUBTITLE gaps have NO automated repair path:
 * a rescan is the only repair. {@see ensureVideoCodecFor()} repairs only a missing
 * video codec. A queued stream-backfill job covering audio and subtitle gaps is
 * an OWNER DECISION that is still pending; do not add an inline probe in its
 * place. A legacy item keeps `streams_probed_at` NULL, so it stays repairable by rescan.
 *
 * Guards — the probe runs AT MOST ONCE per item, with exactly ONE deliberate
 * (and self-limiting) exception, the TRANSIENT write failure below:
 * - `media_items.streams_probed_at` (migration 071) short-circuits every later
 *   request, INCLUDING items that genuinely have 1 audio + 0 subtitle streams.
 *   It is stamped on success, on probe failure (which degrades to the
 *   previously-stored rows but must not retry on every request), and on a
 *   DETERMINISTIC stream-write failure — one whose MySQL error says the same
 *   rows would fail identically forever, where retrying only burns one ~1s
 *   blocking ffprobe per request for nothing. The two reachable shapes were
 *   reproduced against real MySQL 8.0: err 1366, from an 11-byte `language` tag
 *   cut mid-character by {@see MediaScanner::streamLanguage()}'s BYTE-wise
 *   `substr($lang, 0, 10)`; and err 1054 on an un-migrated schema, which fails
 *   for EVERY item at once. (Err 1406 is NOT reachable from here — `title` and
 *   `language` are both truncated to their column widths before the INSERT.)
 *   It is NOT stamped when the write fails TRANSIENTLY (deadlock, lock-wait
 *   timeout, lost connection, connection pool exhausted): the rollback leaves
 *   the item holding exactly the rows that triggered the repair, so it must stay
 *   repairable — the next request retries, and the retry stops as soon as the
 *   transient condition clears. See {@see probeAndReplace()} and
 *   {@see isTransientWriteFailure()} for the classification and for the
 *   trade-off this makes.
 * - A missing file on disk skips the probe without stamping, so the item is
 *   retried once the file re-appears (e.g. a temporarily unmounted share).
 * - No usable {@see FfmpegRunner} (config/ffmpeg.php missing or unreadable)
 *   likewise skips without stamping, so a transiently broken probe config
 *   cannot permanently mask every item it touched.
 *
 * The media DETAIL endpoint ({@see \Phlix\Server\WebPortal\WebPortalRouter::getMediaItem()})
 * uses {@see ensureVideoCodecFor()} and only that. See its blast-radius note for why
 * no broader (audio/subtitle) trigger may be armed on that path.
 *
 * @since 0.74.0
 */
class StreamProbeBackfill
{
    /**
     * MySQL error numbers whose write failure is TRANSIENT: the identical
     * statement can succeed on a later request, so the item must stay
     * unstamped/repairable.
     *
     * Deliberately a CLOSED allow-list. Everything not named here is treated as
     * deterministic and stamped, which is master's behaviour — see the trade-off
     * note on {@see degradeAfterWriteFailure()}.
     */
    private const TRANSIENT_WRITE_ERRNOS = [
        1040, // ER_CON_COUNT_ERROR    — too many connections
        1205, // ER_LOCK_WAIT_TIMEOUT  — another transaction held the rows
        1213, // ER_LOCK_DEADLOCK      — this transaction was the deadlock victim
        1317, // ER_QUERY_INTERRUPTED  — KILL QUERY / statement timeout
        2002, // CR_CONNECTION_ERROR   — socket refused
        2003, // CR_CONN_HOST_ERROR    — host unreachable
        2006, // CR_SERVER_GONE_ERROR  — server closed the socket
        2013, // CR_SERVER_LOST        — connection lost mid-statement
    ];

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
     * Detail-endpoint trigger: probes ONLY when the item's VIDEO codec is
     * genuinely unknown, i.e. it has at least one `video` row and NOT ONE of
     * them carries a codec. Otherwise `$streams` is returned untouched with ZERO
     * I/O — no `stat()`, no probe, no write.
     *
     * WHY ONLY A VIDEO-CODEC TRIGGER (measured on the production library,
     * 116,325 items, media on sshfs-mounted shares):
     * - `streams_probed_at IS NULL` on 79,218 items. Almost all of them are
     *   audio-only or container rows with at most one audio row and no subtitle
     *   rows (61,130 `track`, 11,596 `album`, 4,679 `artist`, 1,378 `season`,
     *   434 `series`, 1 `movie`). Detail is hit on every item view — far more
     *   often than playback-info — so an audio/subtitle trigger on this path
     *   would arm 79,218 one-shot blocking ffprobes on the busiest read path,
     *   each one stalling the whole single-threaded Workerman worker for the
     *   duration of an sshfs read. That is an incident, not a slow endpoint.
     * - This trigger instead matches 0 items on that same library: every item
     *   there with a `video` row has at least one carrying a codec (including
     *   the 14 items that ALSO hold a codec-less junk `video` row, and the single
     *   unprobed `movie`). It is a closure for the failure mode the web player
     *   actually cares about, not a mass re-probe.
     *
     * The player reads the source's video codec out of this endpoint's
     * `streams[]` to pick direct-play vs transcode, and an UNKNOWN codec makes it
     * default to direct play — for which an undecodable video raises no `error`
     * event (black screen, audio playing, no recovery). The "is it known?" test
     * here is deliberately the SAME leniency the client uses
     * (`videoCodecFromStreams()` in @phlix/ui: first `video` row whose trimmed
     * `codec` is non-empty wins, junk rows skipped), so server and player never
     * disagree about which items are affected.
     *
     * A `video` row with no codec is direct evidence the stored set is wrong, so
     * a subtitle row or a second audio row does not veto the repair. The
     * `streams_probed_at` marker guard still applies, so the probe runs at most
     * once per item here too — the sole exception being an item whose stream
     * WRITE failed transiently, which is left unstamped on purpose so the repair
     * can be retried (see {@see probeAndReplace()}).
     *
     * @param array<string, mixed>             $item    Hydrated media_items row
     *                                                  (`id`, `path`, `streams_probed_at`).
     * @param array<int, array<string, mixed>> $streams The item's currently stored
     *                                                  `media_streams` rows.
     *
     * @return array<int, array<string, mixed>> Fresh rows after a successful
     *         backfill, otherwise `$streams` unchanged.
     */
    public function ensureVideoCodecFor(array $item, array $streams): array
    {
        // Cheapest possible short-circuit FIRST: on the measured library this
        // returns for 100% of items, so the detail endpoint gains no I/O at all
        // and the already-probed items get no slower.
        if (!self::videoCodecMissing($streams)) {
            return $streams;
        }

        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        if ($itemId === '') {
            return $streams;
        }

        // Probed-marker guard: the detail trigger's only one-shot guard. One blocking probe per item — except while a
        // TRANSIENT write failure keeps the item deliberately unstamped and therefore retryable (see
        // probeAndReplace()).
        if (!empty($item['streams_probed_at'])) {
            return $streams;
        }

        return $this->probeAndReplace($itemId, $item, $streams);
    }

    /**
     * Runs the one-shot probe and replaces the item's stored rows. Called by
     * {@see ensureVideoCodecFor()}; kept separate so the trigger and the
     * probe/persist/stamp/degrade behaviour stay independent.
     *
     * Callers MUST have applied the `streams_probed_at` guard already.
     *
     * @param string                           $itemId  Non-empty media item id.
     * @param array<string, mixed>             $item    Hydrated media_items row (`path`).
     * @param array<int, array<string, mixed>> $streams Currently stored rows (the degrade value).
     *
     * @return array<int, array<string, mixed>> Fresh rows on success, otherwise `$streams`.
     */
    private function probeAndReplace(string $itemId, array $item, array $streams): array
    {
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
                // later rescan stays idempotent with these rows — but issued as
                // ONE transaction ({@see ItemRepository::replaceStreams()}) so a
                // concurrent reader of this very item can never observe the
                // half-replaced set, and a mid-loop write failure leaves the
                // previously-stored rows intact instead of a partial set.
                // NOTE the blocking ffprobe above is deliberately OUTSIDE the
                // transaction: it takes ~1s on an sshfs share, and holding a
                // transaction (plus, on the unpooled path, the connection's
                // whole-transaction mutex) across it would stall every other
                // coroutine's DB work for that entire second.
                try {
                    $this->itemRepository->replaceStreams($itemId, $summary['streams']);
                } catch (\Throwable $e) {
                    // WRITE failure — its own handler, because the outer catch
                    // STAMPS unconditionally and the two write outcomes need
                    // OPPOSITE treatment. See degradeAfterWriteFailure().
                    return $this->degradeAfterWriteFailure($itemId, $e, $streams);
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
     * Handles a rolled-back stream WRITE, choosing between the two failure
     * modes that must NOT be treated alike.
     *
     * The problem this solves. `replaceStreams()` rolls back, so the item is
     * left holding EXACTLY the rows that triggered the repair and every guard
     * that led here evaluates the same way on the next request. Therefore:
     * - Stamping unconditionally (what master did) makes a repairable item
     *   permanently unrepairable: a stamped item is skipped by the detail trigger
     *   forever ({@see ensureVideoCodecFor()}) and nothing
     *   else rebuilds these rows — a rescan short-circuits in
     *   {@see MediaScanner::backfillItemSourceMetadata()} once duration +
     *   `source` are populated, and `scripts/backfill-streams.php` reselects only
     *   items with zero rows or a NULL `stream_index`.
     * - Never stamping makes a DETERMINISTIC failure re-run the ~1s blocking
     *   ffprobe on EVERY request, forever. On this stack that is a production
     *   stall, not a slow endpoint: `start.php` runs 14 resident single-threaded
     *   Workerman workers, media lives on sshfs shares, and the detail trigger has
     *   79,218 unstamped candidate items on the measured library.
     *
     * So the failure is classified ({@see isTransientWriteFailure()}):
     * - TRANSIENT (deadlock, lock-wait timeout, lost/refused connection, pool
     *   exhausted) → NOT stamped. The item stays repairable and the next request
     *   retries; the retry ends when the transient condition does.
     * - Anything else → treated as DETERMINISTIC and stamped, which bounds the
     *   probe to one per item exactly as master did.
     *
     * THE TRADE-OFF, stated rather than hidden: a deterministically-failing item
     * IS permanently masked from this backfill, and an error we failed to
     * recognise as transient would be masked too. That is why the classification
     * is a closed TRANSIENT allow-list rather than a deterministic deny-list —
     * an unrecognised code then behaves exactly as it did on master (stamped
     * once) instead of arming an unbounded probe loop, so this method is never
     * worse than master in either direction. The masking is made recoverable by
     * being LOUD: the deterministic branch logs at WARNING with the item id and
     * the MySQL errno, and an operator repairs it with
     * `UPDATE media_items SET streams_probed_at = NULL WHERE id = '…'` once the
     * underlying cause is fixed — measured against real MySQL 8.0, that is
     * err 1366 (a `language` tag cut mid-character) or err 1054 (a migration
     * that had not been run).
     *
     * @param string                           $itemId  The item whose write failed.
     * @param \Throwable                       $e       The re-thrown write failure.
     * @param array<int, array<string, mixed>> $streams Stored rows (the degrade value).
     *
     * @return array<int, array<string, mixed>> Always `$streams`: the write rolled
     *         back, so there is nothing new to read.
     */
    private function degradeAfterWriteFailure(string $itemId, \Throwable $e, array $streams): array
    {
        if (self::isTransientWriteFailure($e)) {
            $this->logger->debug(
                'Atomic stream replacement failed transiently; leaving item unstamped to retry',
                ['item_id' => $itemId, 'mysql_errno' => self::sqlErrorOf($e)[1], 'error' => $e->getMessage()]
            );
            return $streams;
        }

        $this->logger->warning(
            'Atomic stream replacement failed permanently; stamping the item so the blocking '
            . 'probe cannot re-run on every request (clear streams_probed_at to retry)',
            ['item_id' => $itemId, 'mysql_errno' => self::sqlErrorOf($e)[1], 'error' => $e->getMessage()]
        );
        try {
            $this->itemRepository->markStreamsProbed($itemId);
        } catch (\Throwable) {
            // Marker write failed too (e.g. pre-071 schema) — nothing else to do.
        }

        return $streams;
    }

    /**
     * Whether a write failure can plausibly succeed on a retry.
     *
     * Three independent signals, because the exception that arrives here has
     * usually been re-wrapped on the way:
     * 1. A message starting `pool exhausted:` —
     *    {@see \Phlix\Common\Database\PooledMySQLConnection} raises a plain
     *    `\RuntimeException` with that prefix when no connection is free; it
     *    reaches this handler because the pool leases on the first statement,
     *    which for `replaceStreams()` is its `beginTrans()`.
     * 2. A MySQL errno in {@see TRANSIENT_WRITE_ERRNOS}.
     * 3. A SQLSTATE class that is transient by the standard: `08xxx`
     *    (connection exception) and `40001` (serialization failure / deadlock).
     *
     * The errno/SQLSTATE come from {@see sqlErrorOf()}, which needs BOTH of its
     * readers because the two failure families arrive in mutually exclusive
     * shapes (measured on MySQL 8.0.46, not inferred):
     * - STATEMENT failures are re-wrapped by
     *   {@see \Phlix\Common\Database\PhlixMySQLConnection::execute()} into
     *   `new \PDOException('SQL:…' . $e->getMessage(), …)` for every code except
     *   2006/2013, and a freshly constructed `PDOException` carries no
     *   `errorInfo` — so only the message survives.
     * - CONNECT failures are re-thrown VERBATIM, keeping `errorInfo`, but PDO
     *   words them `SQLSTATE[08004] [1040] Too many connections` — no colon
     *   after the bracket — so only `errorInfo` survives.
     * The exception chain is walked so a future wrapper cannot hide the cause.
     */
    private static function isTransientWriteFailure(\Throwable $e): bool
    {
        for ($cursor = $e; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            // PooledMySQLConnection::acquire()/lease() — a load condition.
            // Anchored at the start on purpose: a PDOException's message begins
            // with `SQL:` + the failing statement's own parameter values, so a
            // substring match could be spoofed by row data.
            if (str_starts_with($cursor->getMessage(), 'pool exhausted:')) {
                return true;
            }
            [$sqlState, $errno] = self::sqlErrorOf($cursor);
            if (in_array($errno, self::TRANSIENT_WRITE_ERRNOS, true)) {
                return true;
            }
            if ($sqlState === '40001' || str_starts_with($sqlState, '08')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts `[SQLSTATE, MySQL errno]` from a throwable, `['', 0]` when it
     * carries neither.
     *
     * BOTH readers are load-bearing; neither is a fallback for the other,
     * because PDO words its two failure families differently and the transport
     * preserves a different half of each (all four shapes reproduced against
     * MySQL 8.0.46):
     *
     * 1. `PDOException::$errorInfo`, read first. It is the ONLY signal for a
     *    CONNECT failure, whose message reads
     *    `SQLSTATE[08004] [1040] Too many connections` /
     *    `SQLSTATE[HY000] [2002] Connection refused` — a bracketed errno with no
     *    colon after `]`, which the pattern below deliberately does not match.
     *    Those arrive here VERBATIM (so `errorInfo` is intact) because
     *    `PooledMySQLConnection::acquire()` re-throws its `rawFactory()`
     *    failure and workerman/mysql's `beginTrans()` re-throws anything that is
     *    not 2006/2013 — and `replaceStreams()`'s `beginTrans()` is the first
     *    statement of the write, so a connect failure is exactly what a caller
     *    sees when the server is briefly unreachable. Dropping this reader
     *    silently classifies 1040/2002/2003 as DETERMINISTIC and stamps the
     *    item, permanently masking it for a condition that clears by itself.
     * 2. The message, parsed as `SQLSTATE[40001]: Serialization failure: 1213
     *    Deadlock found …`. It is the ONLY signal for a STATEMENT failure,
     *    because `PhlixMySQLConnection::execute()` re-wraps those in a fresh
     *    `PDOException`, which carries no `errorInfo` at all. The LAST match
     *    wins: that re-wrap prefixes the failing SQL — parameter values
     *    included — so an earlier "match" could only come from row data, never
     *    from PDO.
     *
     * @return array{0: string, 1: int}
     */
    private static function sqlErrorOf(\Throwable $e): array
    {
        if ($e instanceof \PDOException) {
            $info = $e->errorInfo;
            if (is_array($info) && isset($info[0], $info[1]) && is_scalar($info[0]) && is_numeric($info[1])) {
                return [(string) $info[0], (int) $info[1]];
            }
        }

        $matches = [];
        $found = preg_match_all(
            '/SQLSTATE\[([0-9A-Za-z]{5})\]:[^:]*:\s*(\d+)\b/',
            $e->getMessage(),
            $matches
        );
        if (is_int($found) && $found > 0) {
            $last = $found - 1;
            return [$matches[1][$last] ?? '', (int) ($matches[2][$last] ?? '0')];
        }

        return ['', 0];
    }

    /**
     * Whether the item's VIDEO codec is unknown: it has at least one `video`
     * row and none of them carries a non-blank codec.
     *
     * FALSE when there is no `video` row at all — that is an audio/book/photo
     * item or a `series`/`season` container row whose `path` is a DIRECTORY. On
     * the measured production library that excludes 77,405 track/album/artist
     * rows plus 1,812 series/season rows (77 % of the whole library) from ever
     * reaching a blocking ffprobe of a path that is not even a media file.
     *
     * Mirrors the client's `videoCodecFromStreams()` leniency (case-insensitive
     * trimmed `stream_type`, trimmed `codec`, junk entries skipped) so both ends
     * classify the same rows as unknown.
     *
     * @param array<int, array<string, mixed>> $streams Stored `media_streams` rows.
     */
    private static function videoCodecMissing(array $streams): bool
    {
        $sawVideoRow = false;
        foreach ($streams as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $type = $stream['stream_type'] ?? null;
            if (!is_string($type) || strtolower(trim($type)) !== 'video') {
                continue;
            }
            $sawVideoRow = true;
            $codec = $stream['codec'] ?? null;
            if (is_string($codec) && trim($codec) !== '') {
                return false; // a usable codec exists — nothing to repair
            }
        }

        return $sawVideoRow;
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
