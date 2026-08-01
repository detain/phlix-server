<?php

/**
 * Phlix media server component: Playlists.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Playlists;

use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Shared\Events\Library\LibraryScanCompleted;
use Throwable;
use Workerman\Timer;

/**
 * Host-side subscriber that drives smart-COLLECTION membership refresh off the
 * library-scan event stream.
 *
 * ## What this actually changes in production
 *
 * A smart COLLECTION stores its membership in `collection_items`, so after a
 * scan adds or removes media the stored membership is stale until something
 * re-evaluates it. {@see SmartPlaylistRefreshHandler} does exactly that, but it
 * was DEAD: its `register()` had no caller anywhere in the repo, so a smart
 * collection never refreshed after a scan. This subscriber is the missing
 * hookup, and that refresh is the ONLY production behaviour it changes.
 *
 * ⚠ It does NOT make smart PLAYLISTS auto-refresh — a smart playlist has no
 * stored membership to refresh. It holds rules only, and they are evaluated on
 * request by {@see SmartPlaylistController::preview()}. A library whose smart
 * playlists have no linked collection is unaffected by this wiring.
 *
 * ## Flow (enqueue on the event, drain on a timer)
 *
 * 1. Subscribes in the worker that DISPATCHES the events (the `library-scan`
 *    managed worker — see `start.php`). The handlers do NO I/O: they record the
 *    library id in a small bounded set and return.
 * 2. A re-arming {@see Timer}, armed LAZILY on the first enqueue, drains ONE
 *    library per tick and runs {@see SmartPlaylistRefreshHandler::refreshLibrary()}.
 *    The timer is boot-safe: guarded by `class_exists(Timer)` + try/catch, so it
 *    is a pure no-op outside a Workerman runtime (and in unit tests).
 *
 * ## Why the refresh MUST NOT run inline on the dispatch stack
 *
 * A refresh is O(library size × LINKED COLLECTIONS) + O(playlists) BLOCKING DB
 * round-trips. The library-size term is NOT multiplied by the playlist count;
 * the linear O(playlists) term is one cheap indexed `collections` lookup each.
 * Every {@see \Phlix\Collections\CollectionManager::refreshSmartCollection()}
 * re-evaluates its playlist's rules with
 * {@see SmartPlaylistEngine::evaluateOnScan()}, which walks the WHOLE library in
 * 500-row batches, and then writes the membership diff into `collection_items`.
 * Concretely, on a 50k-item library that is ~101 blocking paged `SELECT`s and
 * ~50k PHP rule evaluations PER LINKED COLLECTION, plus one `INSERT`/`DELETE`
 * per membership change — ~505 `SELECT`s and ~250k evaluations for five linked
 * collections. So ONE linked collection is already a full-library walk plus
 * writes: the deferral is justified by the per-collection cost alone, without
 * needing the cheap O(playlists) term. Run inline it would (a) stall the
 * single-threaded worker loop for the duration and (b) do so from INSIDE
 * `MediaScanner::scan()`, i.e. before the remaining library paths have even been
 * scanned.
 *
 * The floor is cheap, but it is NOT a single query: a library with no linked
 * collections still costs `1 + N` `SELECT`s for N smart playlists — one on
 * `smart_playlists`, then one indexed `collections` lookup PER PLAYLIST, because
 * {@see SmartPlaylistRefreshHandler::refreshLibrary()} can only rule a link out
 * by querying for it (measured: 5 playlists with zero links issue 6 queries).
 * The ceiling is what the deferral guards, and the subscriber cannot know which
 * case it is holding until it has done the work.
 *
 * ## Coalescing
 *
 * {@see \Phlix\Media\Library\LibraryManager::scanLibrary()} calls
 * `MediaScanner::scan()` once per library PATH, and each call dispatches its own
 * {@see LibraryScanCompleted}. A pending-set keyed by library id collapses those
 * N events into ONE refresh, and the deliberately unhurried tick spacing gives a
 * multi-path scan time to finish before the drain starts.
 *
 * ## Resident-memory (Workerman) safety
 *
 * Instance-scoped, de-duped, hard-capped pending set — no mutable static/global
 * state, nothing unbounded. One re-arming timer per worker, armed once. Never
 * calls `exit`/`die` and never blocks with `sleep()`.
 *
 * @package Phlix\Playlists
 * @since   0.14.0
 */
final class SmartPlaylistRefreshSubscriber
{
    /**
     * Hard cap on distinct libraries awaiting a refresh.
     *
     * An install has a handful of libraries, so this is only a leak guard: a
     * runaway dispatcher can never grow this set without bound in a resident
     * worker.
     */
    private const MAX_PENDING = 256;

    private StructuredLogger $logger;

    /** Whether the re-arming drain timer has been armed in this worker. */
    private bool $timerArmed = false;

    /**
     * Library ids awaiting a refresh, used as a set (dedupe + coalesce).
     *
     * @var array<string, true>
     */
    private array $pending = [];

    /**
     * @param SmartPlaylistRefreshHandler $handler          Performs the actual refresh.
     * @param StructuredLogger|null       $logger           Optional logger; defaults to the MEDIA channel.
     * @param float                       $drainTickSeconds Timer tick spacing. Deliberately
     *     unhurried: a refresh with any linked collection is a full-library walk
     *     plus membership writes, and the gap is what lets a multi-path scan's
     *     repeated LibraryScanCompleted events coalesce into a single refresh.
     */
    public function __construct(
        private readonly SmartPlaylistRefreshHandler $handler,
        ?StructuredLogger $logger = null,
        private readonly float $drainTickSeconds = 5.0,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Subscribe this subscriber's handlers to the given registry. Call once per
     * worker that dispatches the events.
     *
     * {@see LibraryScanCompleted} is the live trigger — `MediaScanner` dispatches
     * it at the end of every scanned library path. {@see LibraryUpdated} is the
     * event {@see SmartPlaylistRefreshHandler} was originally written for; its
     * only emitter is {@see \Phlix\Media\Library\FolderWatcher::checkForChanges()}
     * / `checkLibrary()`, which is now driven by
     * {@see \Phlix\Media\Library\FolderWatchScheduler} in this same worker — but
     * only when folder watching is enabled, and `config/folder_watch.php` ships
     * it disabled. So on a default install this second subscription still
     * receives nothing.
     *
     * @param ListenerRegistry $listeners The worker's listener registry.
     * @return array<int, string> The opaque listener ids.
     */
    public function register(ListenerRegistry $listeners): array
    {
        return [
            $listeners->subscribe(LibraryScanCompleted::class, [$this, 'onLibraryScanCompleted']),
            $listeners->subscribe(LibraryUpdated::class, [$this, 'onLibraryUpdated']),
        ];
    }

    /**
     * Handle the end of a library scan: enqueue only, never refresh inline.
     *
     * @param LibraryScanCompleted $event Completed-scan event.
     * @return void
     */
    public function onLibraryScanCompleted(LibraryScanCompleted $event): void
    {
        $this->enqueue($event->libraryId);
    }

    /**
     * Handle a folder-watch library change: enqueue only, never refresh inline.
     *
     * @param LibraryUpdated $event Library-changed event.
     * @return void
     */
    public function onLibraryUpdated(LibraryUpdated $event): void
    {
        $this->enqueue($event->libraryId);
    }

    /**
     * Refresh at most ONE pending library.
     *
     * Public so the drain timer callback (and tests) can invoke it directly.
     * Never throws: a failed refresh must not kill the worker's timer.
     *
     * @return void
     */
    public function drainTick(): void
    {
        $libraryId = array_key_first($this->pending);
        if ($libraryId === null) {
            return;
        }
        // Remove BEFORE refreshing: a library that fails must not be retried in
        // a tight loop, and a scan completing mid-refresh can re-enqueue it.
        unset($this->pending[$libraryId]);

        try {
            $this->handler->refreshLibrary($libraryId);
        } catch (Throwable $e) {
            $this->logger->warning('SmartPlaylistRefreshSubscriber: refresh failed', [
                'library_id' => $libraryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Number of libraries currently awaiting a refresh.
     *
     * @return int
     */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * Whether a library is awaiting a refresh.
     *
     * @param string $libraryId Library UUID.
     * @return bool
     */
    public function isPending(string $libraryId): bool
    {
        return isset($this->pending[$libraryId]);
    }

    /**
     * Record a library as needing a refresh, then make sure the drain timer runs.
     *
     * @param string $libraryId Library UUID.
     * @return void
     */
    private function enqueue(string $libraryId): void
    {
        if ($libraryId === '') {
            return;
        }

        if (!isset($this->pending[$libraryId])) {
            if (count($this->pending) >= self::MAX_PENDING) {
                $this->logger->warning(
                    'SmartPlaylistRefreshSubscriber: pending refresh set full, dropping',
                    ['library_id' => $libraryId, 'max_pending' => self::MAX_PENDING]
                );
                return;
            }
            $this->pending[$libraryId] = true;
        }

        $this->armDrainTimer();
    }

    /**
     * Arm the re-arming drain timer once. Boot-safe: a no-op outside a Workerman
     * runtime (class missing) or when the timer cannot be created (no running
     * event loop — e.g. unit tests), so an enqueue never fails because a timer
     * could not be scheduled.
     *
     * @return void
     */
    private function armDrainTimer(): void
    {
        if ($this->timerArmed) {
            return;
        }
        // Flag first so a throwing Timer::add (no runtime) does not retry-spam
        // on every subsequent enqueue.
        $this->timerArmed = true;

        if (!class_exists(Timer::class)) {
            return;
        }
        try {
            // Repeating timer (Workerman repeats by default) — one refresh per
            // tick, re-arming until the worker stops.
            Timer::add($this->drainTickSeconds, [$this, 'drainTick']);
        } catch (Throwable $e) {
            // No Workerman event loop (tests / non-daemon entrypoint): the drain
            // simply won't auto-run here; drainTick() can still be called
            // explicitly. Never let this bubble into the scan path.
            $this->logger->debug('SmartPlaylistRefreshSubscriber: drain timer not armed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
