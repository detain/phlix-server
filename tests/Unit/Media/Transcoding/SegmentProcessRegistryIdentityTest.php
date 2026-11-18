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
}
