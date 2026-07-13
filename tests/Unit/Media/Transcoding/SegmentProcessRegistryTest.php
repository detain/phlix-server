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

    private function registry(callable $isAlive): SegmentProcessRegistry
    {
        $this->signals = [];
        return new SegmentProcessRegistry(
            null,
            function (int $pid, int $signal): void {
                $this->signals[] = ['pid' => $pid, 'signal' => $signal];
            },
            $isAlive,
            // Tiny grace period so the SIGTERM→SIGKILL escalation is fast in tests.
            0.05,
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
}
