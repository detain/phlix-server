<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Runtime;

use Phlix\Server\Runtime\SwooleRuntime;
use PHPUnit\Framework\TestCase;

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

        // File/proc/curl/stdio + the PDO drivers and net-function hook must all
        // be off. (The blocking-function hook for exec/shell_exec is unnamed in
        // this build — the allowlist excludes it by construction; asserted via
        // the exact-allowlist test below.)
        $unsafeNames = [
            'SWOOLE_HOOK_FILE', 'SWOOLE_HOOK_PROC', 'SWOOLE_HOOK_CURL', 'SWOOLE_HOOK_NATIVE_CURL',
            'SWOOLE_HOOK_STDIO', 'SWOOLE_HOOK_PDO_PGSQL', 'SWOOLE_HOOK_PDO_SQLITE', 'SWOOLE_HOOK_NET_FUNCTION',
        ];
        foreach ($unsafeNames as $unsafe) {
            if (defined($unsafe)) {
                self::assertSame(
                    0,
                    $flags & (int) constant($unsafe),
                    "$unsafe must be excluded from the safe coroutine hook mask",
                );
            }
        }
    }

    public function test_safe_mask_is_exactly_the_network_sleep_allowlist(): void
    {
        if (!defined('SWOOLE_HOOK_TCP')) {
            self::assertSame(0, SwooleRuntime::safeHookFlags());
            self::markTestSkipped('ext-swoole not loaded.');
        }

        $expected = 0;
        foreach (
            [
                'SWOOLE_HOOK_TCP', 'SWOOLE_HOOK_UDP', 'SWOOLE_HOOK_UNIX', 'SWOOLE_HOOK_UDG',
                'SWOOLE_HOOK_SSL', 'SWOOLE_HOOK_TLS', 'SWOOLE_HOOK_STREAM_FUNCTION',
                'SWOOLE_HOOK_SLEEP', 'SWOOLE_HOOK_SOCKETS',
            ] as $name
        ) {
            if (defined($name)) {
                $expected |= (int) constant($name);
            }
        }

        // Exactly the allowlist — nothing more. This is what stops an unnamed
        // bit (e.g. the exec/shell_exec blocking-function hook still set in
        // SWOOLE_HOOK_ALL) from leaking into the mask.
        self::assertSame($expected, SwooleRuntime::safeHookFlags());
        if (defined('SWOOLE_HOOK_ALL')) {
            self::assertLessThan((int) constant('SWOOLE_HOOK_ALL'), SwooleRuntime::safeHookFlags());
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

    public function test_runtime_hook_mask_is_zero_when_coroutines_disabled(): void
    {
        // The adapter ctor installs SWOOLE_HOOK_ALL regardless; a bare
        // resolveHookFlags() call is therefore NOT the worker-safe value —
        // runtimeHookMask() must answer 0 so enforcement unhooks everything.
        self::assertSame(0, SwooleRuntime::runtimeHookMask(['coroutine' => ['enabled' => false]]));
        // The escape hatch must not resurrect hooks the operator disabled.
        self::assertSame(
            0,
            SwooleRuntime::runtimeHookMask(['coroutine' => ['enabled' => false, 'hook_flags' => 0x2]])
        );
    }

    public function test_runtime_hook_mask_resolves_both_enabled_paths(): void
    {
        self::assertSame(SwooleRuntime::safeHookFlags(), SwooleRuntime::runtimeHookMask([]));
        self::assertSame(
            0x2,
            SwooleRuntime::runtimeHookMask(['coroutine' => ['hook_flags' => 0x2]])
        );
        self::assertSame(
            SwooleRuntime::safeHookFlags(),
            SwooleRuntime::runtimeHookMask(['coroutine' => ['enabled' => true]])
        );
    }

    public function test_curl_hook_flags_cover_the_spells_and_never_intersect_the_allowlist(): void
    {
        if (!defined('SWOOLE_HOOK_NATIVE_CURL')) {
            self::markTestSkipped('ext-swoole not loaded — no cURL hook constants to resolve.');
        }

        $curl = SwooleRuntime::curlHookFlags();
        self::assertGreaterThan(0, $curl);
        if (defined('SWOOLE_HOOK_NATIVE_CURL')) {
            self::assertSame((int) SWOOLE_HOOK_NATIVE_CURL, $curl & (int) SWOOLE_HOOK_NATIVE_CURL);
        }
        if (defined('SWOOLE_HOOK_CURL')) {
            self::assertSame((int) SWOOLE_HOOK_CURL, $curl & (int) SWOOLE_HOOK_CURL);
        }

        // The delivery probe keys on exactly these bits — the curated allowlist
        // must exclude ALL of them (this is what makes "did the mask land?"
        // behaviourally observable).
        self::assertSame(
            0,
            SwooleRuntime::safeHookFlags() & $curl,
            'a cURL hook inside the safe mask would blind the behavioural delivery probe'
        );
    }

    public function test_tls_hook_flags_cover_the_tls_stream_bits_this_swoole_exposes(): void
    {
        if (!defined('SWOOLE_HOOK_SSL') || !defined('SWOOLE_HOOK_TLS')) {
            self::markTestSkipped('ext-swoole not loaded — no TLS stream hook constants to resolve.');
        }

        $tls = SwooleRuntime::tlsHookFlags();
        self::assertSame(
            ((int) SWOOLE_HOOK_SSL) | ((int) SWOOLE_HOOK_TLS),
            $tls,
            'both stream-TLS bits must be captured: leaving one in keeps enable_crypto a coroutine call (F-1b)'
        );
        // Unlike the cURL bits (F-1b: the curated mask INCLUDES these — that is
        // exactly the regime defect the relay worker narrows around).
        self::assertNotSame(
            0,
            SwooleRuntime::safeHookFlags() & $tls,
            'if the curated allowlist ever stops containing the TLS bits, the relay narrowing premise changes — re-derive'
        );
    }

    public function test_relay_tunnel_hook_mask_is_the_runtime_mask_minus_tls_bits(): void
    {
        $runtime = SwooleRuntime::runtimeHookMask([]);
        $relay = SwooleRuntime::relayTunnelHookMask([]);

        self::assertSame($runtime & ~SwooleRuntime::tlsHookFlags(), $relay);
        self::assertSame(0, $relay & SwooleRuntime::tlsHookFlags(), 'no TLS stream bit may survive the narrowing');

        // The escape hatch keeps working: an explicit mask narrows too.
        self::assertSame(
            0x2,
            SwooleRuntime::relayTunnelHookMask(['coroutine' => ['hook_flags' => 0x2 | SwooleRuntime::tlsHookFlags()]])
        );
        // A disabled runtime stays fully unhooked (mask 0), TLS bits included.
        self::assertSame(0, SwooleRuntime::relayTunnelHookMask(['coroutine' => ['enabled' => false]]));
    }
}
