<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Runtime;

use Phlix\Server\Runtime\SwooleRuntime;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Server\Runtime\SwooleRuntime
 */
final class SwooleRuntimeTest extends TestCase
{
    public function test_coroutine_enabled_defaults_to_true(): void
    {
        self::assertTrue(SwooleRuntime::coroutineEnabled([]));
        self::assertTrue(SwooleRuntime::coroutineEnabled(['coroutine' => []]));
        self::assertTrue(SwooleRuntime::coroutineEnabled('not-an-array'));
    }

    public function test_coroutine_can_be_hard_disabled(): void
    {
        self::assertFalse(SwooleRuntime::coroutineEnabled(['coroutine' => ['enabled' => false]]));
    }

    public function test_explicit_truthy_enabled_stays_enabled(): void
    {
        self::assertTrue(SwooleRuntime::coroutineEnabled(['coroutine' => ['enabled' => true]]));
    }

    public function test_resolve_hook_flags_honours_an_explicit_int_override(): void
    {
        self::assertSame(
            0x2,
            SwooleRuntime::resolveHookFlags(['coroutine' => ['hook_flags' => 0x2]]),
        );
    }

    public function test_resolve_hook_flags_falls_back_to_the_safe_mask(): void
    {
        self::assertSame(SwooleRuntime::safeHookFlags(), SwooleRuntime::resolveHookFlags([]));
        // A non-int hook_flags is ignored in favour of the safe default.
        self::assertSame(
            SwooleRuntime::safeHookFlags(),
            SwooleRuntime::resolveHookFlags(['coroutine' => ['hook_flags' => 'all']]),
        );
    }

    public function test_safe_mask_drops_every_crash_prone_native_hook(): void
    {
        if (!defined('SWOOLE_HOOK_ALL')) {
            self::assertSame(0, SwooleRuntime::safeHookFlags());
            self::markTestSkipped('ext-swoole not loaded — no hook constants to assert against.');
        }

        $flags = SwooleRuntime::safeHookFlags();

        foreach (['SWOOLE_HOOK_FILE', 'SWOOLE_HOOK_PROC', 'SWOOLE_HOOK_CURL', 'SWOOLE_HOOK_NATIVE_CURL', 'SWOOLE_HOOK_STDIO'] as $unsafe) {
            if (defined($unsafe)) {
                self::assertSame(
                    0,
                    $flags & (int) constant($unsafe),
                    "$unsafe must be excluded from the safe coroutine hook mask",
                );
            }
        }
    }

    public function test_safe_mask_keeps_socket_and_sleep_hooks_for_the_coroutine_pool(): void
    {
        if (!defined('SWOOLE_HOOK_ALL')) {
            self::markTestSkipped('ext-swoole not loaded.');
        }

        $flags = SwooleRuntime::safeHookFlags();

        foreach (['SWOOLE_HOOK_TCP', 'SWOOLE_HOOK_SLEEP'] as $kept) {
            if (defined($kept)) {
                self::assertSame(
                    (int) constant($kept),
                    $flags & (int) constant($kept),
                    "$kept should remain enabled so coroutine network IO still yields",
                );
            }
        }

        // The safe mask must be a strict subset of SWOOLE_HOOK_ALL and non-zero.
        self::assertGreaterThan(0, $flags);
        self::assertSame($flags, $flags & (int) constant('SWOOLE_HOOK_ALL'));
        self::assertNotSame((int) constant('SWOOLE_HOOK_ALL'), $flags);
    }
}
