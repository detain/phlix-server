<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.2 ([S-F23]): the per-worker detached-encode PID registry. Signals and
 * liveness are injected so no real processes are spawned or killed.
 */
final class SegmentProcessRegistryTest extends TestCase
{
    /** @var array<int, array{pid: int, signal: int}> */
    private array $signals = [];

    /** @var array<int, string> Keys the injected temp cleaner was asked to clean. */
    private array $cleaned = [];

    private function registry(callable $isAlive): SegmentProcessRegistry
    {
        $this->signals = [];
        $this->cleaned = [];
        return new SegmentProcessRegistry(
            null,
            function (int $pid, int $signal): void {
                $this->signals[] = ['pid' => $pid, 'signal' => $signal];
            },
            $isAlive,
            // Tiny grace period so the SIGTERM→SIGKILL escalation is fast in tests.
            0.05,
            function (string $key): void {
                $this->cleaned[] = $key;
            },
        );
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

    public function test_kill_escalates_to_sigkill_when_still_alive(): void
    {
        // Always-alive process forces the SIGTERM → SIGKILL escalation.
        $registry = $this->registry(static fn (int $pid): bool => true);
        $registry->register('seg-a', 4242);

        $registry->kill('seg-a');

        $signals = array_map(static fn (array $s): int => $s['signal'], $this->signals);
        $this->assertContains(SIGTERM, $signals);
        $this->assertContains(SIGKILL, $signals);
        $this->assertSame(0, $registry->registeredKeyCount());
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

    public function test_kill_cleans_the_orphaned_part_temp(): void
    {
        // A signalled encode cannot run its own `|| rm`, so kill must remove the
        // `.part-*` corpse so the cap/dedup globs don't count a dead encode.
        $registry = $this->registry(static fn (int $pid): bool => false);
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242);

        $registry->kill('/tmp/hls/job/seg-00001.ts');

        $this->assertSame(['/tmp/hls/job/seg-00001.ts'], $this->cleaned);
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
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242);

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
        $registry->register('/tmp/hls/job/seg-00001.ts', 4242);

        $registry->releaseAfterWaitTimeout('/tmp/hls/job/seg-00001.ts');

        $this->assertSame([], $this->signals, 'release-after-wait-timeout never signals');
        $this->assertSame(['/tmp/hls/job/seg-00001.ts'], $this->cleaned);
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
        $registry->register('/tmp/hls/job/seg-v-00001.ts', 11, '77');
        $registry->register('/tmp/hls/job/seg-a-00001.ts', 22, '77');

        $killed = $registry->killGroup('77');

        $this->assertSame(2, $killed, 'both grouped encodes are signalled');
        $pids = array_map(static fn (array $s): int => $s['pid'], $this->signals);
        $this->assertEqualsCanonicalizing([11, 22], $pids);
        $this->assertEqualsCanonicalizing(
            ['/tmp/hls/job/seg-v-00001.ts', '/tmp/hls/job/seg-a-00001.ts'],
            $this->cleaned,
            'each killed segment’s temp is cleaned',
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
}
