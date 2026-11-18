<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * PID identity for the segment registry (`/proc/<pid>/stat`).
 *
 * `posix_kill($pid, 0)` reports a zombie as alive, and it cannot tell a reused PID
 * from the encode that was launched. So the registry records each PID's start time
 * (field 22) at registration, and it treats state `Z` as dead. A PID whose start time
 * has changed is never signalled. The `/proc` reader is injected, so the fake process
 * table below needs no real process.
 */
final class SegmentProcessRegistryIdentityTest extends TestCase
{
    private const SIGTERM = 15;

    private const SIGKILL = 9;

    private const SEC = 1_000_000_000;

    /** @var array<int, string> pid => /proc/<pid>/stat line (absent = no such process) */
    private array $proc = [];

    /** @var array<int, bool> pid => alive, as the injected probe reports it */
    private array $alive = [];

    /** @var list<array{0: int, 1: int}> */
    private array $signals = [];

    /** @var list<string> */
    private array $cleaned = [];

    /** When true, the fake process table reports the PID dead once it is signalled. */
    private bool $dieOnSignal = false;

    /** @var array<int, int> The fake TERM-time group membership: PID => start time. */
    private array $members = [];

    /** @var array<int, bool> pid => whether its process group still exists, as the fake reports it */
    private array $groupAlive = [];

    /**
     * A `/proc/<pid>/stat` line with the given state and start time. Field 3 is the
     * state, and field 22 is the start time, which is index 19 after the `)`.
     */
    private static function statLine(int $pid, string $state, int $start, string $comm = 'ffmpeg seg'): string
    {
        $fields = [
            $state, '1', '1', '1', '0', '-1', '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '20', '0', '1', '0', (string) $start, '0', '0',
        ];

        return $pid . ' (' . $comm . ') ' . implode(' ', $fields);
    }

    private function registry(?\Psr\Log\LoggerInterface $logger = null): SegmentProcessRegistry
    {
        $registry = new SegmentProcessRegistry(
            $logger,
            function (int $pid, int $signal): void {
                $this->signals[] = [$pid, $signal];
                if ($this->dieOnSignal) {
                    $this->alive[$pid] = false;
                }
            },
            fn (int $pid): bool => $this->alive[$pid] ?? false,
            0.5,
            function (string $tmp): void {
                $this->cleaned[] = $tmp;
            },
        );
        $registry->setProcStatReader(fn (int $pid): ?string => $this->proc[$pid] ?? null);
        // Fake PIDs must never match a real process group on this host, and a per-process
        // KILL must never reach a real PID. Both go through the fakes.
        $registry->setGroupMembersProbe(fn (array $leaders): array => $this->members);
        $registry->setGroupAliveProbe(fn (int $pid): bool => $this->groupAlive[$pid] ?? false);
        $registry->setProcessSignalSender(function (int $pid, int $signal): void {
            $this->signals[] = [$pid, $signal];
            if ($this->dieOnSignal) {
                $this->alive[$pid] = false;
            }
        });

        return $registry;
    }

    /** @return list<int> */
    private function signalsFor(int $pid): array
    {
        return array_values(array_map(
            static fn (array $s): int => $s[1],
            array_filter($this->signals, static fn (array $s): bool => $s[0] === $pid),
        ));
    }

    public function testTheStatParserReadsTheStateAndTheStartTime(): void
    {
        $parsed = SegmentProcessRegistry::parseProcStat(self::statLine(4321, 'S', 987654));

        self::assertSame(['state' => 'S', 'start' => 987654], $parsed);
    }

    public function testTheStatParserReadsPastACommandNameThatHoldsSpacesAndParentheses(): void
    {
        // The comm field is "(ffmpeg (sh) x)": the fields start after the LAST `)`.
        $line = '4321 (ffmpeg (sh) x) S 1 4321 4321 0 -1 4194560 100 0 0 0 5 2 0 0 20 0 3 0 987654 1234 56';

        self::assertSame(['state' => 'S', 'start' => 987654], SegmentProcessRegistry::parseProcStat($line));
    }

    public function testTheStatParserRecognisesAZombie(): void
    {
        self::assertSame(
            ['state' => 'Z', 'start' => 55],
            SegmentProcessRegistry::parseProcStat(self::statLine(7, 'Z', 55)),
        );
    }

    public function testTheStatParserRejectsMalformedLines(): void
    {
        self::assertNull(SegmentProcessRegistry::parseProcStat(''), 'empty');
        self::assertNull(SegmentProcessRegistry::parseProcStat('4321 (no close paren S 1 2'), 'no closing paren');
        self::assertNull(SegmentProcessRegistry::parseProcStat('4321 (x) S 1 2 3'), 'too few fields');
        self::assertNull(
            // The state, then 18 numeric fields, then a non-numeric 20th field: index 19 is 'abc'.
            SegmentProcessRegistry::parseProcStat('9 (x) S ' . implode(' ', array_fill(0, 18, '0')) . ' abc'),
            'the start field must be digits',
        );
    }

    public function testAReusedPidIsNeverSignalledOnKill(): void
    {
        $key = '/segs/job/seg-00010.ts';
        $this->proc[5001] = self::statLine(5001, 'S', 111);
        $this->alive[5001] = true;
        $registry = $this->registry();
        $registry->register($key, 5001, null, '/segs/job/seg-00010.ts.part-aaaa1111');

        // The PID is reused by an unrelated process between launch and cancel.
        $this->proc[5001] = self::statLine(5001, 'S', 222);

        $registry->kill($key);

        self::assertSame([], $this->signalsFor(5001), 'a reused PID must never be signalled');
        self::assertSame(['/segs/job/seg-00010.ts.part-aaaa1111'], $this->cleaned, 'the launch temp is still cleaned');
    }

    public function testAReusedPidIsDeadForTheWatchdog(): void
    {
        $key = '/segs/job/seg-00011.ts';
        $this->proc[5002] = self::statLine(5002, 'S', 333);
        $this->alive[5002] = true;
        $registry = $this->registry();
        $registry->register($key, 5002, null, '/segs/job/seg-00011.ts.part-bbbb2222');
        $this->proc[5002] = self::statLine(5002, 'S', 444);

        $registry->sweep();

        self::assertSame([], $this->signals);
        self::assertSame(0, $registry->registeredKeyCount(), 'the entry is dropped as dead');
        self::assertSame(['/segs/job/seg-00011.ts.part-bbbb2222'], $this->cleaned);
    }

    public function testAZombieIsDeadEvenThoughTheProbeStillCallsItAlive(): void
    {
        $key = '/segs/job/seg-00012.ts';
        $this->proc[5003] = self::statLine(5003, 'S', 555);
        // posix_kill(0) answers true for a zombie. The `/proc` state is what says dead.
        $this->alive[5003] = true;
        $registry = $this->registry();
        $registry->register($key, 5003, null, '/segs/job/seg-00012.ts.part-cccc3333');
        $this->proc[5003] = self::statLine(5003, 'Z', 555);

        $registry->kill($key);
        self::assertSame([], $this->signalsFor(5003), 'a zombie is never signalled');

        $registry->register($key, 5003, null, '/segs/job/seg-00012.ts.part-cccc3333');
        $registry->sweep();
        self::assertSame(0, $registry->registeredKeyCount(), 'a zombie is dropped by the watchdog');
    }

    public function testTheSameProcessIsStillSignalled(): void
    {
        $key = '/segs/job/seg-00013.ts';
        $this->proc[5004] = self::statLine(5004, 'S', 666);
        $this->alive[5004] = true;
        $registry = $this->registry();
        $registry->register($key, 5004, null, null);

        // SIGTERM ends it, so the grace wait returns at once.
        $this->dieOnSignal = true;
        $registry->kill($key);

        self::assertSame([self::SIGTERM], $this->signalsFor(5004));
    }

    /**
     * Finding 2. The group leader (6001) and two members are snapshotted at TERM time. Before
     * the grace ends, member 6002 exits and its PID is reused by an unrelated process, with a
     * new start time. The SIGKILL step must not reach the reused PID. It still reaches the
     * verified leader and the unchanged member 6003. A process that is not in the TERM-time
     * snapshot is never killed.
     */
    public function testAReusedGroupMemberIsNeverKilledAtTheGrace(): void
    {
        $key = '/segs/job/seg-00020.ts';
        $this->proc[6001] = self::statLine(6001, 'S', 100);
        $this->proc[6002] = self::statLine(6002, 'S', 200);
        $this->proc[6003] = self::statLine(6003, 'S', 300);
        $this->alive[6001] = true;
        $this->members = [6001 => 100, 6002 => 200, 6003 => 300];
        $registry = $this->registry();
        $registry->register($key, 6001, null, null);

        $registry->kill($key); // TERM to the group now; the KILL waits for the sweep.
        self::assertSame([6001], array_column($this->signals, 0), 'kill() signals only the group leader');

        // Member 6002 exits, and an unrelated process takes its PID.
        $this->proc[6002] = self::statLine(6002, 'S', 999);
        // Member 6004 joined the group after the snapshot, so nothing recorded it.
        $this->proc[6004] = self::statLine(6004, 'S', 400);

        $registry->sweep((int) hrtime(true) + self::SEC); // past the 0.5 s grace

        self::assertSame([], $this->signalsFor(6002), 'a reused member PID is never KILLed');
        self::assertSame([], $this->signalsFor(6004), 'a process outside the snapshot is never KILLed');
        self::assertSame(
            [self::SIGTERM, self::SIGKILL],
            $this->signalsFor(6001),
            'the verified leader gets TERM then KILL',
        );
        self::assertSame([self::SIGKILL], $this->signalsFor(6003), 'the unchanged member gets its KILL');
    }

    /**
     * Finding 1. The leader (7001) was registered while `/proc` was unreadable, so no start
     * time is recorded and its identity is 'unchecked'. Its PID has since been reused as a
     * group leader by an unrelated process, and that group now holds member 7002. An
     * unchecked leader is still signalled with TERM, but it gets no membership snapshot, so
     * the SIGKILL step must never reach a process in its group.
     */
    public function testAnUncheckedLeaderGetsNoSnapshotSoItsGroupIsNeverKilled(): void
    {
        $key = '/segs/job/seg-00016.ts';
        // The leader has no `/proc` entry at registration, so its start time is null.
        $this->alive[7001] = true;
        $this->proc[7002] = self::statLine(7002, 'S', 9);
        $this->members = [7002 => 9];
        $registry = $this->registry();
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->register($key, 7001, null, null);

        $registry->kill($key);
        $registry->sweep((int) hrtime(true) + self::SEC); // past the 0.5 s grace

        self::assertSame(
            [self::SIGTERM],
            $this->signalsFor(7001),
            'the unchecked leader gets TERM and no KILL, since its identity is never verified',
        );
        self::assertSame([], $this->signalsFor(7002), 'an unchecked group is never snapshotted, so never KILLed');
    }

    public function testWithoutAProcViewTheBehaviourIsTheUncheckedProbe(): void
    {
        // No `/proc` (non-Linux): nothing is recorded, so the signal is the old one.
        $key = '/segs/job/seg-00014.ts';
        $this->alive[5005] = true;
        $registry = $this->registry();
        $registry->register($key, 5005, null, null);
        $this->alive[5005] = false; // dies on TERM

        $registry->kill($key);

        self::assertSame([self::SIGTERM], $this->signalsFor(5005));
    }

    /**
     * Finding 1 of round 5. Every leader is unchecked: no `/proc` at registration (no start time)
     * and none at TERM. The leader is still alive after TERM. The TERM-time snapshot is then
     * unknown, not empty, so the record is held through the grace and until the hold bound. It keeps
     * its temp and its cap slot. An empty snapshot would drop it one sweep after the grace, while
     * ffmpeg still writes to an unlinked temp. Nothing is KILLed, since no group was snapshotted.
     */
    public function testAnAllUncheckedRecordWithALivePidIsHeldUntilTheBound(): void
    {
        $key = '/segs/job/seg-00018.ts';
        $this->alive[7101] = true;
        $registry = $this->registry();
        $registry->setProcMountedProbe(static fn (): bool => false); // no `/proc` at all
        $registry->register($key, 7101, null, '/segs/job/seg-00018.ts.part-ffff6666');
        $registry->configureWatchdog(30.0, 1.0);
        $registry->configureUnverifiedHold(10.0);

        $start = hrtime(true);
        $registry->sweep($start + 2 * self::SEC); // stalled: TERM goes out (the identity is unchecked)
        self::assertSame([self::SIGTERM], $this->signalsFor(7101), 'an unchecked leader is still signalled with TERM');
        self::assertSame(1, $registry->orphanCount(), 'the reaped record is held as an orphan');

        $registry->sweep($start + 3 * self::SEC); // past the 0.5 s grace, PID still alive
        self::assertSame(1, $registry->orphanCount(), 'a live all-unchecked record is held past the grace');
        self::assertSame([], $this->cleaned, 'its temp is kept while the process may still run');

        $registry->sweep($start + 13 * self::SEC); // 11 s after TERM, past the 10 s hold
        self::assertSame(0, $registry->orphanCount(), 'the record is dropped once the hold bound passes');
        self::assertSame(['/segs/job/seg-00018.ts.part-ffff6666'], $this->cleaned);
        self::assertSame(
            [self::SIGTERM],
            $this->signalsFor(7101),
            'no KILL is sent, since the membership was never snapshotted',
        );
    }

    /**
     * Findings 2 and 5. `/proc` was readable at registration, so the start time is recorded. It
     * is unreadable at signal time, so the identity is unknown. Nothing may be signalled. The
     * record is held through the grace window. Its PID is still alive, so the record keeps
     * its temp and its cap slot past the grace, until the hold bound passes. The warning is
     * logged once for the registry, not once per refusal.
     */
    public function testAnUnreadableProcViewKeepsALiveRecordUntilTheHoldBound(): void
    {
        $key = '/segs/job/seg-00015.ts';
        $this->proc[5006] = self::statLine(5006, 'S', 777);
        $this->alive[5006] = true;
        $logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $registry = $this->registry($logger);
        $registry->register($key, 5006, null, '/segs/job/seg-00015.ts.part-dddd4444');
        $registry->configureWatchdog(30.0, 1.0);
        $registry->configureUnverifiedHold(10.0);

        // `/proc` becomes unreadable: no stat line, and no `/proc` view at all.
        unset($this->proc[5006]);
        $registry->setProcMountedProbe(static fn (): bool => false);

        $start = hrtime(true);
        $registry->sweep($start + 2 * self::SEC); // wall cap: TERM is refused (identity unknown)
        self::assertSame([], $this->signals, 'an unknown identity is never signalled');
        self::assertSame(1, $registry->orphanCount(), 'the record is held through the grace');

        $registry->sweep($start + 2 * self::SEC + intdiv(self::SEC, 10)); // inside the grace
        self::assertSame(1, $registry->orphanCount(), 'still held inside the grace');

        $registry->sweep($start + 3 * self::SEC); // past the 0.5 s grace: KILL refused, PID still alive
        self::assertSame([], $this->signals, 'nothing is signalled while the identity is unknown');
        self::assertSame(1, $registry->orphanCount(), 'a live record keeps its cap slot past the grace');
        self::assertSame([], $this->cleaned, 'its temp is kept while the process may still run');

        $registry->sweep($start + 13 * self::SEC); // 11 s after the TERM at 2 s, past the 10 s hold
        self::assertSame([], $this->signals, 'the hold never signals');
        self::assertSame(0, $registry->orphanCount(), 'the record is dropped once the hold bound passes');
        self::assertSame(['/segs/job/seg-00015.ts.part-dddd4444'], $this->cleaned);
        // The sweep also logs its own "reaping" warning, so count only the identity warning.
        $identityWarnings = array_filter(
            $logger->warnings,
            static fn (string $message): bool => str_contains($message, '/proc unreadable'),
        );
        self::assertCount(1, $identityWarnings, 'the identity warning is logged once per registry');
    }

    /**
     * Finding 2, the other half. A record with an unknown membership snapshot is only held while
     * its PID is still alive. Once the PID is gone, it is dropped at the grace, as before.
     */
    public function testAnUnknownRecordWhosePidDiedIsDroppedAfterTheGrace(): void
    {
        $key = '/segs/job/seg-00017.ts';
        $this->proc[5008] = self::statLine(5008, 'S', 888);
        $this->alive[5008] = true;
        $registry = $this->registry();
        $registry->register($key, 5008, null, '/segs/job/seg-00017.ts.part-eeee5555');
        $registry->configureWatchdog(30.0, 1.0);
        unset($this->proc[5008]);
        $registry->setProcMountedProbe(static fn (): bool => false);

        $start = hrtime(true);
        $registry->sweep($start + 2 * self::SEC); // wall cap: TERM refused, identity unknown
        self::assertSame(1, $registry->orphanCount(), 'the TERM-refused record is an orphan');

        $this->alive[5008] = false; // the process exits on its own
        $registry->sweep($start + 3 * self::SEC); // past the 0.5 s grace

        self::assertSame([], $this->signals, 'nothing is signalled while the identity is unknown');
        self::assertSame(0, $registry->orphanCount(), 'a dead record is dropped at the grace');
        self::assertSame(['/segs/job/seg-00017.ts.part-eeee5555'], $this->cleaned);
    }

    /**
     * A real zombie. The child is killed with SIGKILL and never reaped, so the kernel
     * keeps its entry in state Z. posix_kill(0) still succeeds for it, which is the
     * bug. The registry must call it dead and send nothing.
     */
    public function testARealZombieIsDeadAndIsNeverSignalled(): void
    {
        if (!function_exists('posix_kill') || !is_readable('/proc/self/stat')) {
            self::markTestSkipped('needs posix_kill and /proc');
        }

        $process = proc_open(
            'exec sleep 30',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pid = (int) proc_get_status($process)['pid'];
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        try {
            $signals = [];
            $cleaned = [];
            $registry = new SegmentProcessRegistry(
                null,
                function (int $p, int $s) use (&$signals): void {
                    $signals[] = [$p, $s];
                },
                null, // the real posix_kill probe, the one that calls a zombie alive
                0.5,
                function (string $tmp) use (&$cleaned): void {
                    $cleaned[] = $tmp;
                },
            );
            $key = '/segs/job/seg-00015.ts';
            $registry->register($key, $pid, null, '/segs/job/seg-00015.ts.part-dddd4444');

            posix_kill($pid, self::SIGKILL);
            $state = '';
            for ($i = 0; $i < 60 && $state !== 'Z'; $i++) {
                usleep(50_000);
                $raw = @file_get_contents('/proc/' . $pid . '/stat');
                $parsed = is_string($raw) ? SegmentProcessRegistry::parseProcStat($raw) : null;
                $state = $parsed['state'] ?? '';
            }
            self::assertSame('Z', $state, 'the killed child must be a zombie for this test to mean anything');
            self::assertTrue(posix_kill($pid, 0), 'the bare liveness probe calls a zombie alive');

            $registry->sweep();

            self::assertSame([], $signals, 'a zombie is never signalled');
            self::assertSame(0, $registry->registeredKeyCount(), 'the zombie is dropped as dead');
            self::assertSame(['/segs/job/seg-00015.ts.part-dddd4444'], $cleaned);
        } finally {
            proc_close($process); // reaps the zombie
        }
    }

    /**
     * Round-5 finding 2, option (a). A MIXED record: leader A is verified (its start time still
     * matches), and leader B is unchecked (no start time, as when B exits between shell_exec() and
     * register()). B gets TERM and no KILL, and its group is not snapshotted. Its children survive
     * the TERM, so the record must be held after the grace while B's group still exists, and dropped
     * once that group is gone. A's group is KILLed at the grace as before, and B never is.
     */
    public function testAMixedRecordIsHeldWhileAnUncheckedGroupStillExists(): void
    {
        $key = '/segs/job/seg-00019.ts';
        $tmp = '/segs/job/seg-00019.ts.part-aaaa1111'; // the verified launch (7201)
        $uncheckedTmp = '/segs/job/seg-00019.ts.part-bbbb2222'; // the unchecked launch (7202)
        $this->proc[7201] = self::statLine(7201, 'S', 100);
        $this->alive[7201] = true;
        $this->alive[7202] = false; // B's leader has exited; its children do not
        $this->members = [7201 => 100];
        $this->groupAlive[7202] = true;
        $registry = $this->registry();
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->register($key, 7201, null, $tmp);
        $registry->register($key, 7202, null, $uncheckedTmp); // no `/proc` entry, so unchecked

        $start = (int) hrtime(true);
        $registry->kill($key);
        self::assertSame([self::SIGTERM], $this->signalsFor(7202), 'the unchecked leader gets TERM');

        $registry->sweep($start + self::SEC); // past the 0.5 s grace
        self::assertSame(
            [self::SIGTERM, self::SIGKILL],
            $this->signalsFor(7201),
            'the verified group is KILLed at grace',
        );
        self::assertSame([self::SIGTERM], $this->signalsFor(7202), 'the unchecked group never gets a KILL');
        self::assertSame(1, $registry->orphanCount(), 'held while the unchecked group still exists');
        // Finding 1 (round 7). Before this fix this assertion was assertSame([], ...): the verified
        // launch's temp was held with the whole record, although its leader 7201 was KILLed at the grace.
        // The verified launch's temp is now released at the grace. Only the unchecked launch's temp is held.
        self::assertSame([$tmp], $this->cleaned, 'the verified launch (KILLed at grace) releases its temp');
        self::assertNotContains(
            $uncheckedTmp,
            $this->cleaned,
            'the unchecked launch keeps its temp while its group lives',
        );

        $registry->sweep($start + 2 * self::SEC);
        self::assertSame(1, $registry->orphanCount(), 'still held on a later pass');
        self::assertSame([self::SIGTERM], $this->signalsFor(7202), 'still no KILL to the unchecked group');
        self::assertSame([$tmp], $this->cleaned, 'the unchecked temp is still held on a later pass');

        $this->groupAlive[7202] = false; // its children have exited
        $registry->sweep($start + 3 * self::SEC);
        self::assertSame(0, $registry->orphanCount(), 'dropped once the unchecked group is gone');
        self::assertSame([$tmp, $uncheckedTmp], $this->cleaned, 'the unchecked temp is cleaned when the record drops');
    }

    /**
     * The same mixed record as above, but the unchecked group never dies. The hold is the same bound
     * as the unknown-membership hold, measured from the TERM, so the record is dropped past it.
     */
    public function testAMixedRecordIsDroppedAtTheHoldBoundWhenTheUncheckedGroupSurvives(): void
    {
        $key = '/segs/job/seg-00020.ts';
        $tmp = '/segs/job/seg-00020.ts.part-bbbb2222';
        $this->proc[7211] = self::statLine(7211, 'S', 111);
        $this->alive[7211] = true;
        $this->alive[7212] = false;
        $this->members = [7211 => 111];
        $this->groupAlive[7212] = true; // never dies
        $registry = $this->registry();
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->configureUnverifiedHold(10.0);
        $registry->register($key, 7211, null, $tmp);
        $registry->register($key, 7212, null, null);

        $start = (int) hrtime(true);
        $registry->kill($key);
        $registry->sweep($start + 2 * self::SEC);
        self::assertSame(1, $registry->orphanCount(), 'held inside the hold bound');
        // The verified leader 7211 was KILLed at the grace, so its temp is released there. The
        // unchecked group holds the record, but it holds no temp of its own.
        self::assertSame([$tmp], $this->cleaned, 'the verified launch releases its temp at the grace');

        $registry->sweep($start + 11 * self::SEC); // 11 s after the TERM, past the 10 s bound
        self::assertSame(0, $registry->orphanCount(), 'dropped once the hold bound passes');
        self::assertSame([$tmp], $this->cleaned);
        self::assertSame(
            [self::SIGTERM],
            $this->signalsFor(7212),
            'the unchecked group is never KILLed, even at the bound',
        );
    }

    /**
     * The immediate clean in kill() must not fire while an unchecked group is alive. Here the verified
     * leader is gone and has no members, and the unchecked leader's group still exists. The launch is
     * therefore not cleaned at once, but held as an orphan until the group is gone.
     */
    public function testAGoneVerifiedLeaderDoesNotCleanTheTempWhileAnUncheckedGroupLives(): void
    {
        $key = '/segs/job/seg-00021.ts';
        $tmp = '/segs/job/seg-00021.ts.part-cccc3333';
        $this->proc[7221] = self::statLine(7221, 'S', 121);
        $this->alive[7221] = false; // verified leader is gone
        $this->alive[7222] = false;  // unchecked leader is gone too; its group is not
        $this->groupAlive[7222] = true;
        $registry = $this->registry();
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->register($key, 7221, null, $tmp);
        $registry->register($key, 7222, null, null);
        unset($this->proc[7221]); // the verified leader's /proc entry is now gone

        $registry->kill($key);

        self::assertSame([], $this->cleaned, 'the temp is not removed while the unchecked group lives');
        self::assertSame(1, $registry->orphanCount(), 'the launch is held as an orphan');
        self::assertSame([self::SIGTERM], $this->signalsFor(7222), 'the unchecked leader gets TERM');
        self::assertSame([], $this->signalsFor(7221), 'a gone verified leader is never signalled');

        $this->groupAlive[7222] = false;
        $registry->sweep((int) hrtime(true) + self::SEC);
        self::assertSame(0, $registry->orphanCount());
        self::assertSame([$tmp], $this->cleaned);
    }

    /**
     * Finding 2. Every leader is unchecked (no `/proc` at registration, and none at TERM time). The
     * leader exited on TERM while its children kept running, so its PID is dead, but its group probe
     * still reports the group alive. The record must be held with its temp until the group is gone
     * or the hold bound passes. Before this fix, reapTracked() cleaned it at once, so those children
     * could never be tracked again.
     */
    public function testAnAllUncheckedRecordWhoseLeaderDiedIsHeldWhileItsGroupLives(): void
    {
        $key = '/segs/job/seg-00022.ts';
        $tmp = '/segs/job/seg-00022.ts.part-dddd7777';
        $this->alive[7301] = false; // the leader exited on TERM
        $this->groupAlive[7301] = true; // its children still run
        $registry = $this->registry();
        $registry->setProcMountedProbe(static fn (): bool => false); // no `/proc`: every leader is unchecked
        $registry->register($key, 7301, null, $tmp);
        $registry->configureUnverifiedHold(10.0);

        $start = (int) hrtime(true);
        $registry->kill($key);
        self::assertSame([self::SIGTERM], $this->signalsFor(7301), 'the unchecked leader gets TERM');
        self::assertSame(1, $registry->orphanCount(), 'held at once: its group still exists');
        self::assertSame([], $this->cleaned, 'its temp is kept while the group lives');

        $registry->sweep($start + 2 * self::SEC); // past the 0.5 s grace
        self::assertSame(1, $registry->orphanCount(), 'still held past the grace');
        self::assertSame([], $this->cleaned, 'its temp is still kept');
        self::assertSame([self::SIGTERM], $this->signalsFor(7301), 'no KILL: nothing was snapshotted');

        $registry->sweep($start + 11 * self::SEC); // 11 s after the TERM, past the 10 s bound
        self::assertSame(0, $registry->orphanCount(), 'dropped once the hold bound passes');
        self::assertSame([$tmp], $this->cleaned);
        self::assertSame([self::SIGTERM], $this->signalsFor(7301), 'still no KILL at the bound');
    }

    /**
     * Finding 2, the group-gone half. The same all-unchecked record is dropped at the grace once its
     * group has exited, well inside the hold bound.
     */
    public function testAnAllUncheckedRecordIsDroppedOnceItsGroupIsGone(): void
    {
        $key = '/segs/job/seg-00023.ts';
        $tmp = '/segs/job/seg-00023.ts.part-eeee8888';
        $this->alive[7311] = false;
        $this->groupAlive[7311] = true;
        $registry = $this->registry();
        $registry->setProcMountedProbe(static fn (): bool => false);
        $registry->register($key, 7311, null, $tmp);
        $registry->configureUnverifiedHold(10.0);

        $start = (int) hrtime(true);
        $registry->kill($key);
        $registry->sweep($start + 2 * self::SEC);
        self::assertSame(1, $registry->orphanCount(), 'held while the group lives');

        $this->groupAlive[7311] = false; // the group has exited
        $registry->sweep($start + 3 * self::SEC); // past the grace, well inside the bound
        self::assertSame(0, $registry->orphanCount(), 'dropped once the group is gone');
        self::assertSame([$tmp], $this->cleaned);
        self::assertSame([self::SIGTERM], $this->signalsFor(7311));
    }

    /**
     * Finding 3. The default group-alive probe is `posix_kill(-pid, 0)`, and every other test here
     * injects a fake for it. This test uses the REAL default on a real process group. `setsid` makes
     * the shell the group leader, so PGID == PID. The shell backgrounds a `sleep` and exits, which
     * leaves only the sleep in the group. The leader is reaped by proc_get_status(), so its /proc
     * entry is gone and it is UNCHECKED. The record must be held past the grace while the group
     * lives, and dropped once the hold bound passes. A sign or target error in the default probe
     * (for example `posix_kill(pid, 0)` on the gone leader) would drop the record at the grace and
     * fail here. The TERM goes to a recorder, not to the group, and the group is killed in a finally
     * block, so no sleep process is left behind.
     */
    public function testTheDefaultGroupAliveProbeHoldsARecordWhileARealProcessGroupLives(): void
    {
        if (!function_exists('posix_kill') || !is_readable('/proc/self/stat')) {
            self::markTestSkipped('needs posix_kill and /proc');
        }
        if (!is_executable('/usr/bin/setsid') && !is_executable('/bin/setsid')) {
            self::markTestSkipped('needs setsid(1) to start a process group');
        }

        $process = proc_open(
            "exec setsid sh -c 'sleep 30 >/dev/null 2>&1 & exit 0'",
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pid = (int) proc_get_status($process)['pid'];

        try {
            // Wait for the leader to exit. proc_get_status() reaps it, so its /proc entry is gone.
            $deadline = microtime(true) + 3.0;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running'], 'the leader exits at once');
            self::assertFalse(
                is_readable('/proc/' . $pid . '/stat'),
                'the leader has no /proc entry, so it is unchecked',
            );
            self::assertTrue(posix_kill(-$pid, 0), 'the backgrounded sleep keeps the process group alive');

            $signals = [];
            $cleaned = [];
            // Only the group-alive probe is the real default here. The TERM goes to a recorder.
            $registry = new SegmentProcessRegistry(
                null,
                function (int $p, int $s) use (&$signals): void {
                    $signals[] = [$p, $s];
                },
                null, // the real posix_kill(pid, 0) liveness probe
                0.5,
                function (string $tmp) use (&$cleaned): void {
                    $cleaned[] = $tmp;
                },
            );
            $key = '/segs/job/seg-00024.ts';
            $tmp = '/segs/job/seg-00024.ts.part-ffff9999';
            $registry->register($key, $pid, null, $tmp);
            $registry->configureUnverifiedHold(1.0);

            $start = (int) hrtime(true);
            $registry->kill($key);
            self::assertSame([[$pid, self::SIGTERM]], $signals, 'the unchecked leader gets TERM');
            self::assertSame(1, $registry->orphanCount(), 'held at once: the real group still exists');

            $registry->sweep($start + 800_000_000); // past the 0.5 s grace, inside the 1 s bound
            self::assertSame(1, $registry->orphanCount(), 'held past the grace while the real group lives');
            self::assertSame([], $cleaned, 'its temp is kept while the group lives');

            posix_kill(-$pid, self::SIGKILL); // the sleep ends here
            $registry->sweep($start + 1_500_000_000); // past the 1 s bound
            self::assertSame(0, $registry->orphanCount(), 'dropped once the hold bound passes');
            self::assertSame([$tmp], $cleaned);
            self::assertSame([[$pid, self::SIGTERM]], $signals, 'no other signal was sent');
        } finally {
            // Never leak the sleep, even when an assertion above failed.
            posix_kill(-$pid, self::SIGKILL);
            proc_close($process);
        }
    }
}
