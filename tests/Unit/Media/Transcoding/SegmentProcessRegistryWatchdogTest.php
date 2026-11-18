<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The stall and wall-clock watchdog over detached segment encodes.
 *
 * Before this, releaseAfterWaitTimeout() forgot a LIVE encode once its request's
 * poll gave up. A stalled ffmpeg (a hung sshfs read, say) then kept its cap slot
 * and its `.part` temp until the 7200 s `timeout` wrapper fired. sweep() now owns
 * such encodes. It reaps them on a stall (no temp growth for the stall window)
 * or on the wall-clock cap, and it releases the slot and temp once the process is
 * dead.
 *
 * Every test drives sweep() with explicit monotonic instants and fakes the
 * process table, the signal sender and the temp size, so no real process is
 * signalled and no real file is removed by the probe.
 */
final class SegmentProcessRegistryWatchdogTest extends TestCase
{
    private const SEC = 1_000_000_000;

    /** @var array<int, bool> pid => alive */
    private array $alive = [];

    /** @var list<array{0: int, 1: int}> [pid, signal] in send order */
    private array $signals = [];

    /** @var list<string> temps the cleaner was asked to remove */
    private array $cleaned = [];

    /** @var list<string> keys whose reservation the reap callback invalidated */
    private array $reaped = [];

    /** @var array<string, int> temp => size reported by the probe */
    private array $sizes = [];

    private function registry(): SegmentProcessRegistry
    {
        $registry = new SegmentProcessRegistry(
            null,
            function (int $pid, int $signal): void {
                $this->signals[] = [$pid, $signal];
            },
            fn (int $pid): bool => $this->alive[$pid] ?? false,
            0.5,
            function (string $tmp): void {
                $this->cleaned[] = $tmp;
            },
        );
        $registry->setProgressProbe(function (array $tmps): int {
            $total = 0;
            foreach ($tmps as $tmp) {
                $total += $this->sizes[$tmp] ?? 0;
            }
            return $total;
        });
        // A fake process table: no test reads the real /proc for its fake PIDs.
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $registry->setReapCallback(function (string $key): void {
            $this->reaped[] = $key;
        });

        return $registry;
    }

    /**
     * Register a launch that is alive, with a temp, and return the instant it started.
     */
    private function launch(SegmentProcessRegistry $registry, string $key, int $pid, string $tmp): int
    {
        $this->alive[$pid] = true;
        $this->sizes[$tmp] = 0;
        $registry->register($key, $pid, null, $tmp);

        return hrtime(true);
    }

    /** @return list<int> */
    private function signalsFor(int $pid): array
    {
        return array_values(array_map(
            static fn (array $s): int => $s[1],
            array_filter($this->signals, static fn (array $s): bool => $s[0] === $pid),
        ));
    }

    public function testAStalledEncodeIsReapedAndItsTempReleasedOnceDead(): void
    {
        $registry = $this->registry();
        $tmp = '/segs/job/seg-00000.ts.part-0a1b2c3d';
        $start = $this->launch($registry, '/segs/job/seg-00000.ts', 4001, $tmp);

        // 31 s with no growth: past the 30 s stall window.
        self::assertSame(1, $registry->sweep($start + 31 * self::SEC));
        self::assertSame([self::SIGTERM], $this->signalsFor(4001));
        self::assertSame(1, $registry->orphanCount(), 'a reaped encode is held until it dies');

        // The process dies on TERM. The next pass removes the orphan and cleans its temp.
        $this->alive[4001] = false;
        $registry->sweep($start + 32 * self::SEC);

        self::assertSame(0, $registry->orphanCount(), 'a dead orphan frees its slot');
        self::assertSame([$tmp], $this->cleaned, 'the reaped encode\'s .part temp is removed');
    }

    public function testGrowingTempKeepsAnEncodeAlive(): void
    {
        $registry = $this->registry();
        $tmp = '/segs/job/seg-00001.ts.part-11223344';
        $start = $this->launch($registry, '/segs/job/seg-00001.ts', 4002, $tmp);

        $this->sizes[$tmp] = 1000;
        $registry->sweep($start + 20 * self::SEC); // growth at +20 s
        $this->sizes[$tmp] = 1000;
        $registry->sweep($start + 45 * self::SEC); // 25 s since growth: inside the window

        self::assertSame([], $this->signals, 'no kill while the temp is inside the stall window');

        $registry->sweep($start + 51 * self::SEC); // 31 s since growth: stalled
        self::assertSame([self::SIGTERM], $this->signalsFor(4002));
    }

    public function testWallClockCapReapsAnEncodeThatKeepsGrowing(): void
    {
        $registry = $this->registry();
        $tmp = '/segs/job/seg-00002.ts.part-55667788';
        $start = $this->launch($registry, '/segs/job/seg-00002.ts', 4003, $tmp);
        // Pin the cap this scenario was written against. The shipped default is 300 s.
        $registry->configureWatchdog(30.0, 120.0);

        // It grows every pass, so it never stalls. But it is past the 120 s wall cap.
        for ($s = 10; $s <= 110; $s += 10) {
            $this->sizes[$tmp] = $s * 100;
            $registry->sweep($start + $s * self::SEC);
        }
        self::assertSame([], $this->signals, 'a growing encode under the cap is left alone');

        $this->sizes[$tmp] = 99999;
        $registry->sweep($start + 121 * self::SEC);
        self::assertSame([self::SIGTERM], $this->signalsFor(4003));
    }

    public function testAnUnobservableLaunchIsNotKilledForSilence(): void
    {
        // No temp was registered, so growth cannot be seen. Only the wall-clock cap
        // may reap such an encode, never the stall rule.
        $registry = $this->registry();
        $this->alive[4004] = true;
        $registry->register('/segs/job/seg-00003.ts', 4004, null, null);
        $start = hrtime(true);
        $registry->configureWatchdog(30.0, 1000.0);

        $registry->sweep($start + 300 * self::SEC);

        self::assertSame([], $this->signals, 'silence alone is not evidence when no temp is observable');
    }

    public function testReleaseAfterWaitTimeoutHandsALiveEncodeToTheWatchdog(): void
    {
        $registry = $this->registry();
        $key = '/segs/job/seg-00004.ts';
        $tmp = '/segs/job/seg-00004.ts.part-99887766';
        $start = $this->launch($registry, $key, 4005, $tmp);

        $registry->releaseAfterWaitTimeout($key);

        self::assertSame([], $this->signals, 'a poll timing out never kills a live encode');
        self::assertSame(0, $registry->registeredKeyCount(), 'the keyed map is freed for a fresh launch');
        self::assertSame(1, $registry->orphanCount(), 'the live encode still has an owner');

        $registry->sweep($start + 31 * self::SEC);
        self::assertSame([self::SIGTERM], $this->signalsFor(4005), 'the watchdog reaps the orphan on stall');
    }

    public function testAReapedTrackedLaunchInvalidatesItsReservationBeforeTheSignal(): void
    {
        $registry = $this->registry();
        $key = '/segs/job/seg-00005.ts';
        $start = $this->launch($registry, $key, 4006, '/segs/job/seg-00005.ts.part-aabbccdd');

        $registry->sweep($start + 31 * self::SEC);

        self::assertSame([$key], $this->reaped, 'the dedup reservation for the reaped segment is invalidated');
        self::assertSame([self::SIGTERM], $this->signalsFor(4006));
    }

    public function testAnOrphanThatIgnoresTermIsEscalatedToKillAfterTheGrace(): void
    {
        $registry = $this->registry();
        $tmp = '/segs/job/seg-00006.ts.part-deadbeef';
        $start = $this->launch($registry, '/segs/job/seg-00006.ts', 4007, $tmp);

        $registry->sweep($start + 31 * self::SEC); // TERM
        $registry->sweep($start + 31 * self::SEC + intdiv(self::SEC, 10)); // inside the grace: no KILL yet
        self::assertSame([self::SIGTERM], $this->signalsFor(4007));

        $registry->sweep($start + 32 * self::SEC); // past the 0.5 s grace, still alive: KILL
        self::assertSame([self::SIGTERM, self::SIGKILL], $this->signalsFor(4007));
    }

    public function testAHealthyFinishedLaunchIsDroppedWithoutSignalling(): void
    {
        $registry = $this->registry();
        $tmp = '/segs/job/seg-00007.ts.part-f00dbabe';
        $start = $this->launch($registry, '/segs/job/seg-00007.ts', 4008, $tmp);

        // The encode finishes and exits well inside the window.
        $this->alive[4008] = false;
        $registry->sweep($start + 5 * self::SEC);

        self::assertSame([], $this->signals);
        self::assertSame(0, $registry->registeredKeyCount(), 'a dead tracked launch is dropped');
        self::assertSame([$tmp], $this->cleaned, 'a dead launch\'s own temp is cleaned');
    }

    public function testConfigureWatchdogIgnoresNonPositiveValues(): void
    {
        $registry = $this->registry();
        $tmp = '/segs/job/seg-00008.ts.part-cafef00d';
        $start = $this->launch($registry, '/segs/job/seg-00008.ts', 4009, $tmp);
        $registry->configureWatchdog(0.0, -5.0);

        // Defaults stand (30 s stall, 300 s wall): 31 s of silence still reaps.
        $registry->sweep($start + 31 * self::SEC);
        self::assertSame([self::SIGTERM], $this->signalsFor(4009));
    }

    /** SIGTERM and SIGKILL numbers as the registry itself resolves them. */
    private const SIGTERM = 15;
    private const SIGKILL = 9;
}
