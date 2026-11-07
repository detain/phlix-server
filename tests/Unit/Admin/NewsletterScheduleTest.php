<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Admin\NewsletterGenerator;
use Phlix\Admin\NewsletterSchedule;
use Phlix\Admin\NewsletterSender;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Tests\Support\Database\InMemoryServerSettingsConnection;
use PHPUnit\Framework\TestCase;

/**
 * The weekly newsletter due-decision (S340 shape), end to end on the pure
 * surface: slot math, the persisted-last-run law, the campaign wiring order,
 * and the bounded drain.
 *
 * The pre-fix timer gate was day/hour EQUALITY evaluated every second: it
 * re-fired the whole campaign for the entire send hour, skipped any week the
 * box was down across the hour, and its drain `while (getPendingCount() > 0)`
 * could never terminate once an exhausted-attempt pending row existed (the
 * count saw it, the select refused it). {@see NewsletterSchedule} replaces all
 * three; these tests pin each replacement law with a mutation-shaped
 * expectation, and the whole file is pinned to UTC because
 * mostRecentSlotStart() reads the default date timezone (the same reading the
 * old gate used) — a host TZ must not bend the slot.
 */
final class NewsletterScheduleTest extends TestCase
{
    private const SUNDAY_HOUR = 9;

    private string $savedTimezone = 'UTC';

    protected function setUp(): void
    {
        $this->savedTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->savedTimezone);
    }

    /** Unix timestamp of a named UTC instant. */
    private static function at(string $utc): int
    {
        $ts = strtotime($utc . ' UTC');
        self::assertIsInt($ts, "anchor '{$utc}' must parse");
        return $ts;
    }

    private function settings(): SettingsRepository
    {
        return new SettingsRepository(new InMemoryServerSettingsConnection(), dirname(__DIR__, 3) . '/config');
    }

    /**
     * @return \Phlix\Admin\NewsletterSender&\PHPUnit\Framework\MockObject\MockObject
     */
    private function senderMock(): NewsletterSender
    {
        return $this->createMock(NewsletterSender::class);
    }

    /**
     * @return \Phlix\Admin\NewsletterGenerator&\PHPUnit\Framework\MockObject\MockObject
     */
    private function generatorMock(): NewsletterGenerator
    {
        return $this->createMock(NewsletterGenerator::class);
    }

    private function silentLogger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    public function testMostRecentSlotStartFindsTheSlotOnTheConfiguredWeekday(): void
    {
        $slot = self::at('2026-11-01 09:00:00'); // a Sunday
        $this->assertSame(0, (int) date('w', $slot), 'fixture law: 2026-11-01 is the send day');

        // Mid-week: the most recent Sunday is the slot.
        $this->assertSame($slot, NewsletterSchedule::mostRecentSlotStart(0, self::SUNDAY_HOUR, self::at('2026-11-06 14:00:00')));
        // Exactly at the slot, the slot has arrived (catch-up boundary).
        $this->assertSame($slot, NewsletterSchedule::mostRecentSlotStart(0, self::SUNDAY_HOUR, $slot));
        // One second after: still that same slot.
        $this->assertSame($slot, NewsletterSchedule::mostRecentSlotStart(0, self::SUNDAY_HOUR, $slot + 1));
        // One second before: the slot has not arrived; the PREVIOUS occurrence answers.
        $this->assertSame(
            self::at('2026-10-25 09:00:00'),
            NewsletterSchedule::mostRecentSlotStart(0, self::SUNDAY_HOUR, $slot - 1)
        );
        // A different (day, hour) pair resolves independently.
        $this->assertSame(
            self::at('2026-10-31 00:00:00'),
            NewsletterSchedule::mostRecentSlotStart(6, 0, self::at('2026-11-01 23:00:00')),
            'Saturday 00:00 seen from Sunday night is the PREVIOUS day, not the next'
        );
    }

    public function testMostRecentSlotStartRefusesOutOfRangeConfig(): void
    {
        $now = self::at('2026-11-06 14:00:00');
        $this->assertNull(NewsletterSchedule::mostRecentSlotStart(7, 9, $now), 'day 7 describes no weekday');
        $this->assertNull(NewsletterSchedule::mostRecentSlotStart(-1, 9, $now), 'negative day describes no weekday');
        $this->assertNull(NewsletterSchedule::mostRecentSlotStart(0, 24, $now), 'hour 24 describes no hour');
        $this->assertNull(NewsletterSchedule::mostRecentSlotStart(0, -1, $now), 'negative hour describes no hour');
        // And a null slot is never due (fail-soft, the honest reading of garbage config).
        $this->assertFalse(NewsletterSchedule::isDue(null, null), 'no slot in config must never run a campaign');
    }

    public function testIsDueAnswersOncePerSlotAndCatchesUpAcrossOutages(): void
    {
        $slot = self::at('2026-11-01 09:00:00');

        $this->assertTrue(NewsletterSchedule::isDue(null, $slot), 'never-ran is due once the slot passed');
        $this->assertTrue(NewsletterSchedule::isDue($slot - 1, $slot), 'a last-run one second BEFORE the slot is unserved');
        $this->assertTrue(
            NewsletterSchedule::isDue(self::at('2026-06-01 00:00:00'), $slot),
            'a months-stale last-run (outage across many slots) still catches up'
        );
        $this->assertFalse(NewsletterSchedule::isDue($slot, $slot), 'a run AT the slot start served it — no re-fire');
        $this->assertFalse(NewsletterSchedule::isDue($slot + 59, $slot), 'a late-catch-up run still serves this slot');
        $this->assertFalse(NewsletterSchedule::isDue($slot, null), 'a served history cannot resurrect a garbage config');
    }

    public function testRunIfDueRunsAnUnservedSlotAndPersistsACompletionStamp(): void
    {
        $settings = $this->settings();
        $slot = self::at('2026-11-01 09:00:00');

        $runs = [];
        $this->assertTrue(NewsletterSchedule::runIfDue(
            $settings,
            0,
            self::SUNDAY_HOUR,
            static function (int $slotStart) use (&$runs): void {
                $runs[] = $slotStart;
            },
            self::at('2026-11-06 14:00:00')
        ));
        $this->assertSame([$slot], $runs, 'the campaign receives the slot start as its week anchor');

        $stored = $settings->getOverride(NewsletterSchedule::STATE_LAST_RUN_AT);
        $this->assertIsArray($stored);
        $this->assertTrue(is_int($stored['value']), 'the last-run persists as an int through the settings store');
        $this->assertGreaterThan(0, (int) $stored['value']);
    }

    public function testServedSlotStaysQuietAcrossSweeps(): void
    {
        // Seeded — not chained through a real-time persist — so this is
        // deterministic on any host clock: a completion stamp at/after the
        // slot serves it. This is the pre-fix per-second re-fire class ending.
        $settings = $this->settings();
        $slot = self::at('2026-11-01 09:00:00');
        $settings->set(NewsletterSchedule::STATE_LAST_RUN_AT, $slot + 120, 'int');

        $ran = 0;
        $campaign = static function () use (&$ran): void {
            $ran++;
        };

        $this->assertFalse(NewsletterSchedule::runIfDue($settings, 0, self::SUNDAY_HOUR, $campaign, $slot + 4000));
        $this->assertFalse(NewsletterSchedule::runIfDue($settings, 0, self::SUNDAY_HOUR, $campaign, $slot + 6 * 86400));
        $this->assertSame(0, $ran, 'one slot, one campaign — the persisted last-run IS the gate');
    }

    public function testNextSlotBecomesDueWhenItItselfPasses(): void
    {
        $settings = $this->settings();
        $slot = self::at('2026-11-01 09:00:00');
        $settings->set(NewsletterSchedule::STATE_LAST_RUN_AT, $slot, 'int');

        $runs = [];
        $this->assertTrue(NewsletterSchedule::runIfDue(
            $settings,
            0,
            self::SUNDAY_HOUR,
            static function (int $slotStart) use (&$runs): void {
                $runs[] = $slotStart;
            },
            $slot + 7 * 86400
        ), 'alignment: the campaign returns to the slot, not to a rolling interval');
        $this->assertSame([$slot + 7 * 86400], $runs);
    }

    public function testThrowingCampaignPersistsNothingAndRetriesNextSweep(): void
    {
        $settings = $this->settings();

        $boom = static function (): void {
            throw new \RuntimeException('campaign blew up');
        };

        try {
            NewsletterSchedule::runIfDue($settings, 0, self::SUNDAY_HOUR, $boom, self::at('2026-11-01 09:30:00'));
            $this->fail('runIfDue must propagate a campaign failure to the sweep wrapper');
        } catch (\RuntimeException $e) {
            $this->assertSame('campaign blew up', $e->getMessage());
        }

        $this->assertNull(
            $settings->getOverride(NewsletterSchedule::STATE_LAST_RUN_AT),
            'a failed campaign is never recorded as a served slot'
        );

        $done = false;
        $this->assertTrue(
            NewsletterSchedule::runIfDue(
                $settings,
                0,
                self::SUNDAY_HOUR,
                static function () use (&$done): void {
                    $done = true;
                },
                self::at('2026-11-01 09:31:00')
            ),
            'the next sweep still finds the slot unserved (retry, not skip)'
        );
        $this->assertTrue($done);
    }

    public function testGarbageConfigNeverRunsNorPersists(): void
    {
        $settings = $this->settings();

        $ran = false;
        $result = NewsletterSchedule::runIfDue(
            $settings,
            9, // no weekday 9 — the config describes no slot at all
            9,
            static function () use (&$ran): void {
                $ran = true;
            },
            self::at('2026-11-06 14:00:00')
        );

        $this->assertFalse($result);
        $this->assertFalse($ran, 'an unservable slot must never run a campaign');
        $this->assertNull(
            $settings->getOverride(NewsletterSchedule::STATE_LAST_RUN_AT),
            'the never-due path persists nothing (garbage config must never mark a slot served)'
        );
    }

    public function testUnreadableStateRowDefersQuietlyInsteadOfKillingTheSweep(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getOverride')->willThrowException(new \RuntimeException('store unreachable'));

        $ran = false;
        $result = NewsletterSchedule::runIfDue(
            $settings,
            0,
            self::SUNDAY_HOUR,
            static function () use (&$ran): void {
                $ran = true;
            },
            self::at('2026-11-06 14:00:00')
        );

        $this->assertFalse($result, 'a wobbled store must defer, not throw through the timer tick');
        $this->assertFalse($ran, 'an unreadable last-run must not run the campaign blind');
    }

    public function testCampaignRunsWhileAStoreWobbleOnThePersistTickStillReportsSuccess(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getOverride')->willReturn(null);
        $settings->method('set')->willThrowException(new \RuntimeException('write unreachable'));

        // The campaign already ran; dedup law (migration 125) makes a lost
        // persist tick a harmless re-fire next sweep, so the sweep reports the
        // work it did — but must NOT let the persist failure take the tick.
        $ran = false;
        $this->assertTrue(NewsletterSchedule::runIfDue(
            $settings,
            0,
            self::SUNDAY_HOUR,
            static function () use (&$ran): void {
                $ran = true;
            },
            self::at('2026-11-01 09:00:00')
        ));
        $this->assertTrue($ran);
    }

    public function testRunCampaignLeavesAnAlreadyServedSlotAlone(): void
    {
        $settings = $this->settings();
        $slot = self::at('2026-11-01 09:00:00');
        $settings->set(NewsletterSchedule::STATE_LAST_RUN_AT, $slot + 120, 'int');

        $sender = $this->senderMock();
        $sender->expects($this->never())->method('reapExhausted');
        $sender->expects($this->never())->method('queueAll');
        $sender->expects($this->never())->method('processQueue');

        $generator = $this->generatorMock();
        $generator->expects($this->never())->method('getRecipientUserIds');

        $this->assertFalse(NewsletterSchedule::runCampaign(
            $settings,
            $sender,
            $generator,
            0,
            self::SUNDAY_HOUR,
            50,
            $slot + 4000,
            $this->silentLogger()
        ), 'a sweep minutes after the campaign (pre-fix: the next SECOND) must be silent');
    }

    public function testRunCampaignReapsBeforeQueueingAndAnchorsTheWeekOnTheSlot(): void
    {
        $settings = $this->settings();
        $slot = self::at('2026-11-01 09:00:00');

        $order = [];

        $sender = $this->senderMock();
        $sender->method('reapExhausted')->willReturnCallback(
            function () use (&$order): int {
                $order[] = 'reap';
                return 2;
            }
        );
        $sender->expects($this->once())->method('queueAll')->with(
            $this->callback(function (array $users): bool {
                return $users === ['u-1'];
            }),
            $this->callback(function (\DateTimeInterface $weekStart) use (&$order): bool {
                $order[] = 'queue';
                // Slot minus seven days: a Monday catch-up summarizes the
                // same week a run at the slot would have (migration 125 dedup).
                return $weekStart->format('Y-m-d') === '2026-10-25';
            })
        )->willReturn(1);
        $sender->method('getPendingCount')->willReturnOnConsecutiveCalls(1, 0);
        $sender->expects($this->once())->method('processQueue')->with(50)->willReturnCallback(
            function (int $batch) use (&$order): int {
                $order[] = 'process';
                return 1;
            }
        );
        $sender->method('getDeliveryStats')->willReturnCallback(
            function () use (&$order): array {
                $order[] = 'stats';
                return ['pending' => 0, 'sent' => 1, 'failed' => 2, 'total' => 3];
            }
        );

        $generator = $this->generatorMock();
        $generator->method('getRecipientUserIds')->willReturnCallback(
            function () use (&$order): array {
                $order[] = 'recipients';
                return ['u-1'];
            }
        );

        $this->assertTrue(NewsletterSchedule::runCampaign(
            $settings,
            $sender,
            $generator,
            0,
            self::SUNDAY_HOUR,
            50,
            self::at('2026-11-02 10:00:00'), // Monday catch-up across the Sunday slot
            $this->silentLogger()
        ));

        $this->assertSame(
            ['reap', 'recipients', 'queue', 'process', 'stats'],
            $order,
            'the queue is made honest (reap) BEFORE new rows join it, then recipients -> queue -> drain -> stats'
        );
        $this->assertNotNull($settings->getOverride(NewsletterSchedule::STATE_LAST_RUN_AT));
    }

    public function testZeroPendingRecipientsStillCountsTheSlotServed(): void
    {
        $settings = $this->settings();

        $sender = $this->senderMock();
        $sender->method('reapExhausted')->willReturn(0);
        $sender->method('queueAll')->willReturn(0);
        $sender->expects($this->never())->method('processQueue'); // drain sees zero pending
        $sender->method('getPendingCount')->willReturn(0);
        $sender->method('getDeliveryStats')->willReturn(['pending' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0]);

        $generator = $this->generatorMock();
        $generator->method('getRecipientUserIds')->willReturn([]);

        $this->assertTrue(NewsletterSchedule::runCampaign(
            $settings,
            $sender,
            $generator,
            0,
            self::SUNDAY_HOUR,
            50,
            self::at('2026-11-01 09:00:00'),
            $this->silentLogger()
        ), 'an empty week is a served week — the slot must not re-fire every sweep');
        $this->assertNotNull($settings->getOverride(NewsletterSchedule::STATE_LAST_RUN_AT));
    }

    public function testDrainStopsOnZeroProgressEvenWhileTheCountStillClaimsPending(): void
    {
        // The wedging shape, reproduced: the count sees a row, the batch makes
        // no progress (a killed row). The aligned predicates plus this
        // progress-stop mean the loop ends after ONE pointless batch.
        $sender = $this->senderMock();
        $sender->method('getPendingCount')->willReturn(5);
        $sender->expects($this->exactly(1))->method('processQueue')->with(50)->willReturn(0);

        $processed = NewsletterSchedule::drainBounded($sender, 50, $this->silentLogger());

        $this->assertSame(0, $processed);
    }

    public function testDrainCapsAWhollyHealthyBacklogAtThePerSweepBudget(): void
    {
        // Maximal progress forever: 200-sends budget, batch 50 → four batches,
        // then the sweep yields to the next tick. No host may wedge the
        // count=1 worker with a huge healthy backlog either.
        $sender = $this->senderMock();
        $sender->method('getPendingCount')->willReturn(5000);
        $sender->expects($this->exactly(4))->method('processQueue')
            ->with(50)
            ->willReturn(50);

        $processed = NewsletterSchedule::drainBounded($sender, 50, $this->silentLogger());

        $this->assertSame(NewsletterSchedule::MAX_SEND_PER_SWEEP, $processed);
    }

    public function testDrainHonoursSmallBatchesInsideTheBudget(): void
    {
        // Honest fake: the queue really shrinks; batch 7 against 20 pending
        // drains 7/7/6 then sees zero — one call each, no spin.
        $remaining = 20;

        $sender = $this->senderMock();
        $sender->method('getPendingCount')->willReturnCallback(
            function () use (&$remaining): int {
                return $remaining;
            }
        );
        $sender->method('processQueue')->willReturnCallback(
            function (int $batch) use (&$remaining): int {
                $progress = min($batch, $remaining);
                $remaining -= $progress;
                return $progress;
            }
        );

        $this->assertSame(20, NewsletterSchedule::drainBounded($sender, 7, $this->silentLogger()));
        $this->assertSame(0, $remaining);
    }
}
