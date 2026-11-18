<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * SV-4.2 ([S-F23]): the per-worker detached-encode PID registry. Signals and
 * liveness are injected so no real processes are spawned or killed.
 */
final class SegmentProcessRegistryTest extends TestCase
{
    /** @var array<int, array{pid: int, signal: int}> */
    private array $signals = [];

    /** @var array<int, string> Temp paths the injected temp cleaner was asked to clean. */
    private array $cleaned = [];

    /** @var array<int, string> Fake `/proc/<pid>/stat` lines by PID (the fake process table). */
    private array $proc = [];

    /** @var array<int, int> Fake group membership returned by the probe: PID => start time. */
    private array $members = [];

    private function registry(callable $isAlive): SegmentProcessRegistry
    {
        $this->signals = [];
        $this->cleaned = [];
        $registry = new SegmentProcessRegistry(
            null,
            function (int $pid, int $signal): void {
                $this->signals[] = ['pid' => $pid, 'signal' => $signal];
            },
            $isAlive,
            // Tiny grace period so the TERM→SIGKILL escalation is fast in tests.
            0.05,
            // The cleaner is passed the launcher's OWN `.part-<hex>` temp path
            // (recorded via register()), never a `{$final}.part-*` glob.
            function (string $tmp): void {
                $this->cleaned[] = $tmp;
            },
        );
        // Fake PIDs must never match a real process on this host, so every /proc read and
        // every membership scan is answered from the fakes above. The real /proc scan is
        // covered by the real-process test.
        $registry->setProcStatReader(fn (int $pid): ?string => $this->proc[$pid] ?? null);
        $registry->setGroupMembersProbe(fn (array $leaders): array => $this->members);
        $registry->setProcessSignalSender(function (int $pid, int $signal): void {
            $this->signals[] = ['pid' => $pid, 'signal' => $signal];
        });

        return $registry;
    }

    /**
     * The PIDs that were sent a given signal, in send order.
     *
     * @param array<int, array{pid: int, signal: int}> $sent
     *
     * @return list<int>
     */
    private static function pidsSentWith(array $sent, int $signal): array
    {
        $pids = [];
        foreach ($sent as $entry) {
            if ($entry['signal'] === $signal) {
                $pids[] = $entry['pid'];
            }
        }

        return $pids;
    }

    /**
     * A `/proc/<pid>/stat` line in the real kernel layout, with the state, the process group
     * id and the start time (field 22) set. Every other field is a fixed placeholder.
     */
    private static function stat(int $pid, string $state, int $start, ?int $pgrp = null): string
    {
        $group = (string) ($pgrp ?? $pid);
        $fields = [
            $state, '1', $group, $group, '0', '-1',
            '0', '0', '0', '0', '0', '0', '0', '0', '0',
            '20', '0', '1', '0', (string) $start,
        ];

        return $pid . ' (ffmpeg) ' . implode(' ', $fields) . ' 0 0';
    }

    public function test_register_tracks_pid_under_key(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('seg-a', 4242);

        $this->assertSame([4242], $registry->pidsFor('seg-a'));
        $this->assertSame(1, $registry->registeredKeyCount());
    }

    public function test_register_ignores_nonpositive_pid_and_empty_key(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('seg-a', 0);
        $registry->register('', 99);

        $this->assertSame(0, $registry->registeredKeyCount());
    }

    public function test_release_drops_entry_without_signalling(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => true);
        $registry->register('seg-a', 4242);
        $registry->release('seg-a');

        $this->assertSame(0, $registry->registeredKeyCount(), 'no leak after release');
        $this->assertSame([], $this->signals, 'release must not send any signal');
    }

    public function test_release_of_unknown_key_is_safe_noop(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => true);
        $registry->release('never-registered');
        $this->assertSame(0, $registry->registeredKeyCount());
    }

    public function test_kill_sends_sigterm_when_process_exits_gracefully(): void
    {
        // Process dies right after SIGTERM → no SIGKILL escalation.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('seg-a', 4242);

        $killed = $registry->kill('seg-a');

        $this->assertSame(1, $killed);
        $this->assertCount(1, $this->signals);
        $this->assertSame(4242, $this->signals[0]['pid']);
        $this->assertSame(SIGTERM, $this->signals[0]['signal']);
        $this->assertSame(0, $registry->registeredKeyCount(), 'kill drops the entry — no leak');
    }

    public function testKillEscalatesToSigkillFromTheSweepAfterTheGrace(): void
    {
        // A live leader with one live child in its group. kill() sends TERM and returns at once.
        // The SIGKILL comes from sweep(), once the grace has elapsed, to each member whose
        // recorded start time still matches.
        $registry = $this->registry(static fn (int $pid): bool => true);
        $this->proc[4242] = self::stat(4242, 'S', 100);
        $this->proc[4343] = self::stat(4343, 'S', 200);
        $this->members = [4242 => 100, 4343 => 200];
        $registry->register('seg-a', 4242);

        $registry->kill('seg-a');

        $this->assertSame([['pid' => 4242, 'signal' => SIGTERM]], $this->signals, 'kill() sends TERM only, now');
        $this->assertSame(0, $registry->registeredKeyCount(), 'the key is released at once');
        $this->assertSame(1, $registry->orphanCount(), 'the record waits for the sweep');

        // One second later is past the 0.05 s grace, so this pass is the SIGKILL step.
        $registry->sweep((int) hrtime(true) + 1_000_000_000);

        $kills = self::pidsSentWith($this->signals, SIGKILL);
        $this->assertEqualsCanonicalizing([4242, 4343], $kills, 'each verified member gets one SIGKILL');
        $this->assertSame(0, $registry->orphanCount(), 'the record is dropped after the SIGKILL step');
    }

    public function testKillNeverWaitsForTheGrace(): void
    {
        // The grace is 0.05 s here. A kill with a live member must return well inside it,
        // because the wait now lives in sweep(), not in the request path.
        $registry = $this->registry(static fn (int $pid): bool => true);
        $this->proc[4242] = self::stat(4242, 'S', 100);
        $this->members = [4242 => 100];
        $registry->register('seg-a', 4242);

        $start = hrtime(true);
        $registry->kill('seg-a');
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        $this->assertLessThan(20.0, $elapsedMs, 'kill() must return without the 50 ms grace wait');
        $this->assertSame([SIGTERM], array_map(static fn (array $s): int => $s['signal'], $this->signals));
    }

    public function test_kill_of_unknown_key_returns_zero_and_signals_nothing(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => true);
        $this->assertSame(0, $registry->kill('nope'));
        $this->assertSame([], $this->signals);
    }

    public function test_kill_handles_multiple_pids_for_one_key(): void
    {
        // e.g. an audio + video rendition for the same logical request.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('seg-a', 11);
        $registry->register('seg-a', 22);

        $killed = $registry->kill('seg-a');

        $this->assertSame(2, $killed);
        $pids = array_map(static fn (array $s): int => $s['pid'], $this->signals);
        $this->assertEqualsCanonicalizing([11, 22], $pids);
        $this->assertSame(0, $registry->registeredKeyCount());
    }

    // -------------------------------------------------------------------------
    // SV-4.2 fix: temp cleanup on kill (finding #1)
    // -------------------------------------------------------------------------

    public function test_kill_cleans_only_the_launchers_own_part_temp(): void
    {
        // A signalled encode cannot run its own `|| rm`, so kill must remove the
        // `.part-<hex>` corpse so the cap/dedup globs don't count a dead encode —
        // but ONLY this launcher's own recorded temp, never a `{$final}.part-*`
        // glob (SV-4.2 re-review Low).
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $registry->kill('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(['/tmp/hls/job/seg-00001.ts.part-aaa'], $this->cleaned);
    }

    public function test_kill_of_unknown_key_does_not_clean(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => false);
        $this->assertSame(0, $registry->kill('nope'));
        $this->assertSame([], $this->cleaned, 'nothing killed → nothing to clean');
    }

    // -------------------------------------------------------------------------
    // SV-4.2 fix: release-after-wait-timeout (findings #1 + #2)
    // -------------------------------------------------------------------------

    public function test_release_after_wait_timeout_does_not_kill_a_live_encode(): void
    {
        // The encode is STILL RUNNING (slow-but-wanted). A single request's poll
        // wait timing out is NOT abandonment: we must not signal it, and must not
        // touch its live `.part-*` temp.
        $registry = $this->registry(static fn (int $pid): bool => true);
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $registry->releaseAfterWaitTimeout('/tmp/hls/job/seg-00001.ts');

        $this->assertSame([], $this->signals, 'a slow-but-wanted encode must NOT be killed');
        $this->assertSame([], $this->cleaned, 'a live encode’s temp must NOT be removed');
        $this->assertSame(0, $registry->registeredKeyCount(), 'tracking is still released (no leak)');
    }

    public function test_release_after_wait_timeout_cleans_temp_when_encode_already_dead(): void
    {
        // The encode DIED without publishing (e.g. the timeout backstop signalled
        // it, so its `|| rm` never ran) → clean the orphaned temp, still no kill.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $registry->releaseAfterWaitTimeout('/tmp/hls/job/seg-00001.ts');

        $this->assertSame([], $this->signals, 'release-after-wait-timeout never signals');
        $this->assertSame(['/tmp/hls/job/seg-00001.ts.part-aaa'], $this->cleaned);
        $this->assertSame(0, $registry->registeredKeyCount());
    }

    // -------------------------------------------------------------------------
    // SV-4.2 fix: group kill on cancel (finding #3)
    // -------------------------------------------------------------------------

    public function test_kill_group_kills_every_key_registered_under_the_channel(): void
    {
        // Two segment encodes (video + audio) launched by the same relayed
        // request, grouped under the hub channel/request id.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('/tmp/hls/job/seg-v-00001.ts', 11, '77', '/tmp/hls/job/seg-v-00001.ts.part-vvv');
        $registry->register('/tmp/hls/job/seg-a-00001.ts', 22, '77', '/tmp/hls/job/seg-a-00001.ts.part-aaa');

        $killed = $registry->killGroup('77');

        $this->assertSame(2, $killed, 'both grouped encodes are signalled');
        $pids = array_map(static fn (array $s): int => $s['pid'], $this->signals);
        $this->assertEqualsCanonicalizing([11, 22], $pids);
        $this->assertEqualsCanonicalizing(
            ['/tmp/hls/job/seg-v-00001.ts.part-vvv', '/tmp/hls/job/seg-a-00001.ts.part-aaa'],
            $this->cleaned,
            'each killed segment’s OWN launcher temp is cleaned',
        );
        $this->assertSame(0, $registry->registeredKeyCount(), 'keys dropped — no leak');
        $this->assertSame(0, $registry->registeredGroupCount(), 'group torn down — no leak');
    }

    public function test_kill_group_of_unknown_group_is_zero_and_signals_nothing(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => true);
        $this->assertSame(0, $registry->killGroup('does-not-exist'));
        $this->assertSame([], $this->signals);
        $this->assertSame([], $this->cleaned);
    }

    public function test_release_of_a_grouped_key_tears_down_the_group_link(): void
    {
        // Releasing the only key in a group must drop the group too (no leak), so
        // a later cancel for that channel is a clean no-op.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, '77');
        $this->assertSame(1, $registry->registeredGroupCount());

        $registry->release('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(0, $registry->registeredGroupCount(), 'group link torn down on release');
        $this->assertSame(0, $registry->killGroup('77'), 'cancel after release is a no-op');
        $this->assertSame([], $this->signals);
    }

    public function test_kill_group_leaves_unrelated_group_and_keys_intact(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('/tmp/hls/job/seg-1.ts', 11, 'chan-A');
        $registry->register('/tmp/hls/job/seg-2.ts', 22, 'chan-B');

        $registry->killGroup('chan-A');

        $this->assertSame([11], array_map(static fn (array $s): int => $s['pid'], $this->signals));
        $this->assertSame([22], $registry->pidsFor('/tmp/hls/job/seg-2.ts'), 'other channel untouched');
        $this->assertSame(1, $registry->registeredGroupCount());
    }

    // -------------------------------------------------------------------------
    // SV-4.2 re-review Low: precise-temp cleanup must not destroy a sibling
    // worker's LIVE temp for the same final segment path. Exercises the REAL
    // default temp cleaner against actual files.
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // SV-4.2-disconnect (landmine guard): kill must be waiter-aware. When a
    // SECOND client is still piggybacked on the launcher's shared encode, a
    // cancel of the launcher must NOT signal the encode (it would 404 the other
    // waiter) — it defers, leaving the entry fully tracked. With no other waiter
    // (the common sole-cancel case) it signals + reaps exactly as before.
    // -------------------------------------------------------------------------

    public function test_kill_defers_when_another_waiter_is_present(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setWaiterGuard(static fn (string $key): bool => true); // a piggybacker exists
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $killed = $registry->kill('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(0, $killed, 'no PID signalled while another waiter wants the segment');
        $this->assertSame([], $this->signals, 'the shared encode must NOT be signalled');
        $this->assertSame([], $this->cleaned, 'the live shared temp must NOT be cleaned');
        $this->assertSame(
            [4242],
            $registry->pidsFor('/tmp/hls/job/seg-00001.ts'),
            'the entry is LEFT tracked so the encode keeps running for the other waiter',
        );
        $this->assertSame(1, $registry->registeredKeyCount(), 'deferral must not drop the entry');
    }

    public function test_kill_signals_once_the_other_waiter_has_left(): void
    {
        // Model the count dropping: guard true while B waits, false after B leaves.
        /** @var int $waiters (mutated below; captured by-ref by the guard) */
        $waiters = 2;
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setWaiterGuard(static function (string $key) use (&$waiters): bool {
            return $waiters > 1;
        });
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        // First cancel while B is still a waiter → deferred.
        $this->assertSame(0, $registry->kill('/tmp/hls/job/seg-00001.ts'));
        $this->assertSame([], $this->signals);
        $this->assertSame(1, $registry->registeredKeyCount(), 'still tracked after deferral');

        // B leaves; a subsequent kill now signals + reaps.
        $waiters = 1;
        $killed = $registry->kill('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(1, $killed);
        $this->assertSame([['pid' => 4242, 'signal' => SIGTERM]], $this->signals);
        $this->assertSame(['/tmp/hls/job/seg-00001.ts.part-aaa'], $this->cleaned);
        $this->assertSame(0, $registry->registeredKeyCount(), 'reaped — no leak');
    }

    public function test_kill_signals_when_launcher_is_the_sole_waiter(): void
    {
        // The overwhelmingly common case: one client cancels its own sole encode.
        // The guard reports no OTHER waiter (only the launcher) → signal + reap,
        // exactly as before the guard existed (no relay-cancel regression).
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setWaiterGuard(static fn (string $key): bool => false);
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $killed = $registry->kill('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(1, $killed);
        $this->assertSame([['pid' => 4242, 'signal' => SIGTERM]], $this->signals);
        $this->assertSame(['/tmp/hls/job/seg-00001.ts.part-aaa'], $this->cleaned);
        $this->assertSame(0, $registry->registeredKeyCount());
    }

    public function test_kill_proceeds_to_reap_when_waiter_guard_throws(): void
    {
        // SV-4.2-disconnect F4 fail-safe: a throwing waiter guard must NEVER
        // strand a PID. The guard exception is swallowed (treated as "no other
        // waiter") so the kill still signals + reaps — a lost kill (an orphaned
        // ffmpeg burning CPU) is strictly worse than a rare 404 for a hypothetical
        // piggybacker the guard could not confirm.
        $reaped = [];
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setWaiterGuard(static function (string $key): bool {
            throw new \RuntimeException('waiter guard blew up');
        });
        $registry->setReapCallback(static function (string $key) use (&$reaped): void {
            $reaped[] = $key;
        });
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $killed = $registry->kill('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(1, $killed, 'a throwing guard must not strand the PID — proceed to reap');
        $this->assertSame(
            [['pid' => 4242, 'signal' => SIGTERM]],
            $this->signals,
            'the encode is signalled despite the guard throwing',
        );
        $this->assertSame(
            ['/tmp/hls/job/seg-00001.ts.part-aaa'],
            $this->cleaned,
            'the orphaned temp is cleaned on the fail-safe reap',
        );
        $this->assertSame(
            ['/tmp/hls/job/seg-00001.ts'],
            $reaped,
            'the reap callback fires — a fail-safe reap is a GENUINE reap',
        );
        $this->assertSame(0, $registry->registeredKeyCount(), 'reaped — no leak');
    }

    public function test_kill_group_defers_when_a_second_channel_still_waits(): void
    {
        // Relay-path regression: channel A launched the encode (group 'A' owns the
        // key); channel B requested the same $final and piggybacked (registers no
        // PID/group of its own — only the waiter count reflects it). A being
        // cancelled must NOT kill the encode B still wants. Then, once B leaves,
        // a later cancel of A does signal + reap.
        /** @var int $waiters A (launcher) + B (piggyback); mutated below, captured by-ref by the guard */
        $waiters = 2;
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setWaiterGuard(static function (string $key) use (&$waiters): bool {
            return $waiters > 1;
        });
        $final = '/tmp/hls/job/seg-v-00001.ts';
        $registry->register($final, 4242, 'A', $final . '.part-aaa');

        // Channel A cancelled while B still waits → group kill signals nothing.
        $this->assertSame(0, $registry->killGroup('A'));
        $this->assertSame([], $this->signals, 'B must keep being served');
        $this->assertSame([], $this->cleaned);
        $this->assertSame(1, $registry->registeredKeyCount(), 'encode left running for B');
        $this->assertSame(1, $registry->registeredGroupCount(), 'group link preserved for a later reap');

        // B leaves; A cancels again → now the group kill reaps the encode.
        $waiters = 1;
        $this->assertSame(1, $registry->killGroup('A'));
        $this->assertSame([['pid' => 4242, 'signal' => SIGTERM]], $this->signals);
        $this->assertSame([$final . '.part-aaa'], $this->cleaned);
        $this->assertSame(0, $registry->registeredKeyCount(), 'reaped — no leak');
        $this->assertSame(0, $registry->registeredGroupCount());
    }

    public function test_kill_group_signals_when_sole_channel_waiter(): void
    {
        // No piggybacker: the guard reports no other waiter → the relay HTTP_CANCEL
        // path signals + reaps exactly as today.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setWaiterGuard(static fn (string $key): bool => false);
        $registry->register('/tmp/hls/job/seg-v-00001.ts', 4242, 'A', '/tmp/hls/job/seg-v-00001.ts.part-aaa');

        $this->assertSame(1, $registry->killGroup('A'));
        $this->assertSame([['pid' => 4242, 'signal' => SIGTERM]], $this->signals);
        $this->assertSame(0, $registry->registeredKeyCount());
        $this->assertSame(0, $registry->registeredGroupCount());
    }

    // -------------------------------------------------------------------------
    // SV-4.2-disconnect F1: the reap callback (invalidate the owner's dedup
    // reservation) fires ONLY on a genuine reap — never on the waiter-aware defer,
    // and never when there is nothing to signal.
    // -------------------------------------------------------------------------

    public function test_kill_invokes_reap_callback_on_genuine_reap(): void
    {
        $reaped = [];
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setReapCallback(static function (string $key) use (&$reaped): void {
            $reaped[] = $key;
        });
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $registry->kill('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(['/tmp/hls/job/seg-00001.ts'], $reaped, 'a genuine reap invalidates the reservation');
        $this->assertSame([['pid' => 4242, 'signal' => SIGTERM]], $this->signals);
    }

    public function test_kill_does_not_invoke_reap_callback_when_deferred(): void
    {
        // A piggybacker is still waiting → the kill defers → the encode is still
        // wanted, so the reservation MUST NOT be invalidated.
        $reaped = [];
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setWaiterGuard(static fn (string $key): bool => true);
        $registry->setReapCallback(static function (string $key) use (&$reaped): void {
            $reaped[] = $key;
        });
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242, null, '/tmp/hls/job/seg-00001.ts.part-aaa');

        $this->assertSame(0, $registry->kill('/tmp/hls/job/seg-00001.ts'));
        $this->assertSame([], $reaped, 'a DEFERRED kill must not invalidate the still-wanted reservation');
        $this->assertSame([], $this->signals);
    }

    public function test_kill_does_not_invoke_reap_callback_when_no_pids(): void
    {
        // Nothing tracked under the key → no reap happens → no invalidation (avoids
        // clobbering an unrelated fresh reservation on a stray/duplicate kill).
        $reaped = [];
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setReapCallback(static function (string $key) use (&$reaped): void {
            $reaped[] = $key;
        });

        $this->assertSame(0, $registry->kill('/tmp/hls/job/never-registered.ts'));
        $this->assertSame([], $reaped);
    }

    public function test_kill_with_default_cleaner_removes_only_own_temp_not_sibling(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-sv42-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            $final = $dir . '/seg-00001.ts';
            // Two DISTINCT temps for the SAME final path, as two cross-worker
            // duplicate encodes produce (TranscodeManager tolerates this).
            $ownTmp = $final . '.part-aaaaaaaa';       // THIS worker's temp
            $siblingTmp = $final . '.part-bbbbbbbb';   // another worker's LIVE temp
            file_put_contents($ownTmp, 'own');
            file_put_contents($siblingTmp, 'sibling');

            // Default cleaner (no injected override): unlinks only the recorded temp.
            $registry = new SegmentProcessRegistry(
                null,
                function (int $pid, int $signal): void {
                    $this->signals[] = ['pid' => $pid, 'signal' => $signal];
                },
                static fn (int $pid): bool => false,
                0.05,
            );
            $registry->register($final, 4242, null, $ownTmp);

            $registry->kill($final);

            $this->assertFileDoesNotExist($ownTmp, 'the launcher’s own temp is removed');
            $this->assertFileExists($siblingTmp, 'a sibling worker’s live temp must survive');
            $this->assertFileDoesNotExist($final, 'the final published file is never touched by cleanup');
        } finally {
            foreach (['.part-aaaaaaaa', '.part-bbbbbbbb'] as $shard) {
                if (is_file($final . $shard)) {  // S457: kill() reaps its own temp; the sibling must survive
                    @unlink($final . $shard);
                }
            }
            @rmdir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // Settle gate: a reaped key settles confirmed only when every launch of it is observed gone.
    // The owner (TranscodeManager) frees a reap's ledger slot on a confirmed settle only.
    // -------------------------------------------------------------------------

    /**
     * Record every settle the registry reports, in order.
     *
     * @param array<int, array{0: string, 1: bool}> $settled Receives one [key, confirmed] per settle.
     */
    private function recordSettles(SegmentProcessRegistry $registry, array &$settled): void
    {
        $registry->setSettledCallback(static function (string $key, bool $confirmed) use (&$settled): void {
            $settled[] = [$key, $confirmed];
        });
    }

    public function test_settle_confirms_a_reap_once_its_group_is_observed_gone(): void
    {
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->setGroupAliveProbe(static fn (int $pid): bool => false);
        $this->proc[4242] = self::stat(4242, 'S', 100);
        $this->members = [4242 => 100];
        $settled = [];
        $this->recordSettles($registry, $settled);
        $registry->register('seg-a', 4242);

        $t0 = (int) hrtime(true);
        $registry->kill('seg-a');
        $this->assertSame([], $settled, 'nothing settles at the TERM: the reap waits for the grace tick');

        $registry->sweep($t0 + 1_000_000_000);
        $this->assertSame([['seg-a', true]], $settled, 'the group is observed gone at the grace tick: confirmed');
    }

    public function test_settle_is_unconfirmed_when_the_group_survives_the_kill(): void
    {
        // A D-state child outlives the SIGKILL: the group is still observed alive at every tick.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->setGroupAliveProbe(static fn (int $pid): bool => true);
        $registry->configureUnverifiedHold(1.0);
        $this->proc[4242] = self::stat(4242, 'D', 100);
        $this->members = [4242 => 100];
        $settled = [];
        $this->recordSettles($registry, $settled);
        $registry->register('seg-a', 4242);

        $t0 = (int) hrtime(true);
        $registry->kill('seg-a');
        $registry->sweep($t0 + 1_000_000_000);
        $this->assertSame([4242], self::pidsSentWith($this->signals, SIGKILL), 'the SIGKILL step ran');
        $this->assertSame([], $settled, 'a group still alive after KILL is not observed gone: no settle yet');
        $this->assertSame(1, $registry->orphanCount(), 'the record keeps its slot until the hold bound');

        $registry->sweep($t0 + 2_000_000_000);
        $this->assertSame([['seg-a', false]], $settled, 'past the hold bound the settle is unconfirmed');
        $this->assertSame(0, $registry->orphanCount());
    }

    public function test_settle_is_unconfirmed_when_the_identity_cannot_be_checked(): void
    {
        // The launch's start time was recorded while /proc was readable. At the reap /proc is gone, so its
        // identity is unknown and no membership snapshot exists. The group cannot be checked, so the settle
        // is never confirmed.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setProcMountedProbe(static fn (): bool => false);
        $registry->setGroupAliveProbe(static fn (int $pid): bool => false);
        $this->proc[4242] = self::stat(4242, 'S', 100);
        $settled = [];
        $this->recordSettles($registry, $settled);
        $registry->register('seg-a', 4242);
        $this->proc = [];

        $t0 = (int) hrtime(true);
        $registry->kill('seg-a');
        $this->assertSame([], $settled, 'an unknown identity is not settled confirmed at the reap');

        $registry->sweep($t0 + 1_000_000_000);
        $this->assertSame([['seg-a', false]], $settled, 'an unknown identity settles unconfirmed');
    }

    public function test_a_reap_with_nothing_left_settles_confirmed_at_the_grace_tick(): void
    {
        // No /proc record at all (unchecked), and no live group at the TERM. The group was still signalled, so the
        // confirmation waits for the grace tick, the same observation a launch that survived the TERM gets.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->setGroupAliveProbe(static fn (int $pid): bool => false);
        $settled = [];
        $this->recordSettles($registry, $settled);
        $registry->register('seg-a', 4242);

        $t0 = (int) hrtime(true);
        $registry->kill('seg-a');
        $this->assertSame([], $settled, 'a signalled reap is not settled at the TERM, even with nothing left');
        $this->assertSame(1, $registry->orphanCount(), 'the record is held to the grace tick');

        $registry->sweep($t0 + 1_000_000_000);
        $this->assertSame([['seg-a', true]], $settled, 'the grace tick observes the group gone: confirmed');
        $this->assertSame(0, $registry->orphanCount());
    }

    public function test_a_stall_reap_with_an_unknown_identity_settles_unconfirmed(): void
    {
        // The launch's start time was recorded while /proc was readable. At the stall TERM /proc is unreadable, so
        // no membership snapshot exists and the identity is unknown. The launch then drops at the grace with nothing
        // alive and an empty group. It must still settle UNCONFIRMED, because the identity was never checkable.
        $alive = true;
        $registry = $this->registry(function (int $pid) use (&$alive): bool {
            return $alive;
        });
        $registry->setProcMountedProbe(static fn (): bool => false);
        $registry->setGroupAliveProbe(static fn (int $pid): bool => false);
        $registry->configureWatchdog(1.0, 2.0);
        $this->proc[4242] = self::stat(4242, 'S', 100);   // readable at registration
        $settled = [];
        $this->recordSettles($registry, $settled);
        $registry->register('seg-a', 4242, null, '/tmp/phlix-stall-unused.part-ccc');
        $this->proc = [];                                  // unreadable from the TERM on

        $t0 = (int) hrtime(true);
        $registry->sweep($t0 + 3_000_000_000);             // past the 2 s wall cap: the stall path sends TERM
        $this->assertSame([], $settled, 'the stall reap is not settled at the TERM');
        $this->assertSame(1, $registry->orphanCount());

        $alive = false;
        $registry->sweep($t0 + 4_000_000_000);             // the grace has passed and the launch is gone
        $this->assertSame([['seg-a', false]], $settled, 'an identity unknown at the TERM settles unconfirmed');
    }

    public function test_an_orphan_termed_with_unknown_identity_settles_unconfirmed_at_the_grace(): void
    {
        // A launch held by the watchdog (released alive, no TERM yet). /proc is readable at registration and unreadable
        // from the watchdog's TERM on. The sweep then reaps the ORPHAN on the wall cap (the orphan TERM branch of
        // sweep(), not signalOrphanForStall()). Its identity is unknown at that TERM, so the settle must stay
        // UNCONFIRMED at the grace, even though the group reads gone then.
        $alive = true;
        $registry = $this->registry(function (int $pid) use (&$alive): bool {
            return $alive;
        });
        $registry->setProcMountedProbe(static fn (): bool => false);
        $registry->setGroupAliveProbe(static fn (int $pid): bool => false);
        $registry->configureWatchdog(1.0, 2.0);
        $this->proc[4242] = self::stat(4242, 'S', 100);   // readable at registration
        $settled = [];
        $this->recordSettles($registry, $settled);
        $registry->register('seg-a', 4242, null, '/tmp/phlix-orphan-term-unused.part-ggg');
        $registry->releaseAfterWaitTimeout('seg-a');      // alive: held as an orphan, nothing signalled yet
        $this->assertSame(1, $registry->orphanCount());
        $this->proc = [];                                  // unreadable from the TERM on

        $t0 = (int) hrtime(true);
        $registry->sweep($t0 + 3_000_000_000);             // past the 2 s wall cap: the orphan is TERMed
        $this->assertSame([], $settled, 'the orphan TERM is not a settle');
        $this->assertSame(1, $registry->orphanCount(), 'the orphan is held to its grace tick');

        $alive = false;
        $registry->sweep($t0 + 4_000_000_000);             // the grace has passed; the group reads gone
        $this->assertSame([['seg-a', false]], $settled, 'an orphan TERMed with an unknown identity settles unconfirmed');
        $this->assertSame(0, $registry->orphanCount());
    }

    public function test_a_stall_is_reaped_for_the_stall_reason_while_the_wall_cap_is_not_exceeded(): void
    {
        // No progress for longer than the stall window (1 s), while the launch is younger than the wall cap (2 s).
        // Only the stall branch of watchdogReason() can reap it, so the logged reason must be 'stall'.
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $registry = new SegmentProcessRegistry(
            $logger,
            function (int $pid, int $signal): void {
                $this->signals[] = ['pid' => $pid, 'signal' => $signal];
            },
            static fn (int $pid): bool => true,
            0.05,
            static function (string $tmp): void {
            },
        );
        $registry->setProcStatReader(fn (int $pid): ?string => $this->proc[$pid] ?? null);
        $registry->setGroupMembersProbe(fn (array $leaders): array => $this->members);
        $registry->setProcessSignalSender(function (int $pid, int $signal): void {
            $this->signals[] = ['pid' => $pid, 'signal' => $signal];
        });
        $registry->setProcMountedProbe(static fn (): bool => true);
        $registry->setProgressProbe(static fn (array $tmps): int => 0);   // the progress token never moves
        $registry->configureWatchdog(1.0, 2.0);
        $this->proc[4242] = self::stat(4242, 'S', 100);

        $t0 = (int) hrtime(true);
        $registry->register('seg-a', 4242, null, '/tmp/phlix-stall-reason-unused.part-hhh');
        $registry->sweep($t0 + 1_500_000_000);             // 1.5 s: past the stall window, inside the wall cap

        $this->assertSame([['pid' => 4242, 'signal' => SIGTERM]], $this->signals, 'the stalled launch gets its TERM');
        $reasons = [];
        foreach ($logger->records as $record) {
            if ($record['message'] === 'SegmentProcessRegistry: reaping stalled segment encode') {
                $reasons[] = $record['context']['reason'] ?? null;
            }
        }
        $this->assertSame(['stall'], $reasons, 'the reap is the stall branch, not the wall-clock cap');
    }

    public function test_without_posix_and_without_proc_the_pid_counts_as_alive(): void
    {
        // Neither the signal-0 probe (posix missing) nor /proc (unreadable) can show the PID gone, so it is ALIVE.
        // The launch is therefore held and not released early.
        $registry = new SegmentProcessRegistry(
            null,
            null,
            null,
            0.05,
            static function (string $tmp): void {
            },
        );
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $registry->setSignalZeroProbe(static fn (int $target): ?int => null);   // no posix
        $registry->setProcMountedProbe(static fn (): bool => false);            // no /proc either
        $registry->register('seg-a', 4242, null, '/tmp/phlix-no-posix-unused.part-iii');

        $registry->releaseAfterWaitTimeout('seg-a');
        $this->assertSame(1, $registry->orphanCount(), 'no posix and no /proc: the PID is alive, so the launch is held');
    }

    public function test_without_posix_the_pid_falls_back_to_proc_while_proc_is_readable(): void
    {
        // The /proc fallback still decides when /proc is readable. A PID that has no /proc entry is gone. A PID far above
        // any pid_max is used so no real process can match it.
        $registry = new SegmentProcessRegistry(
            null,
            null,
            null,
            0.05,
            static function (string $tmp): void {
            },
        );
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $registry->setSignalZeroProbe(static fn (int $target): ?int => null);   // no posix
        $registry->setProcMountedProbe(static fn (): bool => true);             // /proc readable
        $registry->register('seg-a', 2000000000, null, '/tmp/phlix-proc-fallback-unused.part-jjj');

        $registry->releaseAfterWaitTimeout('seg-a');
        $this->assertSame(0, $registry->orphanCount(), 'no posix, /proc readable, no such PID: gone, nothing held');
    }

    /**
     * Shared body for the signal-0 classification tests: a launch is alive, its leader then exits, and the group's
     * signal-0 result is `$groupErrno` (null for a missing posix extension). Returns the settles reported.
     *
     * @return array<int, array{0: string, 1: bool}>
     */
    private function settleWithGroupSignalZero(?int $groupErrno): array
    {
        $alive = true;
        $registry = $this->registry(function (int $pid) use (&$alive): bool {
            return $alive;
        });
        $registry->setSignalZeroProbe(static fn (int $target): ?int => $groupErrno);
        $settled = [];
        $this->recordSettles($registry, $settled);
        $registry->configureUnverifiedHold(10.0);
        $registry->register('seg-a', 4242, null, '/tmp/phlix-signal-zero-unused.part-ddd');
        $registry->releaseAfterWaitTimeout('seg-a');   // alive: held by the watchdog with no TERM

        $alive = false;
        $t0 = (int) hrtime(true);
        $registry->sweep($t0);                          // the leader is gone: the group decides the settle
        // A group that reads alive is held (its temp too) until the hold bound, then settles unconfirmed.
        $registry->sweep($t0 + 11_000_000_000);
        return $settled;
    }

    public function test_eperm_on_the_pid_probe_counts_as_alive(): void
    {
        // The default liveness probe, reading a signal-0 EPERM: the process exists and is not ours. It is alive.
        $registry = new SegmentProcessRegistry(
            null,
            null,
            null,
            0.05,
            static function (string $tmp): void {
            },
        );
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $registry->setSignalZeroProbe(static fn (int $target): int => 1);   // EPERM for every target
        $registry->register('seg-a', 4242, null, '/tmp/phlix-eperm-unused.part-eee');

        $registry->releaseAfterWaitTimeout('seg-a');
        $this->assertSame(1, $registry->orphanCount(), 'EPERM: the PID is alive, so the launch is held');
    }

    public function test_esrch_on_the_pid_probe_counts_as_gone(): void
    {
        $registry = new SegmentProcessRegistry(
            null,
            null,
            null,
            0.05,
            static function (string $tmp): void {
            },
        );
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $registry->setSignalZeroProbe(static fn (int $target): int => 3);   // ESRCH for every target
        $registry->register('seg-a', 4242, null, '/tmp/phlix-esrch-unused.part-fff');

        $registry->releaseAfterWaitTimeout('seg-a');
        $this->assertSame(0, $registry->orphanCount(), 'ESRCH: the PID is gone, so nothing is held');
    }

    public function test_eperm_on_the_group_probe_keeps_the_settle_unconfirmed(): void
    {
        // The group has a member this process may not signal. The group is alive, so the settle is not confirmed.
        $this->assertSame([['seg-a', false]], $this->settleWithGroupSignalZero(1), 'EPERM: the group is alive');
    }

    public function test_esrch_on_the_group_probe_confirms_the_settle(): void
    {
        $this->assertSame([['seg-a', true]], $this->settleWithGroupSignalZero(3), 'ESRCH: the group is observed gone');
    }

    public function test_without_posix_the_group_is_alive_and_the_settle_is_unconfirmed(): void
    {
        // A missing posix extension observes nothing, so the group must read as alive: the settle is not confirmed.
        $this->assertSame([['seg-a', false]], $this->settleWithGroupSignalZero(null), 'no posix: never confirmed');
    }

    public function test_a_dead_leader_with_a_live_group_holds_its_temp_and_settles_unconfirmed_at_the_hold_bound(): void
    {
        // A launch released alive (no TERM, held by the watchdog). Its leader then exits, but its group still
        // has a member. The temp is the path the publish needs, so it is held while the group is alive, and the
        // settle waits with it. Only the hold bound, with the group still alive, settles it unconfirmed.
        $alive = true;
        $registry = $this->registry(function (int $pid) use (&$alive): bool {
            return $alive;
        });
        $registry->setGroupAliveProbe(static fn (int $pid): bool => true);
        $registry->configureUnverifiedHold(10.0);
        $settled = [];
        $this->recordSettles($registry, $settled);
        $tmp = '/tmp/phlix-settle-unused.part-aaa';
        $registry->register('seg-a', 4242, null, $tmp);
        $registry->releaseAfterWaitTimeout('seg-a');
        $this->assertSame(1, $registry->orphanCount(), 'a live launch is handed to the watchdog');

        $alive = false;
        $t0 = (int) hrtime(true);
        $registry->sweep($t0);
        $this->assertSame([], $this->cleaned, 'the leader is gone but its group is alive: the temp is held');
        $this->assertSame([], $settled, 'the settle waits while the group is alive');
        $this->assertSame(1, $registry->orphanCount(), 'the record is held with its temp');

        $registry->sweep($t0 + 10_000_000_000);
        $this->assertSame([$tmp], $this->cleaned, 'past the hold bound the temp is removed');
        $this->assertSame([['seg-a', false]], $settled, 'the group never read gone: unconfirmed');
        $this->assertSame(0, $registry->orphanCount());
    }

    public function test_a_dead_leader_with_a_live_group_removes_its_temp_when_the_group_is_gone_at_a_later_tick(): void
    {
        $alive = true;
        $groupAlive = true;
        $registry = $this->registry(function (int $pid) use (&$alive): bool {
            return $alive;
        });
        $registry->setGroupAliveProbe(function (int $pid) use (&$groupAlive): bool {
            return $groupAlive;
        });
        $settled = [];
        $this->recordSettles($registry, $settled);
        $tmp = '/tmp/phlix-held-release-unused.part-ccc';
        $registry->register('seg-a', 4242, null, $tmp);
        $registry->releaseAfterWaitTimeout('seg-a');

        $alive = false;
        $t0 = (int) hrtime(true);
        $registry->sweep($t0);
        $this->assertSame([], $this->cleaned, 'the group is alive at the first tick: the temp is held');
        $this->assertSame(1, $registry->orphanCount());

        $groupAlive = false;
        $registry->sweep($t0 + 1_000_000_000);
        $this->assertSame([$tmp], $this->cleaned, 'the group is observed gone at a later tick: the temp is removed');
        $this->assertSame([['seg-a', true]], $settled, 'the group is observed gone: confirmed');
        $this->assertSame(0, $registry->orphanCount());
    }

    public function test_a_dead_leader_with_no_group_members_removes_its_temp_at_once(): void
    {
        // The inverse: nothing in the group runs, so the temp and the record go at the first tick, as before.
        $alive = true;
        $registry = $this->registry(function (int $pid) use (&$alive): bool {
            return $alive;
        });
        $registry->setGroupAliveProbe(static fn (int $pid): bool => false);
        $settled = [];
        $this->recordSettles($registry, $settled);
        $tmp = '/tmp/phlix-settle-unused.part-bbb';
        $registry->register('seg-a', 4242, null, $tmp);
        $registry->releaseAfterWaitTimeout('seg-a');

        $alive = false;
        $registry->sweep((int) hrtime(true));
        $this->assertSame([$tmp], $this->cleaned, 'the leader and its group are gone: the temp is removed at once');
        $this->assertSame([['seg-a', true]], $settled, 'the leader and its group are gone: confirmed');
        $this->assertSame(0, $registry->orphanCount());
    }

    public function test_an_unconfirmed_orphan_taints_a_later_confirmed_settle_of_its_key(): void
    {
        // Two launches of one key. Launch A is dropped UNCONFIRMED while launch B is still held, so no settle
        // is reported yet. When B later confirms, the key's settle must still be unconfirmed, or the owner would
        // free A's slot while A's group may still run.
        $alive = true;
        $groupAlive = [4242, 4343];
        $registry = $this->registry(function (int $pid) use (&$alive): bool {
            return $alive;
        });
        $registry->setGroupAliveProbe(function (int $pid) use (&$groupAlive): bool {
            return in_array($pid, $groupAlive, true);
        });
        $settled = [];
        $this->recordSettles($registry, $settled);

        $registry->configureUnverifiedHold(10.0);
        $registry->register('seg-a', 4242);
        $registry->releaseAfterWaitTimeout('seg-a');   // launch A: alive, held by the watchdog with no TERM
        $registry->register('seg-a', 4343);            // launch B of the same key
        $t0 = (int) hrtime(true);
        $registry->kill('seg-a');                      // launch B is reaped (TERM), and it is still alive
        $alive = false;

        // Pass 1: A's leader is gone but its group is alive, so A is held. B's group is alive too, so B is held.
        $registry->sweep($t0 + 1_000_000_000);
        $this->assertSame([], $settled, 'the key is not settled while launches A and B are held');

        // Pass 2: B passes its hold bound with its group still alive, so B drops UNCONFIRMED. A is still held.
        $registry->sweep($t0 + 10_500_000_000);
        $this->assertSame([], $settled, 'the key is not settled while launch A is still held');
        $this->assertSame(1, $registry->orphanCount(), 'only launch A remains');

        // Pass 3: A's group is gone, so A drops confirmed. The key is still reported unconfirmed.
        $groupAlive = [];
        $registry->sweep($t0 + 10_800_000_000);
        $this->assertSame([['seg-a', false]], $settled, 'an earlier unconfirmed launch taints the key settle');
    }
}
