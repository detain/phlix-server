<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Per-worker registry of detached on-demand ffmpeg segment-encode process
 * groups, keyed by an opaque cancel key (the final segment path) and optionally
 * grouped under a cancel-group id (the relay channel/request id).
 *
 * SV-4.2 ([S-F23]): the scrub-storm orphan-CPU problem. On-demand segment
 * encodes are launched detached and previously ran to completion even after the
 * client abandoned the request (a frantic seek launches — and abandons — many
 * segment encodes). This registry lets a cancel/disconnect hook kill the encode
 * a specific relayed request launched ({@see killGroup()}), so an ABANDONED
 * encode stops burning CPU immediately instead of running to the end.
 *
 * Two-level keying (SV-4.2 fix):
 *   - The PRIMARY key is the final segment path ($final). The transcode poll
 *     loop that launched the encode manages this key in a `finally`
 *     ({@see release()} on publish, {@see releaseAfterWaitTimeout()} otherwise).
 *   - An optional GROUP id (the relay channel/request id, threaded via
 *     {@see \Phlix\Server\Http\RequestContext::getRelayCancelGroup()}) maps that
 *     channel to the segment key(s) it launched, so
 *     {@see \Phlix\Hub\RelayConsumer::onHttpCancel()} can kill by channel id.
 *
 * Kill semantics (SV-4.2 findings #1 & #4):
 *   - Segment encodes are launched under `setsid`, so the tracked PID is a
 *     process-GROUP leader (PGID == PID). The default signal sender targets the
 *     whole group (negative PID), reaching the ffmpeg grandchild directly rather
 *     than only the `timeout`/`sh` wrapper.
 *   - A signalled encode cannot run its own atomic-publish `|| rm` cleanup, so
 *     on any kill the orphaned `.part-*` temp is removed (via the temp cleaner)
 *     — otherwise the cap/dedup globs would count a dead encode as in-flight.
 *
 * Wait-timeout semantics (SV-4.2 finding #2): a single request's poll wait
 * timing out is NOT abandonment. {@see releaseAfterWaitTimeout()} never kills a
 * still-running encode, so a slow-but-wanted software 4K/HEVC transcode is left
 * to finish and publish for the retrying requester. A LIVE encode is handed to the
 * watchdog ({@see sweep()}) rather than forgotten, so a stalled one still has an
 * owner. A DEAD one has its temp cleaned right away. Genuine abandonment kills
 * promptly via the cancel path.
 *
 * Watchdog (stall and wall-clock): {@see sweep()} reaps a live encode whose
 * progress has not advanced for {@see configureWatchdog()}'s stall window, or that
 * exceeds its wall-clock cap. The progress signal is the encode's `-progress` file
 * for a CMAF encode (`out_time_us`, or that file's mtime while `out_time` is still
 * N/A), and the temp family's byte size for an MPEG-TS encode, whose temp grows
 * continuously. A CMAF encode writes its fragment only at the end, so its temp
 * family does not grow during the encode, and byte size is not a usable signal for
 * it. A reaped encode's slot and `.part` temp are released once the process is
 * dead. The sweep is non-blocking: SIGTERM now, SIGKILL on a later sweep.
 *
 * PID identity (Linux): the liveness probe is `posix_kill($pid, 0)`, which reports
 * a zombie as alive and cannot tell a reused PID from the encode it launched. So on
 * Linux each PID's `/proc/<pid>/stat` start time (field 22) is recorded at
 * registration. A PID whose state is `Z`, or whose start time has changed, is
 * dead, and no signal is sent to a PID whose identity no longer matches. Where
 * `/proc` is unavailable (non-Linux) the check is skipped and the behaviour is the
 * plain liveness probe, as before.
 *
 * Shared-encode landmine guard (SV-4.2-disconnect): an on-demand encode is
 * deduplicated by its output path, so a SECOND concurrent requester piggybacks
 * on the launcher's in-flight encode instead of spawning its own. If the
 * launcher is then cancelled, killing the shared encode would 404 the
 * piggybacker who still wants the segment. {@see kill()} therefore consults an
 * optional waiter guard ({@see setWaiterGuard()}, wired from
 * {@see \Phlix\Media\Transcoding\TranscodeManager::hasOtherWaiter()}) and
 * DEFERS the kill (leaving the entry tracked) whenever another waiter is still
 * present. A deferred encode is NOT re-killed later: it keeps running for the
 * remaining waiter(s) and completes + publishes normally, and its registry entry
 * is then dropped by the launcher's own wait-timeout release (or, for a genuinely
 * stuck encode, the `timeout <n>` wrapper). With no other waiter — the common
 * case — the kill signals + reaps exactly as before.
 *
 * On a genuine (non-deferred) reap, {@see kill()} also invokes an optional reap
 * callback ({@see setReapCallback()}, SV-4.2-disconnect F1) so the owner
 * ({@see TranscodeManager}) can invalidate its dedup RESERVATION for the reaped
 * segment — otherwise the next requester would dedup onto the killed encode and
 * 404 until the reservation self-heals. That reservation invalidation, NOT this
 * guard, is what closes the shared-encode dedup gap; the guard only narrows the
 * live-piggybacker 404 window.
 *
 * Known per-worker bounds (documented, not fixed here):
 *  - Untracked encodes. An encode launched before this worker's last restart or
 *    deploy is not in this registry. No stall or wall-clock reap applies to it, so
 *    it holds a cap slot until the `timeout` wrapper (`ffmpeg.transcode_timeout`,
 *    7200 s by default) fires. Startup does not adopt or reap such temps. Doing so
 *    needs the PID and its start time, and neither is persisted. Reaping by temp age
 *    alone could kill a sibling worker's live encode, so no cheap safe path exists.
 *  - Waiters are per worker. {@see \Phlix\Media\Transcoding\TranscodeManager::hasOtherWaiter()}
 *    counts only the waiters in this worker. If a launcher is cancelled in worker A
 *    while a piggybacker in worker B waits on the same segment, the guard cannot see
 *    B, and the shared encode is killed, so B gets a 404. Closing that gap needs a
 *    cross-worker waiter marker in the shared segment tree (for example a
 *    `{final}.waiter-<pid>-<nonce>` created on join, removed in `finally`, and
 *    bounded by a TTL), consulted by hasOtherWaiter through a glob.
 *
 * Resident-memory discipline (this is Workerman, NOT php-fpm): the maps are
 * bounded — every caller MUST {@see release()}, {@see releaseAfterWaitTimeout()},
 * {@see kill()} or {@see killGroup()} its key (the transcode path does so in a
 * `finally`). {@see registeredKeyCount()} is exposed so leaks are observable in
 * tests, and dropping a key always tears down its group links and identity records.
 *
 * Coroutine-safety: killing waits between SIGTERM and SIGKILL using a
 * coroutine-yielding sleep when inside a Swoole coroutine (`getCid() > 0`) and a
 * short blocking sleep otherwise, so it never blocks the event loop. The signal
 * send, liveness probe, `/proc` reader, progress probe and temp cleaner are
 * injectable for tests (no real processes spawned, no real files removed).
 *
 * @since SV-4.2
 */
final class SegmentProcessRegistry
{
    /** Tail of a progress file read per probe. A progress block is ~0.3 KB. */
    private const PROGRESS_TAIL_BYTES = 8192;

    /**
     * The shipped wall-clock cap in seconds (see {@see $maxWallSeconds}). Public so
     * the manager can bound a temp join without holding a registry instance.
     */
    public const DEFAULT_MAX_WALL_SECONDS = 300.0;

    /**
     * Cancel key (segment path) => list of tracked OS process-group-leader PIDs.
     *
     * @var array<string, array<int, int>>
     */
    private array $pids = [];

    /**
     * Cancel-group id (relay channel/request id) => list of cancel keys it owns.
     *
     * @var array<string, array<int, string>>
     */
    private array $keysByGroup = [];

    /**
     * Reverse index: cancel key => its cancel-group id (for O(1) teardown).
     *
     * @var array<string, string>
     */
    private array $groupOfKey = [];

    /**
     * Cancel key (segment path) => the exact `.part-<hex>` temp path(s) that THIS
     * launcher created for that key. On kill / dead-release only these specific
     * temps are removed — never the whole `{$final}.part-*` family — so a sibling
     * worker's LIVE temp for the same final segment path is never destroyed
     * (SV-4.2 re-review Low: `TranscodeManager` deliberately tolerates cross-worker
     * duplicate encodes of the same `$final`, each writing a DISTINCT temp).
     *
     * @var array<string, array<int, string>>
     */
    private array $tmpsByKey = [];

    /**
     * Cancel key => (PID => /proc start time, or null when /proc was unreadable at
     * registration). Read before any signal, so a reused PID is never signalled.
     *
     * @var array<string, array<int, int|null>>
     */
    private array $starts = [];

    private LoggerInterface $logger;

    /**
     * Signal sender: fn(int $pid, int $signal): void. Defaults to signalling the
     * process GROUP (negative PID) via posix_kill / `kill` fallback. Overridable
     * in tests so no real signals are sent.
     *
     * @var callable(int, int): void
     */
    private $signalSender;

    /**
     * Liveness probe: fn(int $pid): bool. Defaults to posix_kill($pid, 0) /
     * /proc probe against the group-leader PID. Overridable in tests. A zombie
     * reports alive here, so {@see isLive()} also checks `/proc`.
     *
     * @var callable(int): bool
     */
    private $isAlive;

    /**
     * `/proc/<pid>/stat` reader: fn(int $pid): ?string (null = unreadable). Defaults
     * to reading the real file (null on non-Linux). Overridable in tests.
     *
     * @var callable(int): (string|null)
     */
    private $procStat;

    /**
     * Temp cleaner: fn(string $tmp): void. Removes the ONE specific
     * `.part-<hex>` atomic-write temp path that this launcher created, after its
     * encode was killed (or died without publishing) — a signalled encode never
     * runs its own `|| rm` cleanup. Defaults to `@unlink($tmp)` guarded by
     * `is_string`/`file_exists`. It is passed the launcher's exact temp path (as
     * recorded via {@see register()}), NOT the `{$final}.part-*` family, so a
     * sibling worker's live temp for the same final segment is never destroyed
     * (SV-4.2 re-review Low). Overridable in tests.
     *
     * @var callable(string): void
     */
    private $tempCleaner;

    /**
     * Optional waiter guard: fn(string $key): bool (SV-4.2-disconnect landmine
     * guard). When set, {@see kill()} consults it BEFORE signalling — if it
     * returns true, another client is still actively waiting on that exact
     * segment (a piggybacker that joined the launcher's in-flight encode rather
     * than launching its own), so the shared encode MUST NOT be killed: doing so
     * would 404 the other waiter. The kill is then DEFERRED (the entry is left
     * fully tracked) so the encode keeps running for the remaining waiter(s); it
     * is NOT re-killed later — it completes + publishes normally and its entry is
     * dropped by the launcher's own wait-timeout release (`timeout <n>` backstops
     * a genuinely stuck one).
     *
     * Wired at container-build time from the {@see TranscodeManager} per-worker
     * singleton ({@see \Phlix\Media\Transcoding\TranscodeManager::hasOtherWaiter()})
     * via {@see setWaiterGuard()}, which breaks the registry↔manager DI cycle.
     * Null (the default) means "no guard" — every kill signals immediately,
     * exactly preserving the pre-SV-4.2-disconnect behavior (all direct-construct
     * callers, including the unit tests, run without one).
     *
     * @var (callable(string): bool)|null
     */
    private $hasOtherWaiter = null;

    /**
     * Optional reap callback: fn(string $key): void (SV-4.2-disconnect F1). When
     * set, {@see kill()} invokes it on the SIGNALLED branch ONLY — after the
     * waiter-guard defer check has passed and a genuine reap (PIDs to signal) is
     * about to happen — so the owner ({@see TranscodeManager}) can invalidate its
     * dedup RESERVATION for the reaped segment. Without this, a requester arriving
     * just after a disconnect-kill would dedup onto the killed-never-to-publish
     * encode and 404 until the reservation self-heals (up to ~5s). It is fired
     * BEFORE the coroutine-yielding SIGTERM→SIGKILL wait so a requester that
     * re-launches during the grace period re-reserves a FRESH slot rather than
     * having its reservation cleared out from under it. NOT fired on the deferred
     * branch (the encode is still wanted by a piggybacker and keeps publishing).
     *
     * Wired at container-build time from the {@see TranscodeManager} per-worker
     * singleton ({@see \Phlix\Media\Transcoding\TranscodeManager::invalidateReservation()})
     * via {@see setReapCallback()} — the mirror of the waiter-guard wiring. Null
     * (the default) means "no reap callback" (every direct-construct caller,
     * including the unit tests, runs without one).
     *
     * @var (callable(string): void)|null
     */
    private $onReap = null;

    /**
     * Seconds to wait for graceful SIGTERM exit before escalating to SIGKILL.
     * Deliberately short: segment encodes are small and disposable.
     */
    private float $gracePeriodSeconds;

    /**
     * Per-key progress of a TRACKED launch (cancel key => progress), read by the
     * watchdog in {@see sweep()}. `startedNs` is the registration instant, `lastToken`
     * the progress token last observed (see {@see observeProgress()}), and `lastGrowNs`
     * the last instant that token changed. All monotonic (hrtime). Dropped with the key.
     *
     * @var array<string, array{startedNs: int, lastToken: int, lastGrowNs: int}>
     */
    private array $progress = [];

    /**
     * Launches the watchdog now owns. A launch lands here when its poll gave up while
     * the encode was still alive ({@see releaseAfterWaitTimeout()}), or when the
     * watchdog itself judged it stalled or over the wall-clock cap. A slow-but-
     * growing encode is never moved here by the poll alone, so SV-4.2 finding #2
     * still holds. An orphan is removed when its process is dead, after its temps
     * are cleaned. Bounded by the watchdog's own wall-clock and stall rules.
     *
     * @var list<array{key: string, pids: array<int, int>, starts: array<int, int|null>,
     *     tmps: array<int, string>, startedNs: int, lastToken: int, lastGrowNs: int,
     *     termNs: int|null}>
     */
    private array $orphans = [];

    /**
     * Seconds without the encode's progress advancing before it is declared stalled
     * and killed. A healthy CMAF encode advances `out_time_us` about every second
     * (measured: 0.2–2 s steps on 360p and 720p `veryslow`, 1 thread), and its
     * progress file is rewritten every 0.5 s. During the pre-first-packet lookahead
     * `out_time_us` is N/A, and the file's mtime carries the signal. 30 s is therefore
     * 15–60× the healthy cadence. It is also the order of a hung sshfs read, which is
     * the failure this rule exists for.
     */
    private float $stallSeconds = 30.0;

    /**
     * Wall-clock cap, in seconds, for one on-demand segment encode. Measured: a 6 s
     * 720p `veryslow` encode on one thread took 17 s on this box at load ~15. The
     * slowest legitimate case is 4K at one thread, about 9× the pixels, so roughly
     * 150 s. 300 s covers that with 2× headroom for contention. The cap only catches
     * an encode that keeps progressing but will not finish. Stalls are handled by
     * {@see $stallSeconds}, and the `timeout` wrapper stays the last backstop.
     */
    private float $maxWallSeconds = self::DEFAULT_MAX_WALL_SECONDS;

    /**
     * Progress token of a tracked launch: fn(array<int, string> $tmps): int. Defaults
     * to the CMAF progress signal per temp (`out_time_us`, or the progress file's mtime
     * while out_time is N/A), falling back to the byte total of the temp family for an
     * MPEG-TS temp that has no progress file. Overridable in tests. Growth means the
     * token CHANGED, which is the same test for both signals.
     *
     * @var callable(array<int, string>): int
     */
    private $progressProbe;

    /**
     * @param callable(int, int): void|null $signalSender
     * @param callable(int): bool|null      $isAlive
     * @param callable(string): void|null   $tempCleaner
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?callable $signalSender = null,
        ?callable $isAlive = null,
        float $gracePeriodSeconds = 0.5,
        ?callable $tempCleaner = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->signalSender = $signalSender ?? self::defaultSignalSender();
        $this->isAlive = $isAlive ?? self::defaultLivenessProbe();
        $this->procStat = self::defaultProcStatReader();
        $this->tempCleaner = $tempCleaner ?? self::defaultTempCleaner();
        $this->gracePeriodSeconds = $gracePeriodSeconds;
        $this->progressProbe = self::defaultProgressProbe();
    }

    /**
     * Configure the stall and wall-clock watchdog that {@see sweep()} applies to every
     * detached segment encode. Non-positive values keep the shipped default, so a
     * bad config can never turn the watchdog off or make it kill everything.
     *
     * @param float $stallSeconds   Seconds without progress before a live encode is killed.
     * @param float $maxWallSeconds Wall-clock cap for one encode, in seconds.
     */
    public function configureWatchdog(float $stallSeconds, float $maxWallSeconds): void
    {
        if ($stallSeconds > 0.0) {
            $this->stallSeconds = $stallSeconds;
        }
        if ($maxWallSeconds > 0.0) {
            $this->maxWallSeconds = $maxWallSeconds;
        }
    }

    /**
     * The wall-clock cap in seconds. The manager uses it to bound how long a
     * temp can be joined as live (see {@see \Phlix\Media\Transcoding\TranscodeManager}).
     */
    public function maxWallSeconds(): float
    {
        return $this->maxWallSeconds;
    }

    /**
     * Override the progress probe used by the watchdog. Null restores the default
     * (CMAF progress file, or the byte total of an MPEG-TS temp family). Exists for tests.
     *
     * @param (callable(array<int, string>): int)|null $progressProbe
     */
    public function setProgressProbe(?callable $progressProbe): void
    {
        $this->progressProbe = $progressProbe ?? self::defaultProgressProbe();
    }

    /**
     * Override the `/proc/<pid>/stat` reader used for PID identity and zombie checks.
     * Null restores the default (the real file; null on non-Linux). Exists for tests,
     * which pass a fake process table.
     *
     * @param (callable(int): (string|null))|null $procStat
     */
    public function setProcStatReader(?callable $procStat): void
    {
        $this->procStat = $procStat ?? self::defaultProcStatReader();
    }

    /**
     * Wire the waiter guard consulted by {@see kill()} (SV-4.2-disconnect
     * landmine guard). See {@see $hasOtherWaiter} for the full rationale. Passing
     * null clears it (restores unconditional signalling).
     *
     * @param (callable(string): bool)|null $hasOtherWaiter fn($key): bool — true
     *        when a waiter OTHER than the one being cancelled is still present on
     *        that segment, so its shared encode must not be killed.
     */
    public function setWaiterGuard(?callable $hasOtherWaiter): void
    {
        $this->hasOtherWaiter = $hasOtherWaiter;
    }

    /**
     * Wire the reap callback invoked by {@see kill()} on a genuine (non-deferred)
     * reap (SV-4.2-disconnect F1). See {@see $onReap} for the full rationale.
     * Passing null clears it.
     *
     * @param (callable(string): void)|null $onReap fn($key): void — invalidate the
     *        owner's dedup reservation for a reaped segment so the next requester
     *        re-launches instead of deduping onto the killed encode.
     */
    public function setReapCallback(?callable $onReap): void
    {
        $this->onReap = $onReap;
    }

    /**
     * Track a launched detached encode PID under the given cancel key, and
     * (optionally) record that key under a cancel group so a group kill can
     * find it.
     *
     * A key may accumulate more than one PID (e.g. an audio + video rendition
     * for the same logical request); all are killed together.
     *
     * @param string      $key   Opaque cancel key (segment path).
     * @param int         $pid   OS process-group-leader id; non-positive ignored.
     * @param string|null $group Optional cancel-group id (relay channel/request).
     * @param string|null $tmp   The exact `.part-<hex>` temp path THIS launcher
     *                           created for the encode. Recorded so kill /
     *                           dead-release removes only this specific temp, not
     *                           the whole `{$final}.part-*` family (SV-4.2
     *                           re-review Low). Null/empty = nothing to clean.
     */
    public function register(string $key, int $pid, ?string $group = null, ?string $tmp = null): void
    {
        if ($key === '' || $pid <= 0) {
            return;
        }
        $this->pids[$key][] = $pid;
        // PID identity, read now while the launch is this process (see class docblock).
        $this->starts[$key][$pid] = $this->startTimeOf($pid);

        if ($tmp !== null && $tmp !== '') {
            $this->tmpsByKey[$key][] = $tmp;
        }

        // The watchdog's clock starts at the first registration for this key.
        $now = (int) hrtime(true);
        $this->progress[$key] ??= ['startedNs' => $now, 'lastToken' => 0, 'lastGrowNs' => $now];

        if ($group !== null && $group !== '' && !isset($this->groupOfKey[$key])) {
            $this->keysByGroup[$group][] = $key;
            $this->groupOfKey[$key] = $group;
        }
    }

    /**
     * Drop the tracked PIDs for a key WITHOUT killing them (the encode finished
     * on its own and published). Tears down group links. Safe for unknown keys.
     *
     * @param string $key Cancel key to forget.
     */
    public function release(string $key): void
    {
        $this->drop($key);
    }

    /**
     * Wait-timeout release for the request that launched the encode: stop
     * tracking it but do NOT kill it — a single request's poll wait timing out
     * is not abandonment, and a slow-but-wanted encode must be left to finish
     * and publish for a retrying requester (SV-4.2 finding #2). If the encode
     * already DIED without publishing, remove its orphaned `.part-*` temp so the
     * cap/dedup globs don't count a corpse (SV-4.2 finding #1); a still-running
     * encode is left completely alone (its live temp must not be deleted).
     *
     * @param string $key Cancel key (segment path) to release.
     */
    public function releaseAfterWaitTimeout(string $key): void
    {
        $tmps = $this->tmpsByKey[$key] ?? [];

        if ($this->anyAlive($this->pids[$key] ?? [], $this->startsFor($key))) {
            // A poll timing out is not abandonment, so the encode is not killed here.
            // But forgetting a LIVE encode would leave it with no owner: nothing could
            // kill a stalled one until the 7200 s wrapper. Hand it to the watchdog
            // instead, which reaps it on stall or wall-clock overrun and removes it once
            // it is dead.
            $this->orphan($key);
            return;
        }

        $this->drop($key);

        // Dead (or never tracked) without publishing → clean THIS launcher's
        // own corpse temp only (never the `{$final}.part-*` family, so a
        // sibling worker's live temp for the same final path is untouched —
        // SV-4.2 re-review Low). A naturally-exiting encode already ran its
        // `|| rm`, so this is a harmless no-op then; the case that matters is
        // the `timeout` backstop signalling it (which skips the `|| rm`).
        $this->cleanTemps($tmps);
    }

    /**
     * Watchdog pass over every detached segment encode this worker launched: reaps
     * the stalled ones, the ones over the wall-clock cap, and the orphans that
     * have died. It never blocks. SIGTERM is sent now, and SIGKILL is sent on a
     * later pass once the grace period has elapsed. The temp family is removed
     * only after the process is dead. Driven by a per-worker timer and also,
     * throttled, from the cap check in TranscodeManager.
     *
     * Rules, per launch:
     *  - dead (or a zombie, or a reused PID) → temps cleaned, entry dropped (a stalled
     *    encode's slot and .part are freed here);
     *  - alive and past the wall-clock cap → reaped;
     *  - alive and the progress token has not changed for {@see configureWatchdog()}'s
     *    stall window → reaped. Only applies when a temp was registered, so a launch
     *    we cannot observe is never killed for silence.
     * A reaped TRACKED launch also has its dedup reservation invalidated through the
     * reap callback, exactly as {@see kill()} does, so the next requester re-launches.
     *
     * @param int|null $nowNs Monotonic instant to judge against (hrtime). Null = now.
     *
     * @return int Number of encodes newly signalled (SIGTERM) by this pass.
     */
    public function sweep(?int $nowNs = null): int
    {
        $now = $nowNs ?? (int) hrtime(true);
        $signalled = 0;

        foreach (array_keys($this->pids) as $key) {
            $pids = $this->pids[$key] ?? [];
            if ($pids === []) {
                continue;
            }
            if (!$this->anyAlive($pids, $this->startsFor($key))) {
                $tmps = $this->tmpsByKey[$key] ?? [];
                $this->drop($key);
                $this->cleanTemps($tmps);
                continue;
            }

            $progress = $this->progress[$key] ?? null;
            if ($progress === null) {
                continue;
            }
            $tmps = $this->tmpsByKey[$key] ?? [];
            $lastToken = $progress['lastToken'];
            $lastGrowNs = $progress['lastGrowNs'];
            $this->observeProgress($tmps, $lastToken, $lastGrowNs, $now);
            $this->progress[$key] = [
                'startedNs' => $progress['startedNs'],
                'lastToken' => $lastToken,
                'lastGrowNs' => $lastGrowNs,
            ];

            $reason = $this->watchdogReason($progress['startedNs'], $lastGrowNs, $tmps !== [], $now);
            if ($reason === null) {
                continue;
            }

            $this->logger->warning('SegmentProcessRegistry: reaping stalled segment encode', [
                'key' => $key,
                'reason' => $reason,
                'pids' => $pids,
            ]);
            // Invalidate the dedup reservation BEFORE the signal, exactly as kill()
            // does, so a requester arriving now re-launches instead of deduping onto
            // the encode about to die.
            if ($this->onReap !== null) {
                ($this->onReap)($key);
            }
            $signalled += $this->signalOrphanForStall($this->orphan($key), $now);
        }

        $keep = [];
        foreach ($this->orphans as $orphan) {
            if (!$this->anyAlive($orphan['pids'], $orphan['starts'])) {
                $this->cleanTemps($orphan['tmps']);
                continue; // dead → gone
            }

            if ($orphan['termNs'] !== null) {
                // Already signalled: escalate to SIGKILL once the grace has elapsed.
                // Non-blocking, so a pass never waits here.
                if ($now - $orphan['termNs'] >= (int) ($this->gracePeriodSeconds * 1_000_000_000)) {
                    foreach ($orphan['pids'] as $pid) {
                        $start = $orphan['starts'][$pid] ?? null;
                        if ($this->isLive($pid, $start)) {
                            $this->signalPid($pid, self::sigKill(), $start);
                        }
                    }
                }
                $keep[] = $orphan;
                continue;
            }

            $lastToken = $orphan['lastToken'];
            $lastGrowNs = $orphan['lastGrowNs'];
            $this->observeProgress($orphan['tmps'], $lastToken, $lastGrowNs, $now);
            $orphan['lastToken'] = $lastToken;
            $orphan['lastGrowNs'] = $lastGrowNs;

            $reason = $this->watchdogReason($orphan['startedNs'], $lastGrowNs, $orphan['tmps'] !== [], $now);
            if ($reason !== null) {
                $this->logger->warning('SegmentProcessRegistry: reaping orphaned segment encode', [
                    'key' => $orphan['key'],
                    'reason' => $reason,
                    'pids' => $orphan['pids'],
                ]);
                $orphan['termNs'] = $now;
                foreach ($orphan['pids'] as $pid) {
                    $this->signalPid($pid, self::sigTerm(), $orphan['starts'][$pid] ?? null);
                }
                $signalled++;
            }
            $keep[] = $orphan;
        }
        $this->orphans = $keep;

        return $signalled;
    }

    /**
     * Number of live encodes the watchdog owns past their poll (see {@see sweep()}).
     * Exposed for observability and leak assertions.
     */
    public function orphanCount(): int
    {
        return count($this->orphans);
    }

    /**
     * Move a TRACKED launch to the watchdog's ownership: its pids, temps, identity and
     * progress leave the keyed maps (freeing the key for a fresh launch) and become one
     * orphan record. The group link is torn down by drop(), so a later killGroup() no
     * longer reaches it, which matches the wait-timeout release it replaces.
     *
     * @param string $key Tracked cancel key.
     *
     * @return int|null Index of the new orphan record, or null when there was nothing to move.
     */
    private function orphan(string $key): ?int
    {
        $pids = $this->pids[$key] ?? [];
        $tmps = $this->tmpsByKey[$key] ?? [];
        $progress = $this->progress[$key] ?? null;
        $starts = $this->startsFor($key);
        $this->drop($key);

        if ($pids === [] || $progress === null) {
            return null;
        }

        $this->orphans[] = [
            'key' => $key,
            'pids' => $pids,
            'starts' => $starts,
            'tmps' => $tmps,
            'startedNs' => $progress['startedNs'],
            'lastToken' => $progress['lastToken'],
            'lastGrowNs' => $progress['lastGrowNs'],
            'termNs' => null,
        ];

        return array_key_last($this->orphans);
    }

    /**
     * Send SIGTERM to the orphan at the given index for a stall/wall reap, marking it
     * as signalled so the next pass escalates it to SIGKILL if it outlives the grace.
     *
     * @param int|null $index Index into {@see $orphans}; null is a no-op.
     *
     * @return int 1 when the orphan was signalled, 0 otherwise.
     */
    private function signalOrphanForStall(?int $index, int $now): int
    {
        if ($index === null || !isset($this->orphans[$index])) {
            return 0;
        }
        $this->orphans[$index]['termNs'] = $now;
        foreach ($this->orphans[$index]['pids'] as $pid) {
            $this->signalPid($pid, self::sigTerm(), $this->orphans[$index]['starts'][$pid] ?? null);
        }

        return 1;
    }

    /**
     * Fold the current progress token into the watchdog pair. Growth means the token
     * CHANGED. Every component is non-decreasing (bytes, out_time, mtime), so a change
     * is progress. A change from the mtime fallback to out_time is also taken as
     * progress, which is harmless.
     *
     * @param array<int, string> $tmps
     */
    private function observeProgress(array $tmps, int &$lastToken, int &$lastGrowNs, int $now): void
    {
        if ($tmps === []) {
            return;
        }
        $token = ($this->progressProbe)($tmps);
        if ($token !== $lastToken) {
            $lastToken = $token;
            $lastGrowNs = $now;
        }
    }

    /**
     * The watchdog verdict for one live launch, or null to leave it alone.
     *
     * @return string|null 'wall-clock', 'stall', or null
     */
    private function watchdogReason(int $startedNs, int $lastGrowNs, bool $observable, int $now): ?string
    {
        if ($now - $startedNs > (int) ($this->maxWallSeconds * 1_000_000_000)) {
            return 'wall-clock';
        }
        if ($observable && $now - $lastGrowNs > (int) ($this->stallSeconds * 1_000_000_000)) {
            return 'stall';
        }

        return null;
    }

    /**
     * @param array<int, int>      $pids
     * @param array<int, int|null> $starts PID => recorded start time.
     */
    private function anyAlive(array $pids, array $starts = []): bool
    {
        foreach ($pids as $pid) {
            if ($this->isLive($pid, $starts[$pid] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The liveness verdict for one tracked PID. The injected probe says the PID exists.
     * On Linux, `/proc` then rules out a zombie (state `Z`, which posix_kill still calls
     * alive) and a reused PID (a start time other than the recorded one). With no
     * `/proc` view the probe alone decides, which is the pre-existing behaviour.
     */
    private function isLive(int $pid, ?int $start): bool
    {
        if (!($this->isAlive)($pid)) {
            return false;
        }
        $raw = ($this->procStat)($pid);
        if ($raw === null) {
            return true;
        }
        $parsed = self::parseProcStat($raw);
        if ($parsed === null) {
            return true;
        }
        if ($parsed['state'] === 'Z') {
            return false;
        }

        return $start === null || $parsed['start'] === $start;
    }

    /**
     * The recorded start time of a key's PIDs (PID => start, or null where unknown).
     *
     * @return array<int, int|null>
     */
    private function startsFor(string $key): array
    {
        return $this->starts[$key] ?? [];
    }

    /**
     * The `/proc/<pid>/stat` start time (field 22), or null when unreadable.
     */
    private function startTimeOf(int $pid): ?int
    {
        $raw = ($this->procStat)($pid);
        if ($raw === null) {
            return null;
        }
        $parsed = self::parseProcStat($raw);

        return $parsed === null ? null : $parsed['start'];
    }

    /**
     * Send one signal to a tracked PID, only after confirming it is still the process
     * that was launched: the same start time, and not a zombie. A PID that fails the
     * check is not signalled. When no start time was recorded (no `/proc` at
     * registration), this is the pre-existing unchecked signal.
     */
    private function signalPid(int $pid, int $signal, ?int $start): void
    {
        if ($start !== null) {
            $raw = ($this->procStat)($pid);
            $parsed = $raw === null ? null : self::parseProcStat($raw);
            if ($parsed === null || $parsed['state'] === 'Z' || $parsed['start'] !== $start) {
                $this->logger->debug(
                    'SegmentProcessRegistry: pid is no longer the launched encode; not signalling',
                    ['pid' => $pid]
                );
                return;
            }
        }
        ($this->signalSender)($pid, $signal);
    }

    /**
     * The default progress token for the temp family of one launch: the CMAF progress
     * signal for each temp that has a progress file, otherwise the byte total of that
     * temp's family (an MPEG-TS temp, which grows continuously).
     *
     * The stat cache is cleared first. Otherwise a repeat stat of the same path would
     * answer from its first read, and a progress file that grew between two sweeps would
     * look frozen.
     *
     * @return callable(array<int, string>): int
     */
    private static function defaultProgressProbe(): callable
    {
        return static function (array $tmps): int {
            clearstatcache();
            $total = 0;
            foreach ($tmps as $tmp) {
                if ($tmp === '') {
                    continue;
                }
                $signal = self::progressSignal($tmp);
                if ($signal !== null) {
                    $total += $signal;
                    continue;
                }
                // No progress file: an MPEG-TS temp. Sum the temp and its siblings by
                // the same escaped stem, never a `{final}.part-*` family glob.
                $escaped = addcslashes($tmp, '*?[]\\');
                foreach (glob($escaped . '*') ?: [] as $file) {
                    $size = @filesize($file);
                    if (is_int($size)) {
                        $total += $size;
                    }
                }
            }
            return $total;
        };
    }

    /**
     * The CMAF progress signal for one temp: `out_time_us` from its `-progress` file,
     * or the file's mtime (microseconds) while out_time is N/A. Null when the temp has
     * no progress file (an MPEG-TS temp).
     */
    private static function progressSignal(string $tmp): ?int
    {
        $progressFile = $tmp . FfmpegRunner::FMP4_PROGRESS_TMP_SUFFIX;
        $mtime = @filemtime($progressFile);
        if ($mtime === false) {
            return null;
        }

        return self::lastOutTimeMicros($progressFile) ?? $mtime * 1_000_000;
    }

    /**
     * The last `out_time_us` value in the tail of a progress file, or null when none
     * is numeric yet (ffmpeg writes N/A until the first packet). Only the last
     * {@see PROGRESS_TAIL_BYTES} are read, so the cost is bounded regardless of how
     * long the encode has run.
     */
    private static function lastOutTimeMicros(string $progressFile): ?int
    {
        $handle = @fopen($progressFile, 'rb');
        if ($handle === false) {
            return null;
        }
        $stat = fstat($handle);
        $size = is_array($stat) ? $stat['size'] : 0;
        $from = $size > self::PROGRESS_TAIL_BYTES ? $size - self::PROGRESS_TAIL_BYTES : 0;
        $tail = fseek($handle, $from) === 0 ? stream_get_contents($handle) : false;
        fclose($handle);

        if (!is_string($tail) || preg_match_all('/out_time_us=(\d+)/', $tail, $matches) < 1) {
            return null;
        }

        return (int) end($matches[1]);
    }

    /**
     * Kill every tracked PID for a key (SIGTERM to the process group, then
     * SIGKILL after the grace period if still alive), remove the orphaned
     * `.part-*` temp, and drop the entry. Safe (no-op) for unknown or
     * already-cleared keys.
     *
     * @param string $key Cancel key whose encode(s) should be aborted.
     *
     * @return int Number of PIDs that were signalled.
     */
    public function kill(string $key): int
    {
        // SV-4.2-disconnect (landmine guard): if another client is still actively
        // waiting on this exact segment — a piggybacker that joined the launcher's
        // in-flight encode rather than spawning its own — do NOT signal. Killing
        // the shared encode here would 404 that other waiter. Leave the entry
        // FULLY tracked (PIDs, temps, group links) so the encode keeps running for
        // the remaining waiter(s). It is NOT re-killed later: it completes +
        // publishes normally and its entry is then dropped by the launcher's own
        // wait-timeout release (`timeout <n>` backstops a genuinely stuck one). The
        // launcher whose cancel reached this kill is itself counted as a waiter,
        // so the guard fires ONLY when a SECOND waiter genuinely exists — the
        // overwhelmingly common sole-waiter cancel still signals + reaps exactly
        // as before. This NARROWS the shared-encode 404 window (two hub channels /
        // two viewers on the same $final); the dedup RESERVATION gap is closed
        // separately by the reap callback on the signalled branch below (F1).
        $defer = false;
        if ($this->hasOtherWaiter !== null) {
            try {
                $defer = ($this->hasOtherWaiter)($key);
            } catch (\Throwable $e) {
                // F4 fail-safe: a throwing guard must NEVER strand a PID. Treat a
                // guard exception as "no other waiter" and proceed to reap — a lost
                // kill (orphaned ffmpeg burning CPU) is strictly worse than a rare
                // 404 for a hypothetical piggybacker the guard could not confirm.
                $this->logger->warning(
                    'SegmentProcessRegistry: waiter guard threw; proceeding to reap (fail-safe)',
                    ['key' => $key, 'exception' => $e::class, 'message' => $e->getMessage()],
                );
                $defer = false;
            }
        }
        if ($defer) {
            $this->logger->debug(
                'SegmentProcessRegistry: deferring kill — another waiter still present',
                ['key' => $key],
            );
            return 0;
        }

        $pids = $this->pids[$key] ?? [];
        $tmps = $this->tmpsByKey[$key] ?? [];
        $starts = $this->startsFor($key);
        $this->drop($key);
        if ($pids === []) {
            return 0;
        }

        // SV-4.2-disconnect F1: this is a genuine reap (past the waiter-guard defer
        // and there are PIDs to signal). Invalidate the owner's dedup reservation
        // for $key BEFORE the coroutine-yielding SIGTERM→SIGKILL wait below, so a
        // requester that arrives for the same segment re-launches a FRESH encode
        // instead of deduping onto the corpse we are about to signal (which would
        // 404 it until the ~5s stale-reconcile). Running before any yield means it
        // can only clear the reservation of the launcher being reaped here — a
        // fresher re-launch's reservation is created afterwards and its own
        // generation-guarded finally protects it from this reaped launcher.
        if ($this->onReap !== null) {
            ($this->onReap)($key);
        }

        $this->logger->debug('SegmentProcessRegistry: killing abandoned segment encode(s)', [
            'key' => $key,
            'pids' => $pids,
        ]);

        foreach ($pids as $pid) {
            $this->terminate($pid, $starts[$pid] ?? null);
        }

        // SV-4.2 finding #1: a signalled encode never runs its atomic-publish
        // `|| rm`, so remove the orphaned temp here — otherwise the cap/dedup
        // globs count the dead encode as still in-flight. SV-4.2 re-review Low:
        // clean ONLY this launcher's own `.part-<hex>` temp(s), never the whole
        // `{$final}.part-*` family, so a sibling worker's live temp survives.
        $this->cleanTemps($tmps);

        return count($pids);
    }

    /**
     * Kill every encode registered under a cancel group (the relay
     * channel/request id) — the HTTP_CANCEL / disconnect path (SV-4.2 / X1).
     * Each owned key is killed via {@see kill()} (group leader signalled + temp
     * cleaned + entry dropped), EXCEPT a key whose encode a second client is
     * still piggybacked on, which {@see kill()} defers via the waiter guard
     * (SV-4.2-disconnect) so the remaining waiter is still served. Safe
     * (returns 0) for unknown groups.
     *
     * @param string $group Cancel-group id (relay channel/request id).
     *
     * @return int Total number of PIDs signalled across the group's keys.
     */
    public function killGroup(string $group): int
    {
        // Snapshot: kill() mutates $this->keysByGroup via drop().
        $keys = $this->keysByGroup[$group] ?? [];
        if ($keys === []) {
            return 0;
        }

        $total = 0;
        foreach ($keys as $key) {
            $total += $this->kill($key);
        }
        return $total;
    }

    /**
     * The number of keys currently tracked. Exposed for leak assertions in tests
     * and for observability; should return to 0 once all in-flight encodes have
     * been released or killed.
     */
    public function registeredKeyCount(): int
    {
        return count($this->pids);
    }

    /**
     * The number of cancel groups currently tracked. Exposed for leak assertions
     * in tests; should return to 0 once all keys have been released or killed.
     */
    public function registeredGroupCount(): int
    {
        return count($this->keysByGroup);
    }

    /**
     * The PIDs tracked under a key (empty if none). Exposed for tests.
     *
     * @param string $key Cancel key.
     *
     * @return array<int, int>
     */
    public function pidsFor(string $key): array
    {
        return $this->pids[$key] ?? [];
    }

    /**
     * Remove a key's PID entry, its identity records and its group links (no signalling).
     *
     * @param string $key Cancel key to drop.
     */
    private function drop(string $key): void
    {
        unset($this->pids[$key], $this->tmpsByKey[$key], $this->progress[$key], $this->starts[$key]);

        $group = $this->groupOfKey[$key] ?? null;
        unset($this->groupOfKey[$key]);
        if ($group === null || !isset($this->keysByGroup[$group])) {
            return;
        }

        $remaining = array_values(array_filter(
            $this->keysByGroup[$group],
            static fn (string $k): bool => $k !== $key
        ));
        if ($remaining === []) {
            unset($this->keysByGroup[$group]);
        } else {
            $this->keysByGroup[$group] = $remaining;
        }
    }

    /**
     * Graceful-then-forced kill of a single detached encode process group,
     * coroutine-safe. The signal sender targets the whole group (negative PID),
     * so both the SIGTERM and the SIGKILL escalation reach the ffmpeg grandchild
     * directly, not just the `timeout`/`sh` wrapper (SV-4.2 finding #4). Every signal
     * is identity-checked first (see {@see signalPid()}).
     *
     * @param int      $pid   Process-group-leader id to terminate.
     * @param int|null $start The start time recorded at registration.
     */
    private function terminate(int $pid, ?int $start): void
    {
        if ($pid <= 0) {
            return;
        }

        $this->signalPid($pid, self::sigTerm(), $start);

        // Bounded, coroutine-yielding wait for graceful exit.
        $deadline = hrtime(true) + (int) ($this->gracePeriodSeconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            if (!$this->isLive($pid, $start)) {
                return;
            }
            $this->cooperativeSleep(0.05);
        }

        if ($this->isLive($pid, $start)) {
            $this->signalPid($pid, self::sigKill(), $start);
        }
    }

    /**
     * Sleep that yields to the Swoole event loop inside a coroutine and blocks
     * (briefly) otherwise — never stalls the worker under the coroutine runtime.
     *
     * @param float $seconds Sleep duration.
     */
    private function cooperativeSleep(float $seconds): void
    {
        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * @return callable(int, int): void
     */
    private static function defaultSignalSender(): callable
    {
        return static function (int $pid, int $signal): void {
            if ($pid <= 0) {
                return;
            }
            // Signal the whole process GROUP (negative PID): the detached encode
            // is launched under `setsid`, so its PGID == $pid. This reaches the
            // ffmpeg grandchild directly rather than only the `timeout`/`sh`
            // wrapper (SV-4.2 finding #4).
            if (function_exists('posix_kill')) {
                @posix_kill(-$pid, $signal);
                return;
            }
            $name = $signal === self::sigKill() ? 'KILL' : 'TERM';
            @shell_exec(sprintf('kill -%s -- -%d 2>/dev/null', $name, $pid));
        };
    }

    /**
     * @return callable(int): bool
     */
    private static function defaultLivenessProbe(): callable
    {
        return static function (int $pid): bool {
            if ($pid <= 0) {
                return false;
            }
            if (function_exists('posix_kill')) {
                return @posix_kill($pid, 0);
            }
            return is_dir('/proc/' . $pid);
        };
    }

    /**
     * @return callable(int): (string|null)
     */
    private static function defaultProcStatReader(): callable
    {
        return static function (int $pid): ?string {
            if ($pid <= 0) {
                return null;
            }
            $path = '/proc/' . $pid . '/stat';
            if (!is_readable($path)) {
                return null; // non-Linux, or the process is gone
            }
            $raw = @file_get_contents($path);

            return is_string($raw) ? $raw : null;
        };
    }

    /**
     * Parse one `/proc/<pid>/stat` line: the process state and its start time.
     *
     * The command name (field 2) is in parentheses and may itself hold spaces and
     * parentheses, so the fields are read from after the LAST `)`. The state is field 3
     * (index 0 there), and the start time is field 22 (index 19).
     *
     * @return array{state: string, start: int}|null Null when the line is malformed.
     */
    public static function parseProcStat(string $stat): ?array
    {
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)), -1, PREG_SPLIT_NO_EMPTY);
        if ($fields === false || count($fields) < 20) {
            return null;
        }
        $state = $fields[0];
        $start = $fields[19];
        if (strlen($state) !== 1 || !ctype_digit($start)) {
            return null;
        }

        return ['state' => $state, 'start' => (int) $start];
    }

    /**
     * Remove the specific launcher temp path(s) recorded for a killed / dead
     * encode via the injectable {@see $tempCleaner}. Only the exact
     * `.part-<hex>` paths THIS launcher created are removed — never a
     * `{$final}.part-*` glob — so a sibling worker's live temp for the same
     * final segment path is never destroyed (SV-4.2 re-review Low).
     *
     * @param array<int, string> $tmps The launcher's own temp paths for the key.
     */
    private function cleanTemps(array $tmps): void
    {
        foreach ($tmps as $tmp) {
            if ($tmp === '') {
                continue;
            }
            ($this->tempCleaner)($tmp);
        }
    }

    /**
     * @return callable(string): void
     */
    private static function defaultTempCleaner(): callable
    {
        return static function (string $tmp): void {
            // Remove exactly the launcher's own `.part-<hex>` temp — NOT a
            // `{$final}.part-*` glob — so a concurrent sibling worker's live temp
            // for the same final segment path is left intact (SV-4.2 re-review
            // Low). A signalled encode never ran its own `|| rm`, so this is the
            // only cleanup for that one orphaned temp.
            if ($tmp === '') {
                return;
            }
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            // S56: a CMAF encode writes three auxiliary temps BESIDE its
            // `.part-<hex>` marker — `<tmp>.i` (init), `<tmp>.s0` (fragment) and
            // `<tmp>.m3u8` (throwaway playlist) — and, since the progress watchdog, a
            // `<tmp>.prog` progress file. A signalled encode never runs its own cleanup,
            // so without this they outlive the job until the LRU sweep removes the whole
            // directory. `{$tmp}.*` is still keyed on THIS launcher's unique hex — it is
            // not a `{$final}.part-*` family glob — so a concurrent sibling worker's live
            // temps are untouched, which is the SV-4.2 re-review invariant above. The stem
            // is escaped exactly as the progress probe escapes it. On the MPEG-TS path no
            // such sibling exists and the glob returns nothing.
            $siblings = glob(addcslashes($tmp, '*?[]\\') . '.*');
            if (is_array($siblings)) {
                foreach ($siblings as $sibling) {
                    @unlink($sibling);
                }
            }
        };
    }

    private static function sigTerm(): int
    {
        return defined('SIGTERM') ? SIGTERM : 15;
    }

    private static function sigKill(): int
    {
        return defined('SIGKILL') ? SIGKILL : 9;
    }
}
