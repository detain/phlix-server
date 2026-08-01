<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\Timer;

/**
 * Drives {@see FolderWatcher} from a Workerman timer inside the `library-scan`
 * managed worker.
 *
 * ## What this fixes
 *
 * {@see FolderWatcher} is the only emitter of
 * {@see \Phlix\Playlists\LibraryUpdated}, and before this class existed its one
 * check entry point ({@see FolderWatcher::checkForChanges()}) had no caller
 * anywhere in the repo — no timer, no CLI command, no worker block. So
 * `LibraryUpdated` was never dispatched in production and folder watching
 * detected nothing. This class is the missing driver.
 *
 * A second gap had to be closed with it: the only call to
 * {@see FolderWatcher::watch()} is in {@see LibraryManager::createLibrary()},
 * i.e. in whichever HTTP worker served a "create library" request. Nothing ever
 * repopulated the watch list from the `libraries` table, so a freshly forked
 * managed worker's watcher holds ZERO paths. Arming a timer alone would
 * therefore still have detected nothing; this class registers the libraries
 * itself.
 *
 * ## What enabling it changes in production — and what it does not
 *
 * The only `LibraryUpdated` subscriber is
 * {@see \Phlix\Playlists\SmartPlaylistRefreshSubscriber}, so the sole
 * behavioural change is that a smart COLLECTION's stored membership
 * (`collection_items`) is refreshed when files under its library change,
 * without waiting for the next scan.
 *
 * It does NOT enqueue a rescan and does NOT add, remove or update
 * `media_items`: nothing in the `LibraryUpdated` path touches them. A newly
 * copied file stays invisible to the library until a scan runs.
 *
 * ## Why it must run in the `library-scan` worker
 *
 * PSR-14 dispatch is per-process — each fork builds its own
 * {@see \Phlix\Common\Events\ListenerRegistry} — and
 * `SmartPlaylistRefreshSubscriber` is registered only in the `library-scan`
 * managed worker (see `start.php`). Dispatching `LibraryUpdated` from any other
 * process would reach no listener.
 *
 * ## Cost, and how it is bounded
 *
 * Both registering a library and re-checking one perform
 * {@see FolderWatcher::calculateDirectoryChecksum()}: a recursive walk that
 * `stat()`s every file under the path, synchronous and blocking. Under the
 * shipped configuration filesystem calls are not coroutine-hooked
 * (`SwooleRuntime::SAFE_HOOK_NAMES` allowlists network + sleep hooks only —
 * though an operator who sets an explicit integer `coroutine.hook_flags` can
 * override that mask), so the walk holds the worker's event loop for its whole
 * duration and the worker cannot claim scan jobs meanwhile. Its cost scales
 * with the file count and with per-`stat()` latency, so it is cheap on a local
 * SSD and can be far worse on network storage; it has not been measured on the
 * production vault.
 *
 * That is why the feature ships disabled (`config/folder_watch.php`,
 * `enabled => false`) and why a tick does at most ONE library's worth of work:
 * one registration, or one re-check. There is exactly one exception, and it is
 * deliberate: a tick whose registration walk THREW also re-checks one library,
 * because otherwise an unregisterable library would starve the re-check phase
 * for every other library (see below). The per-library detection latency is
 * therefore about `library count x interval_seconds`, not `interval_seconds`.
 *
 * ## A library that cannot be registered
 *
 * A directory can be present and still be unwalkable: `is_dir()` returns true
 * while `RecursiveDirectoryIterator` throws `Failed to open directory:
 * Permission denied` — for an unreadable ROOT and equally for an unreadable
 * SUBDIRECTORY reached mid-walk, since no `CATCH_GET_CHILD` flag is set. That
 * library never becomes registered, so it is a permanent failure, not a
 * transient one, and it is re-discovered by `syncLibraries()` on every tick.
 *
 * Two things stop it from costing anything else:
 *
 * 1. when a registration walk fails the tick falls through to the re-check
 *    phase instead of returning, so every other library keeps being checked no
 *    matter how many libraries are broken; and
 * 2. the failing library is held out of the registration queue for a doubling
 *    number of ticks (1, 2, 4, 8, ... capped at
 *    {@see MAX_REGISTRATION_BACKOFF_TICKS}), so a permanently broken path costs
 *    one failed walk per cap-many ticks rather than one per tick.
 *
 * It is deliberately NOT dropped. An unreadable path is usually a dropped mount
 * or a permission an admin is about to fix, and recovery must not require a
 * worker restart: once the path becomes readable, the next scheduled attempt
 * registers it normally — at most {@see MAX_REGISTRATION_BACKOFF_TICKS} ticks
 * later, one hour at the shipped 300 s interval. Editing the library's `paths`
 * clears the backoff immediately, because the recorded failure no longer
 * describes that library.
 *
 * ## Coalescing
 *
 * A re-check dispatches one `LibraryUpdated` per changed PATH. No new
 * coalescing is introduced here: that is the same shape as
 * {@see LibraryManager::scanLibrary()}, which dispatches one
 * `LibraryScanCompleted` per scanned path, and
 * `SmartPlaylistRefreshSubscriber`'s pending-set (keyed by library id, drained
 * one library per timer tick) collapses both into a single refresh. Because a
 * tick re-checks at most one library, the refresh rate this can induce is
 * capped at one library per `interval_seconds`.
 *
 * ## Known limitations
 *
 * - {@see FolderWatcher::watch()} skips a path that is not a directory at
 *   registration time (it logs and moves on) without throwing, so the library
 *   still counts as registered and this class does not re-probe the path. A
 *   mount that is DOWN when its library is first registered therefore stays
 *   unwatched until the library's `paths` are edited or the worker restarts.
 *   (A path that exists but cannot be walked is the different case handled by
 *   the backoff above: that one throws.)
 * - {@see FolderWatcher} keys its watch list by PATH, not by library, so when
 *   two libraries are configured with the same path only the one registered
 *   last owns it: {@see FolderWatcher::checkLibrary()} returns an empty array
 *   forever for the other, and deleting the owning library unwatches the path
 *   for both. That is the watcher's pre-existing design; it merely becomes
 *   reachable now that every library gets registered.
 *
 * ## Resident-memory (Workerman) safety
 *
 * All state is instance-scoped and bounded by the number of rows in
 * `libraries` — every per-library map is pruned in `syncLibraries()` when the
 * row disappears — so there is no mutable static/global state and nothing
 * unbounded. One re-arming timer per worker, armed once. Never calls
 * `exit`/`die`, never blocks with `sleep()`. Every tick is wrapped in a
 * `Throwable` guard so a vanished mount (`RecursiveDirectoryIterator` throws on
 * an unreadable directory) cannot kill the timer or the worker.
 *
 * No re-entrancy guard is needed — but NOT because ticks cannot overlap. Under
 * Swoole, `Workerman\Events\Swoole::repeat()` runs each timer callback in its
 * own coroutine, and a tick's first act (the `getAllLibraries()` DB read) CAN
 * yield, because TCP is hooked; two ticks would overlap if that read outlived
 * `interval_seconds`. What makes the overlap harmless is that the expensive
 * part cannot interleave and the state is idempotent: the walks themselves
 * never yield (file IO is not hooked), every map is keyed by library id, and
 * both mutation sites commit BEFORE they walk — the pending key is unset before
 * {@see FolderWatcher::watch()}, and the round-robin cursor is advanced before
 * {@see FolderWatcher::checkLibrary()}. The worst case is one extra
 * registration or check within an interval, not corrupted state.
 *
 * @package Phlix\Media\Library
 * @since   0.14.0
 */
final class FolderWatchScheduler
{
    /**
     * Ceiling on the registration retry backoff, in ticks.
     *
     * A library whose baseline walk keeps throwing is retried after 1, 2, 4, 8
     * and then this many ticks. At the shipped 300 s interval the steady state
     * is one wasted walk attempt per hour, and a path whose permissions are
     * repaired comes under watch at most this many ticks later without a worker
     * restart.
     */
    private const MAX_REGISTRATION_BACKOFF_TICKS = 12;

    private StructuredLogger $logger;

    /** Whether the re-arming tick timer has been armed in this worker. */
    private bool $timerArmed = false;

    /**
     * Paths currently registered with the watcher, keyed by library id. The
     * stored value is what lets an admin edit of a library's `paths` be
     * noticed — the watcher itself is keyed by path, not by library.
     *
     * @var array<string, list<string>>
     */
    private array $registered = [];

    /**
     * Libraries discovered but not yet registered, keyed by library id, in
     * discovery order. One is drained per tick. Carrying the paths here rather
     * than re-reading the row at drain time keeps a tick to a single DB read.
     *
     * @var array<string, list<string>>
     */
    private array $pendingRegistration = [];

    /**
     * Libraries whose baseline walk threw, keyed by library id.
     *
     * `paths` is what the failure was recorded against, so that editing a
     * library's paths clears the backoff instead of making the admin wait it
     * out. `cooldown` is the number of ticks still to skip before the next
     * attempt; it is decremented once per tick. Entries are removed on a
     * successful registration and when the library leaves the `libraries`
     * table, so this map stays bounded by the row count.
     *
     * @var array<string, array{paths: list<string>, failures: int, cooldown: int}>
     */
    private array $registrationBackoff = [];

    /**
     * Registered library ids in round-robin re-check order.
     *
     * @var list<string>
     */
    private array $checkOrder = [];

    /** Cursor into {@see $checkOrder}; wraps. */
    private int $checkCursor = 0;

    /**
     * @param FolderWatcher         $watcher         Performs the checksum walks and dispatches
     *     {@see \Phlix\Playlists\LibraryUpdated}. Must be the container-bound instance:
     *     the bare `new FolderWatcher()` fallbacks in `Application.php` have no dispatcher.
     * @param LibraryManager        $libraries       Source of the library ids and paths to watch.
     * @param StructuredLogger|null $logger          Optional logger; defaults to the MEDIA channel.
     * @param bool                  $enabled         Master switch (`config/folder_watch.php`).
     *     False means {@see start()} arms no timer at all.
     * @param int                   $intervalSeconds Seconds between ticks. See the class
     *     docblock for why the effective per-library detection latency is this
     *     multiplied by the library count.
     */
    public function __construct(
        private readonly FolderWatcher $watcher,
        private readonly LibraryManager $libraries,
        ?StructuredLogger $logger = null,
        private readonly bool $enabled = false,
        private readonly int $intervalSeconds = 300,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Whether folder watching is enabled by configuration.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Arm the re-arming tick timer. Call once per worker, from the worker that
     * owns the work (`library-scan`).
     *
     * Does NO filesystem or database work itself — the first library is only
     * registered on the first tick, so boot stays fast.
     *
     * Boot-safe: returns false rather than throwing when the feature is
     * disabled, when {@see Timer} is absent (non-Workerman entry point), or
     * when no event loop is running (unit tests).
     *
     * @return bool True when a timer was armed.
     */
    public function start(): bool
    {
        if (!$this->enabled) {
            $this->logger->debug('FolderWatchScheduler: disabled by configuration, not arming timer');
            return false;
        }

        if ($this->timerArmed) {
            return false;
        }
        // Flag first so a throwing Timer::add (no runtime) cannot retry-spam.
        $this->timerArmed = true;

        if (!class_exists(Timer::class)) {
            return false;
        }

        try {
            // Workerman's Timer::add REPEATS by default, which is what is wanted
            // here: this is a poll, and it must keep polling for the life of the
            // worker. The first tick lands one interval after start().
            //
            // No boot catch-up is attempted, and a restart is NOT transparent:
            // the DB supplies only the PATH list, while the baseline detection
            // compares against is a directory checksum that FolderWatcher::watch()
            // re-derives from the FILESYSTEM on the tick that registers the
            // library. A change made while the worker was down is therefore
            // absorbed into the new baseline and never reported as a
            // LibraryUpdated. That is acceptable only because this poll's sole
            // effect is refreshing smart-collection membership, and a scan
            // re-dispatches LibraryScanCompleted for the same libraries anyway —
            // the scan path, not this poll, is what makes a missed change
            // visible.
            Timer::add($this->intervalSeconds, [$this, 'tick']);
        } catch (Throwable $e) {
            $this->logger->debug('FolderWatchScheduler: tick timer not armed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->logger->info('FolderWatchScheduler: folder watching armed', [
            'interval_seconds' => $this->intervalSeconds,
        ]);

        return true;
    }

    /**
     * Do one tick's worth of work: ONE library is registered, or ONE library is
     * re-checked. The single exception is a tick whose registration walk threw —
     * that tick falls through and also re-checks one library, so a library that
     * can never be registered cannot starve the re-check phase.
     *
     * Public so the timer callback (and tests) can invoke it directly. Never
     * throws: a failed walk must not kill the worker's timer.
     *
     * @return void
     */
    public function tick(): void
    {
        $this->ageRegistrationBackoff();

        try {
            $this->syncLibraries();
        } catch (Throwable $e) {
            $this->logger->warning('FolderWatchScheduler: could not refresh the library list', [
                'error' => $e->getMessage(),
            ]);
            // Fall through: an unreachable DB must not stop re-checking the
            // libraries already registered in this worker.
        }

        $libraryId = array_key_first($this->pendingRegistration);
        if ($libraryId !== null) {
            $paths = $this->pendingRegistration[$libraryId];
            // Drop BEFORE walking, so an overlapping tick (see the class
            // docblock) cannot start the same walk twice. registerLibrary()
            // decides whether it comes back: on success it moves to
            // $registered, on failure it goes into $registrationBackoff and
            // syncLibraries() re-queues it when the cooldown expires.
            unset($this->pendingRegistration[$libraryId]);
            if ($this->registerLibrary($libraryId, $paths)) {
                // A completed baseline walk IS this tick's one library's worth
                // of work, so the tick ends here.
                return;
            }

            // The walk failed (or there was nothing to walk). Fall through to
            // the re-check phase rather than returning: returning here is what
            // let a single unregisterable library starve the re-check phase for
            // every other library, permanently, because syncLibraries() re-queues
            // it every tick. The extra cost is bounded — a failed walk stops at
            // the directory it could not open, and the backoff keeps such ticks
            // rare.
        }

        $this->checkNextLibrary();
    }

    /**
     * Number of libraries currently registered with the watcher.
     *
     * @return int
     */
    public function registeredCount(): int
    {
        return count($this->registered);
    }

    /**
     * Number of libraries discovered but not yet registered.
     *
     * @return int
     */
    public function pendingRegistrationCount(): int
    {
        return count($this->pendingRegistration);
    }

    /**
     * Reconcile the in-worker watch list with the `libraries` table.
     *
     * Database-only — no filesystem work happens here, so this stays cheap
     * enough to run on every tick. New libraries are queued for registration
     * (the queue is drained one per tick, and a library whose last walk threw is
     * left out of it until its cooldown expires); deleted ones are unwatched
     * immediately, since dropping them is free.
     *
     * @return void
     */
    private function syncLibraries(): void
    {
        $current = [];
        foreach ($this->libraries->getAllLibraries() as $library) {
            $id = $library['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }
            $current[$id] = $this->pathsOf($library);
        }

        // Gone, or its paths were edited: drop the stale registration. A path
        // edit then falls through to the "not registered" branch below and is
        // re-queued in this same pass, so it needs no special case; the walk
        // happens on whichever tick its turn in the registration queue arrives.
        foreach ($this->registered as $id => $watched) {
            if (isset($current[$id]) && $current[$id] === $watched) {
                continue;
            }
            $this->forget($id);
            $this->logger->info('FolderWatchScheduler: dropped a stale library registration', [
                'library_id' => $id,
                'reason' => isset($current[$id]) ? 'paths_changed' : 'library_removed',
            ]);
        }

        foreach ($current as $id => $paths) {
            if (isset($this->registered[$id]) || $paths === []) {
                // Already watched, or nothing to watch — skip rather than queue
                // a no-op walk.
                continue;
            }

            $backoff = $this->registrationBackoff[$id] ?? null;
            if ($backoff !== null && $backoff['paths'] !== $paths) {
                // The admin edited the paths, so the recorded failure no longer
                // describes this library: retry at once instead of making them
                // wait out a cooldown earned by paths that are gone.
                unset($this->registrationBackoff[$id]);
                $backoff = null;
            }
            if ($backoff !== null && $backoff['cooldown'] > 0) {
                // Still cooling down after a failed baseline walk. Leaving it
                // out of the queue is what keeps the registration branch from
                // consuming every tick.
                continue;
            }

            $this->pendingRegistration[$id] = $paths;
        }

        // Drop queued registrations and recorded failures for libraries that
        // disappeared before their turn came round. This is also what bounds
        // both maps by the row count in `libraries`.
        foreach (array_keys($this->pendingRegistration) as $id) {
            if (!isset($current[$id])) {
                unset($this->pendingRegistration[$id]);
            }
        }
        foreach (array_keys($this->registrationBackoff) as $id) {
            if (!isset($current[$id])) {
                unset($this->registrationBackoff[$id]);
            }
        }
    }

    /**
     * Advance every recorded registration failure by one tick.
     *
     * Called once at the top of {@see tick()}, before {@see syncLibraries()}
     * decides what to queue. A cooldown of `n` therefore means "retry `n` ticks
     * from now": a first failure (cooldown 1) is retried on the very next tick,
     * which is what a transiently missing mount wants.
     *
     * @return void
     */
    private function ageRegistrationBackoff(): void
    {
        foreach ($this->registrationBackoff as $id => $backoff) {
            if ($backoff['cooldown'] > 0) {
                $this->registrationBackoff[$id]['cooldown'] = $backoff['cooldown'] - 1;
            }
        }
    }

    /**
     * Stop watching a library and remove it from the round-robin order.
     *
     * @param string $libraryId Library UUID.
     * @return void
     */
    private function forget(string $libraryId): void
    {
        $this->watcher->unwatch($libraryId);
        unset($this->registered[$libraryId]);

        $position = array_search($libraryId, $this->checkOrder, true);
        if ($position === false) {
            return;
        }
        array_splice($this->checkOrder, $position, 1);

        // Everything after $position shifted down one slot, so a cursor that
        // was already past the removed entry now points one library FURTHER on
        // than it did — which would skip a library for one round. Step it back.
        if ($position < $this->checkCursor) {
            $this->checkCursor--;
        }
    }

    /**
     * Register one library's paths with the watcher.
     *
     * This performs the BASELINE walk. {@see FolderWatcher::watch()} only stores
     * the resulting checksums; it dispatches nothing, so bringing a library
     * under watch never produces a spurious `LibraryUpdated`.
     *
     * @param string       $libraryId Library UUID.
     * @param list<string> $paths     The library's configured paths.
     * @return bool True when the library is now registered. False means the
     *     caller must NOT treat this tick as having done a library's worth of
     *     work — see the fall-through in {@see tick()}.
     */
    private function registerLibrary(string $libraryId, array $paths): bool
    {
        if ($paths === []) {
            return false;
        }

        try {
            $this->watcher->watch($libraryId, $paths);
        } catch (Throwable $e) {
            // An unreadable directory makes RecursiveDirectoryIterator throw,
            // and it will throw again on every retry for as long as the
            // permissions or the mount stay broken. Leave the library
            // unregistered and hold it out of the queue for a doubling number
            // of ticks, so a permanently broken path costs one wasted walk
            // occasionally instead of one every tick.
            $failures = ($this->registrationBackoff[$libraryId]['failures'] ?? 0) + 1;
            // Shift rather than pow(): guaranteed integer, and clamped so a
            // long-lived worker cannot overflow the exponent.
            $cooldown = min(1 << min($failures - 1, 4), self::MAX_REGISTRATION_BACKOFF_TICKS);
            $this->registrationBackoff[$libraryId] = [
                'paths' => $paths,
                'failures' => $failures,
                'cooldown' => $cooldown,
            ];

            $this->logger->warning('FolderWatchScheduler: could not start watching a library', [
                'library_id' => $libraryId,
                'error' => $e->getMessage(),
                'consecutive_failures' => $failures,
                'retry_in_ticks' => $cooldown,
            ]);
            return false;
        }

        unset($this->registrationBackoff[$libraryId]);
        $this->registered[$libraryId] = $paths;
        $this->checkOrder[] = $libraryId;

        return true;
    }

    /**
     * Re-check the next library in round-robin order.
     *
     * @return void
     */
    private function checkNextLibrary(): void
    {
        $total = count($this->checkOrder);
        if ($total === 0) {
            return;
        }

        if ($this->checkCursor >= $total) {
            $this->checkCursor = 0;
        }
        $libraryId = $this->checkOrder[$this->checkCursor];
        $this->checkCursor++;

        try {
            $changes = $this->watcher->checkLibrary($libraryId);
        } catch (Throwable $e) {
            $this->logger->warning('FolderWatchScheduler: change check failed', [
                'library_id' => $libraryId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($changes !== []) {
            $this->logger->info('FolderWatchScheduler: library change detected', [
                'library_id' => $libraryId,
                'changed_paths' => count($changes),
            ]);
        }
    }

    /**
     * Extract the watchable paths from a library row.
     *
     * `LibraryManager` decodes the `paths` column into a list of strings, but
     * its return type is `array<string, mixed>`, so the shape is re-checked
     * here rather than assumed.
     *
     * @param array<string, mixed> $library A row from {@see LibraryManager::getAllLibraries()}.
     * @return list<string> Non-empty path strings.
     */
    private function pathsOf(array $library): array
    {
        $raw = $library['paths'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $paths = [];
        foreach ($raw as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
