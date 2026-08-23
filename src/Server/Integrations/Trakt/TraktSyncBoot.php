<?php

/**
 * Phlix media server component: Integrations.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Integrations\Trakt;

use Phlix\Admin\SettingsRepository;

/**
 * Boot catch-up / due-decision for the Trakt pull-sync timer (S340).
 *
 * `start.php` runs OUTSIDE PHPUnit, so the due-decision that used to live
 * inline in the worker-0 `onWorkerStart` closure was, by construction,
 * unpinnable. It was also WRONG: the timer was armed on a bare
 * `Timer::add($intervalMinutes * 60, …)`, which fires its FIRST tick a full
 * interval after the process starts. On a server restarted (deploy,
 * `install.sh --update`, reboot, the admin Restart button's SIGUSR2 reload)
 * more often than the interval, that tick never happens and the pull-sync
 * silently does nothing — the last live instance of the boot-catch-up defect
 * this class closes.
 *
 * ## The shape (S308's, not the one-shot sibling's)
 *
 * Following the hub's core update check — the S308 structural due-check, whose
 * rationale this class borrows verbatim — the timer in `start.php` is now a
 * SHORT SWEEP at {@see DEFAULT_SWEEP_SECONDS} (60s) and this class holds the
 * due-decision: each sweep asks {@see runIfDue()}, which reads the persisted
 * last-run, runs the pull only when the configured interval has genuinely
 * elapsed, and persists the new last-run. That keeps the property the boot
 * catch-up existed for — a bare `Timer::add(86400, …)` fires its FIRST tick
 * 86400 seconds after the process starts, so on a box restarted more often
 * than the interval it fires NEVER. A 60-second sweep plus a persisted last-run
 * makes the catch-up STRUCTURAL rather than a special case: the first sweep is
 * a minute after every boot, and it runs the pull exactly when a poll was
 * actually missed. It is also strictly better than the one-shot boot-catch-up
 * shape the server's other three timers carry: that shape fetches once per
 * PROCESS START (every ten minutes on a flapping host), this one runs the pull
 * once per INTERVAL.
 *
 * ## Persistence
 *
 * The last-run lives in `server_settings` under {@see STATE_LAST_RUN_AT} — a
 * separate row, NOT a key inside `plugin_settings.settings_json`. The plugin
 * owns that JSON document (tokens, toggles, interval); writing a scheduler
 * bookkeeping key into it would couple the daemon's timer to a document the
 * plugin rewrites wholesale on every settings save, and would risk the key
 * being dropped or confused with plugin-owned data. `server_settings` is the
 * same DB-backed store the server's own core update check uses for
 * `updates.last_checked_at`, and it survives restart by construction.
 *
 * ## What this class will never do
 *
 * It does not touch the Trakt plugin. `TraktPlugin` is an external package
 * (`phlix-plugin-trakt`, not vendored here), so the pull itself is injected as
 * the `$sync` callback from `start.php` — the testable surface is exactly the
 * due-decision and the persistence, which is what this class owns.
 *
 * @package Phlix\Server\Integrations\Trakt
 * @since   S340 (Trakt pull-sync boot catch-up)
 */
final class TraktSyncBoot
{
    /**
     * Sweep cadence: how often the daemon ASKS whether a pull is due.
     *
     * 60s, the same cadence the hub's S308 structural due-check chose for its
     * sweep. A sweep is a single `server_settings` read; the pull (outbound
     * HTTP) is touched only when the configured interval has elapsed.
     */
    public const DEFAULT_SWEEP_SECONDS = 60;

    /**
     * Persisted result: unix timestamp of the last completed pull.
     *
     * Stored in `server_settings` (see the class docblock for why not
     * `plugin_settings`). Read via {@see SettingsRepository::getOverride()},
     * written via {@see SettingsRepository::set()}.
     */
    public const STATE_LAST_RUN_AT = 'trakt.sync_last_run_at';

    /**
     * Is a pull due? True when the pull has never run, when the configured
     * interval has elapsed since the last completed run, or when the stored
     * timestamp is in the future (a clock that moved backwards must not
     * silence the pull for years).
     *
     * Pure: no I/O, no clock reads — every branch is testable.
     *
     * @param int|null $lastRunAt      Persisted last-run timestamp, or null when the pull never ran.
     * @param int      $intervalSeconds Steady-state pull interval, seconds.
     * @param int      $now            The current unix timestamp being evaluated against.
     *
     * @return bool True when a pull should run now.
     *
     * @since S340
     */
    public static function isDue(?int $lastRunAt, int $intervalSeconds, int $now): bool
    {
        if ($lastRunAt === null) {
            return true;
        }

        if ($lastRunAt > $now) {
            return true;
        }

        return ($now - $lastRunAt) >= max(0, $intervalSeconds);
    }

    /**
     * Run the pull, but only if {@see isDue()} says an interval has elapsed.
     *
     * This is what the daemon's sweep calls: it is safe at any cadence,
     * because the interval — not the sweep — decides when the pull runs.
     *
     * The `$sync` callback is the caller's pull (in production, the
     * TraktPlugin invocation from `start.php`). It runs BEFORE the last-run is
     * persisted, so a pull that throws leaves the stale last-run in place and
     * the next sweep retries — a failed pull is never recorded as a success.
     * Callers that want to swallow a plugin error (as `start.php` does, so a
     * pull failure cannot take the worker's tick) catch inside the callback;
     * the last-run is then persisted on the callback's normal return, which
     * preserves the steady-state behaviour: a failing pull is retried after a
     * full interval, not on every 60-second sweep.
     *
     * The persisted timestamp is the COMPLETION time — `time()` taken after
     * the callback returns — not the decision time: a pull that takes longer
     * than the interval (a large first history sync, say) must not be due
     * again on the very next sweep, so the interval measures
     * completion-to-completion exactly as the hub's S308 due-check does.
     *
     * @param SettingsRepository $settings        Server settings store (read + persist the last-run).
     * @param int                $intervalSeconds Steady-state pull interval, seconds.
     * @param callable           $sync            The pull to run when due.
     * @param int|null           $now             Current unix timestamp; injectable for tests.
     *
     * @return bool True when the pull ran, false when none was due.
     *
     * @since S340
     */
    public static function runIfDue(
        SettingsRepository $settings,
        int $intervalSeconds,
        callable $sync,
        ?int $now = null,
    ): bool {
        $now ??= time();
        $lastRunAt = self::readLastRunAt($settings);

        if (!self::isDue($lastRunAt, $intervalSeconds, $now)) {
            return false;
        }

        $sync();
        $settings->set(self::STATE_LAST_RUN_AT, time(), 'int');

        return true;
    }

    /**
     * Read the persisted last-run timestamp, or null when unset/zero.
     *
     * Mirrors {@see \Phlix\Server\Updates\CoreUpdateCheckService::readStateInt()}'s
     * contract: the row is read through `getOverride()` (never `getEffective()`),
     * and a non-positive or non-int value counts as "never ran".
     *
     * @param SettingsRepository $settings Server settings store.
     *
     * @return int|null
     */
    private static function readLastRunAt(SettingsRepository $settings): ?int
    {
        $row = $settings->getOverride(self::STATE_LAST_RUN_AT);
        if ($row === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $row['value'];
        if (!is_int($value) || $value <= 0) {
            return null;
        }

        return $value;
    }
}
