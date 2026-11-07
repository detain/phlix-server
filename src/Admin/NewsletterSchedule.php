<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;

/**
 * Slot-aware due-decision for the weekly newsletter campaign (S340 shape).
 *
 * The newsletter timer used to run as a `Timer::add(1, …)` whose callback
 * gate was `(int) date('w') === $sendDay && (int) date('G') === $sendHour`.
 * That equality gate is wrong in both directions: for the whole send hour it
 * re-fires the campaign EVERY SECOND (queue creation plus a full drain, each
 * tick, on the count=1 background-timers worker), and a server that is down
 * across the send hour skips that week's newsletter silently — the exact
 * boot-catch-up defect {@see \Phlix\Server\Integrations\Trakt\TraktSyncBoot}
 * (S340, itself following the hub's S308 structural due-check) was spawned to
 * end. This class carries the replacement decision; like `TraktSyncBoot` it
 * owns ONLY the due-decision and the persisted bookkeeping — the campaign
 * itself is injected as the callback, so the whole scheduling surface is
 * pure/static and testable outside Workerman.
 *
 * ## The shape
 *
 * The daemon sweeps every {@see DEFAULT_SWEEP_SECONDS} and asks
 * {@see runIfDue()}. A sweep reads the persisted last-run
 * ({@see STATE_LAST_RUN_AT} in `server_settings`) and runs the campaign
 * exactly when the most recent slot occurrence
 * ({@see mostRecentSlotStart()}) has not yet been served:
 * `lastRunAt === null || lastRunAt < slotStart`. One run per slot (later
 * sweeps see the persisted run at/after the slot and stay quiet); catch-up
 * for a box that was down across the slot (the first sweep after boot finds
 * the slot unserved and runs); alignment back onto the slot afterwards
 * (the next slot only becomes due when it itself passes).
 *
 * ## What this class will never do
 *
 * It never touches the mail transport, the queue rows, or Workerman. A
 * campaign that throws leaves the stale last-run in place and the next sweep
 * retries ({@see runIfDue()} persists only after the callback returns), so a
 * failed campaign is never recorded as a success.
 *
 * @package Phlix\Admin
 */
final class NewsletterSchedule
{
    /**
     * Sweep cadence: how often the daemon ASKS whether a campaign is due.
     *
     * 60s, the same cadence {@see \Phlix\Server\Integrations\Trakt\TraktSyncBoot}
     * and the hub's S308 due-check chose. A sweep is one `server_settings`
     * read; the campaign (queue creation, drains, mail) is touched only when
     * the slot has genuinely passed unserved.
     */
    public const DEFAULT_SWEEP_SECONDS = 60;

    /**
     * Persisted result: unix timestamp of the last completed campaign.
     *
     * Stored in `server_settings` (same store and read/write idiom as
     * `trakt.sync_last_run_at` and `updates.last_checked_at`), so the
     * due-state survives restarts by construction.
     */
    public const STATE_LAST_RUN_AT = 'newsletter.last_run_at';

    /**
     * error_message written on queue rows this subsystem reaps or re-queues.
     *
     * Shared between the sender (transport-error re-queue + budget reap) and
     * the campaign guard so an operator reading newsletter_queue sees the same
     * sentence for the same lifecycle event, from either code path.
     */
    public const NOTE_EXHAUSTED_ATTEMPTS = 'attempt budget exhausted before delivery completed';

    /**
     * Hard ceiling on sends one sweep may perform.
     *
     * Each send is a blocking `@mail()` on the count=1 background-timers
     * worker, so the drain is bounded twice: by progress (a batch that
     * advances nothing ends the loop — the infinite-drain defect this law
     * ends could only spin while progress looked possible) and by this
     * budget, so even maximal progress caps one sweep's stall. Whatever is
     * left waits for the next sweep; backlog drains at
     * MAX_SEND_PER_SWEEP per {@see DEFAULT_SWEEP_SECONDS} while a campaign is
     * being served.
     */
    public const MAX_SEND_PER_SWEEP = 200;

    /**
     * Start of the most recent newsletter slot at or before $now.
     *
     * The slot is the weekly (day, hour) pair the operator configured
     * (send_day 0=Sunday..6=Saturday, send_hour 0-23) interpreted in the
     * default date timezone — the same reading the old equality gate used.
     *
     * Pure: no I/O; the clock arrives as a parameter. An out-of-range day or
     * hour yields null (no matching slot), which {@see isDue()} treats as
     * never due — fail-soft, matching what a garbage gate silently did before.
     *
     * @param int $sendDay  Day of week (0=Sunday..6=Saturday).
     * @param int $sendHour Hour of day (0-23).
     * @param int $now      Unix timestamp to evaluate against.
     *
     * @return int|null Slot start unix timestamp, or null when the config
     *                  cannot describe any slot.
     */
    public static function mostRecentSlotStart(int $sendDay, int $sendHour, int $now): ?int
    {
        if ($sendDay < 0 || $sendDay > 6 || $sendHour < 0 || $sendHour > 23) {
            return null;
        }

        $timezone = new \DateTimeZone(date_default_timezone_get());
        $cursor = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);

        for ($offset = 0; $offset <= 7; $offset++) {
            $candidate = $cursor->modify('-' . $offset . ' days')->setTime($sendHour, 0, 0);
            if ((int) $candidate->format('w') === $sendDay && $candidate->getTimestamp() <= $now) {
                return $candidate->getTimestamp();
            }
        }

        return null;
    }

    /**
     * Is a campaign due? True when none has ever run, or when the most recent
     * slot has not been served yet.
     *
     * Pure: every branch is testable. A null slot (config cannot describe one)
     * is never due; a last-run stamped at/after the slot means that slot was
     * already served (this is what stops the pre-fix per-second re-fire); a
     * last-run before the slot — including a months-stale one after an
     * outage — is the catch-up case.
     *
     * @param int|null $lastRunAt Persisted last-campaign timestamp, or null when none ran.
     * @param int|null $slotStart Most recent slot start from {@see mostRecentSlotStart()}, or null.
     */
    public static function isDue(?int $lastRunAt, ?int $slotStart): bool
    {
        if ($slotStart === null) {
            return false;
        }

        if ($lastRunAt === null) {
            return true;
        }

        return $lastRunAt < $slotStart;
    }

    /**
     * Run the campaign, but only when the most recent slot is unserved.
     *
     * This is what the daemon's sweep calls: safe at any cadence, because the
     * slot and the persisted last-run — not the tick — decide when the
     * campaign runs. Mirrors the {@see \Phlix\Server\Integrations\Trakt\TraktSyncBoot::runIfDue()}
     * persistence law: the callback runs FIRST, the completion timestamp
     * (`time()` taken after the callback returns) is persisted only on normal
     * return, so a campaign that throws retries on the next sweep and a slow
     * campaign is not immediately due again.
     *
     * @param SettingsRepository                $settings Server settings store (read + persist the last-run).
     * @param int                             $sendDay  Configured send day (0=Sunday).
     * @param int                             $sendHour Configured send hour (0-23).
     * @param callable(int): void             $campaign Runs the weekly send; receives the slot start so the
     *                                                  campaign's week window is slot-stable (catch-up on
     *                                                  Monday summarizes the same week as a run at the slot).
     * @param int|null                        $now      Current unix timestamp; injectable for tests.
     *
     * @return bool True when the campaign ran, false when the slot was served
     *              already, the config describes no slot, or the state row is
     *              unreachable (a failed read logs and defers — the timer
     *              worker must survive a settings-store wobble).
     */
    public static function runIfDue(
        SettingsRepository $settings,
        int $sendDay,
        int $sendHour,
        callable $campaign,
        ?int $now = null,
    ): bool {
        $now ??= time();

        try {
            $lastRunAt = self::readLastRunAt($settings);
        } catch (\Throwable $e) {
            self::logSweepFailure('Newsletter sweep could not read its persisted last-run', $e);
            return false;
        }

        $slotStart = self::mostRecentSlotStart($sendDay, $sendHour, $now);

        if ($slotStart === null) {
            // The config describes no reachable slot — nothing to catch up to.
            return false;
        }

        if (!self::isDue($lastRunAt, $slotStart)) {
            return false;
        }

        $campaign($slotStart);

        try {
            $settings->set(self::STATE_LAST_RUN_AT, time(), 'int');
        } catch (\Throwable $e) {
            // The campaign already ran; its rows dedup on
            // (user_id, week_start) (migration 125), so a lost persist tick
            // re-fires harmlessly — never let it take the worker's tick.
            self::logSweepFailure('Newsletter sweep could not persist its last-run', $e);
        }

        return true;
    }

    /**
     * The weekly campaign: run the due decision, then queue + bounded drain.
     *
     * This is what the daemon's 60-second sweep calls (the Application timer
     * wraps it in a try/catch so a failure can never take the worker's tick).
     * On a due slot it first reaps budget-exhausted orphans (making the
     * queue's accounting honest BEFORE new rows are added), creates the
     * week's queue entries keyed on the SLOT-derived week start
     * (slot start minus 7 days — so a catch-up run on Monday summarizes the
     * same week a run at the slot would have, and migration 125's
     * (user_id, week_start) uniqueness dedups any re-fire), then drains in
     * bounded batches.
     *
     * @return bool True when the campaign ran (slot was unserved), false on
     *               every quiet path: not due, no slot in config, state row
     *               unreachable, or no recipients to serve (a run with zero
     *               recipients still persists — the slot was served).
     */
    public static function runCampaign(
        SettingsRepository $settings,
        NewsletterSender $sender,
        NewsletterGenerator $generator,
        int $sendDay,
        int $sendHour,
        int $batchSize,
        ?int $now = null,
        ?StructuredLogger $logger = null,
    ): bool {
        $logger ??= LoggerFactory::get(LogChannels::MEDIA);

        return self::runIfDue(
            $settings,
            $sendDay,
            $sendHour,
            static function (int $slotStart) use ($sender, $generator, $batchSize, $logger): void {
                $reaped = $sender->reapExhausted();
                if ($reaped > 0) {
                    $logger->warning(
                        'Newsletter campaign reaped exhausted-attempt orphans before queueing',
                        ['count' => $reaped],
                    );
                }

                $timezone = new DateTimeZone(date_default_timezone_get());
                $weekStart = (new DateTimeImmutable('@' . $slotStart))
                    ->setTimezone($timezone)
                    ->modify('-7 days');

                $userIds = $generator->getRecipientUserIds();
                $queued = $sender->queueAll($userIds, $weekStart);

                $logger->info('Newsletter queue created', [
                    'count' => $queued,
                    'week_start' => $weekStart->format('Y-m-d'),
                ]);

                $processed = self::drainBounded($sender, $batchSize, $logger);
                $logger->info('Newsletter batch processed', ['processed' => $processed]);

                $stats = $sender->getDeliveryStats();
                $logger->info('Newsletter delivery stats', $stats);
            },
            $now,
        );
    }

    /**
     * Drain the eligible queue in batches, under two independent stops.
     *
     * The pre-fix loop was `while (getPendingCount() > 0) { $processed +=
     * processQueue($batchSize); }` — no progress check, no cap. A single
     * pending row that processQueue can never select (its attempt budget was
     * spent between the count query and the select query, the old shape being
     * the un-aligned predicate) made that loop spin forever and wedged the
     * whole background-timers worker. The predicates are aligned now
     * ({@see NewsletterSender::getPendingCount()}), and this function is
     * belt-and-suspenders on top of that: zero progress ends the drain even
     * if the count still says otherwise, and {@see MAX_SEND_PER_SWEEP} caps
     * the stall of maximal progress.
     *
     * @return int Total entries processed by this drain.
     */
    public static function drainBounded(NewsletterSender $sender, int $batchSize, ?StructuredLogger $logger = null): int
    {
        $logger ??= LoggerFactory::get(LogChannels::MEDIA);

        $processed = 0;
        $budget = self::MAX_SEND_PER_SWEEP;

        while ($budget > 0 && $sender->getPendingCount() > 0) {
            $batch = min($batchSize, $budget);
            $progress = $sender->processQueue($batch);
            if ($progress <= 0) {
                // Eligibility says pending work exists but no batch advanced
                // it — stop spinning; the next sweep (or the reap) owns it.
                $logger->warning('Newsletter drain stopped without progress', [
                    'processed_so_far' => $processed,
                    'pending' => $sender->getPendingCount(),
                ]);
                break;
            }
            $processed += $progress;
            $budget -= $progress;
        }

        if ($budget <= 0 && $sender->getPendingCount() > 0) {
            $logger->info('Newsletter drain reached its per-sweep cap; remaining rows wait for the next sweep', [
                'processed' => $processed,
                'cap' => self::MAX_SEND_PER_SWEEP,
            ]);
        }

        return $processed;
    }

    /**
     * Read the persisted last-run timestamp, or null when unset/non-positive.
     *
     * Same contract as the Trakt/S308 siblings: read via getOverride() (never
     * getEffective()), a non-int or non-positive value counts as "never ran".
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

    /**
     * A sweep-wiring failure is operator-visible detail, never a tick killer.
     */
    private static function logSweepFailure(string $message, \Throwable $e): void
    {
        \Phlix\Common\Logger\LoggerFactory::get(\Phlix\Common\Logger\LogChannels::MEDIA)->error($message, [
            'error' => $e->getMessage(),
        ]);
    }
}
