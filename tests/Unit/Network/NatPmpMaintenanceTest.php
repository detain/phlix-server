<?php

/**
 * Phlix media server test: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\NatPmpMaintenance;

/**
 * Unit pins for the pure NAT-PMP maintenance decisions: the §3.3 renewal
 * state machine, the §3.2.1 announcement parser, reboot detection and the
 * failure backoff curve. No sockets, no files, no clocks — which is exactly
 * the point of extracting NatPmpMaintenance from the worker.
 *
 * RFC: https://www.rfc-editor.org/rfc/rfc6886.txt (§3.2, §3.2.1, §3.3)
 *
 * @see \Phlix\Network\NatPmpMaintenance
 */
final class NatPmpMaintenanceTest extends TestCase
{
    private const NOW = 1_760_000_000;

    /**
     * Build a minimally-valid enabled NAT-PMP state array.
     *
     * @return array<string,mixed>
     */
    private static function natpmpState(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'method' => 'natpmp',
            'external_ip' => '203.0.113.7',
            'port' => 32400,
            'gateway_ip' => '192.168.1.1',
            'mapping_granted_lifetime' => 7200,
            'mapping_renew_at' => self::NOW + 3600,
        ], $overrides);
    }

    /**
     * §3.2.1 gratuitous address response: version 0, opcode 128, result 0,
     * SSSoE (4B), IPv4 (4B) — 12 bytes on the wire.
     */
    private static function announcement(string $ip, int $sssoe): string
    {
        return chr(0) . chr(128) . pack('n', 0) . pack('N', $sssoe) . inet_pton($ip);
    }

    // ==================================================================
    // plan()
    // ==================================================================

    public function test_plan_skips_when_no_state_file_exists(): void
    {
        self::assertSame(
            NatPmpMaintenance::ACTION_SKIP_NO_STATE,
            NatPmpMaintenance::plan(null, self::NOW),
            'Port-forwarding was never configured: the tick must no-op cleanly.'
        );
    }

    public function test_plan_never_resurrects_a_disabled_mapping(): void
    {
        // disable() writes enabled:false with nulled deadlines; plan must
        // stop there even if method still reads 'natpmp'.
        $disabled = self::natpmpState([
            'enabled' => false,
            'mapping_renew_at' => self::NOW - 1,
        ]);
        self::assertSame(NatPmpMaintenance::ACTION_SKIP_DISABLED, NatPmpMaintenance::plan($disabled, self::NOW));

        // A hand-edited / legacy file without the key is treated the same way
        // — only literal `true` counts as enabled.
        $missingFlag = self::natpmpState();
        unset($missingFlag['enabled']);
        self::assertSame(NatPmpMaintenance::ACTION_SKIP_DISABLED, NatPmpMaintenance::plan($missingFlag, self::NOW));
    }

    public function test_plan_ignores_non_natpmp_methods(): void
    {
        // IGD leases are permanent router rules; STUN observed nothing. The
        // §3.3 half-lifetime law belongs to NAT-PMP grants only — even when
        // such a state file carries a stale deadline.
        foreach (['upnp', 'stun-already-open', 'manual', null] as $method) {
            $state = self::natpmpState([
                'method' => $method,
                'mapping_renew_at' => self::NOW - 1,
            ]);
            self::assertSame(
                NatPmpMaintenance::ACTION_SKIP_NOT_NATPMP,
                NatPmpMaintenance::plan($state, self::NOW),
                "method=" . var_export($method, true) . ' must never schedule a NAT-PMP renewal.'
            );
        }
    }

    public function test_plan_backoff_beats_the_deadline(): void
    {
        // Renewal is due AND a backoff slot is armed: wait. This precedence
        // is the anti-hot-loop guarantee — an unreachable gateway gets one
        // bounded exchange per slot, not one per tick.
        $state = self::natpmpState([
            'mapping_renew_at' => self::NOW - 30,
            'mapping_retry_at' => self::NOW + 45,
        ]);
        self::assertSame(NatPmpMaintenance::ACTION_WAIT_BACKOFF, NatPmpMaintenance::plan($state, self::NOW));

        // The instant the slot passes, the due deadline wins again.
        $expired = self::natpmpState([
            'mapping_renew_at' => self::NOW - 30,
            'mapping_retry_at' => self::NOW - 1,
        ]);
        self::assertSame(NatPmpMaintenance::ACTION_RENEW, NatPmpMaintenance::plan($expired, self::NOW));

        // A null retry (success cleared it) never blocks.
        $cleared = self::natpmpState([
            'mapping_renew_at' => self::NOW,
            'mapping_retry_at' => null,
        ]);
        self::assertSame(NatPmpMaintenance::ACTION_RENEW, NatPmpMaintenance::plan($cleared, self::NOW));
    }

    public function test_plan_waits_inside_the_halfway_window_and_renews_at_the_deadline(): void
    {
        // RFC 6886 §3.3 lines 679-681: renewal begins halfway to expiry.
        // The persisted deadline IS the halfway point (granted/2 is computed
        // by PortForwardService); plan's job is purely vs/now.
        $early = self::natpmpState(['mapping_renew_at' => self::NOW + 1]);
        self::assertSame(NatPmpMaintenance::ACTION_WAIT_NOT_DUE, NatPmpMaintenance::plan($early, self::NOW));

        $exactlyDue = self::natpmpState(['mapping_renew_at' => self::NOW]);
        self::assertSame(
            NatPmpMaintenance::ACTION_RENEW,
            NatPmpMaintenance::plan($exactlyDue, self::NOW),
            'now == deadline is DUE (>=), not "wait one more tick".'
        );

        $overdue = self::natpmpState(['mapping_renew_at' => self::NOW - 86_400]);
        self::assertSame(NatPmpMaintenance::ACTION_RENEW, NatPmpMaintenance::plan($overdue, self::NOW));
    }

    public function test_plan_skips_state_without_a_usable_deadline(): void
    {
        // A file written before gateway/renew bookkeeping, or hand-edited:
        // renewing without a deadline would be guessing. No-op honestly.
        $missing = self::natpmpState();
        unset($missing['mapping_renew_at']);
        self::assertSame(NatPmpMaintenance::ACTION_SKIP_NO_DEADLINE, NatPmpMaintenance::plan($missing, self::NOW));

        foreach (['nope', 12.5, null, true] as $junk) {
            $state = self::natpmpState(['mapping_renew_at' => $junk]);
            self::assertSame(
                NatPmpMaintenance::ACTION_SKIP_NO_DEADLINE,
                NatPmpMaintenance::plan($state, self::NOW),
                'Non-int deadlines must not schedule anything: ' . var_export($junk, true)
            );
        }
    }

    // ==================================================================
    // parseAnnouncement()
    // ==================================================================

    public function test_parse_reads_a_conformant_announcement(): void
    {
        $parsed = NatPmpMaintenance::parseAnnouncement(self::announcement('203.0.113.9', 12345));

        self::assertIsArray($parsed);
        self::assertSame('203.0.113.9', $parsed['external_ip']);
        self::assertSame(12345, $parsed['seconds_since_epoch']);
    }

    public function test_parse_reads_high_bits_without_sign_tricks(): void
    {
        // 0xFFFFFFFF SSSoE + 255.255.255.255 exercise the upper end of the
        // N/n unpacking on 64-bit PHP.
        $parsed = NatPmpMaintenance::parseAnnouncement(self::announcement('255.255.255.255', 4_294_967_295));

        self::assertIsArray($parsed);
        self::assertSame('255.255.255.255', $parsed['external_ip']);
        self::assertSame(4_294_967_295, $parsed['seconds_since_epoch']);
    }

    public function test_parse_accepts_zero_address_announcements_as_data(): void
    {
        // A gateway mid-boot can announce 0.0.0.0 with result 0. The parser
        // stays honest (it is a parser); decideChange/reconfigure treats it
        // as a contradicting address, which is TRUE — the mapping IS gone.
        $parsed = NatPmpMaintenance::parseAnnouncement(self::announcement('0.0.0.0', 3));

        self::assertIsArray($parsed);
        self::assertSame('0.0.0.0', $parsed['external_ip']);
    }

    public function test_parse_rejects_every_non_announcement_shape(): void
    {
        $rejects = [
            'empty' => '',
            'truncated 11 bytes' => substr(self::announcement('203.0.113.9', 5), 0, 11),
            // §3.3 mapping REPLY unsolicited (opcode 0x80|1): not an
            // announcement — we only parse the §3.2 address form.
            'mapping reply opcode 129' => chr(0) . chr(129) . pack('n', 0) . pack('N', 5) . inet_pton('10.0.0.1'),
            'request opcode 0' => chr(0) . chr(0) . pack('n', 0) . pack('N', 5) . inet_pton('10.0.0.1'),
            'version 1 (PCP prefix)' => chr(1) . chr(128) . pack('n', 0) . pack('N', 5) . inet_pton('10.0.0.1'),
            // §3.2: non-zero result means "no address available".
            'result DENIED (1)' => chr(0) . chr(128) . pack('n', 1) . pack('N', 5) . inet_pton('10.0.0.1'),
            'result UNKNOWN_OPCODE (2)' => chr(0) . chr(128) . pack('n', 2) . pack('N', 5) . inet_pton('10.0.0.1'),
            'network garbage' => str_repeat("\xAB", 40),
        ];

        foreach ($rejects as $label => $datagram) {
            self::assertNull(
                NatPmpMaintenance::parseAnnouncement($datagram),
                "Must drop {$label}: untrusted LAN bytes parse to typed data or nothing."
            );
        }
    }

    public function test_parse_tolerates_trailing_bytes(): void
    {
        // UDP payloads can carry trailing padding; the first 12 bytes are the
        // whole address response. Length guard is >= 12, not == 12.
        $parsed = NatPmpMaintenance::parseAnnouncement(self::announcement('198.51.100.4', 42) . "\x00\x00");

        self::assertIsArray($parsed);
        self::assertSame('198.51.100.4', $parsed['external_ip']);
        self::assertSame(42, $parsed['seconds_since_epoch']);
    }

    // ==================================================================
    // detectChange()
    // ==================================================================

    public function test_first_observation_cannot_detect_a_change(): void
    {
        // Null references have nothing to contradict: establishing the
        // baseline is not itself evidence of a reboot.
        self::assertFalse(NatPmpMaintenance::detectChange(null, null, '203.0.113.7', 999_999));
        self::assertFalse(NatPmpMaintenance::detectChange('203.0.113.7', null, '203.0.113.7', 1));

        // But the SSSoE law is SELF-STANDING: with a prior observation it
        // needs no cached IP to prove a restart (a monotonic counter
        // regressed — the clock restarted — whatever the address says).
        self::assertTrue(NatPmpMaintenance::detectChange(null, 500, '203.0.113.7', 10));
    }

    public function test_address_mismatch_is_a_change_even_advancing_sssoe(): void
    {
        // Re-assign: gateway kept running but the WAN address moved.
        self::assertTrue(NatPmpMaintenance::detectChange('203.0.113.7', 100, '198.51.100.2', 5000));
    }

    public function test_sssoe_regression_is_a_reboot_at_the_same_address(): void
    {
        // The sneaky one: same IP after reboot. Only Seconds-Since-Start-of-
        // Epoch proves the box restarted and every mapping in it is gone.
        self::assertTrue(NatPmpMaintenance::detectChange('203.0.113.7', 86_400, '203.0.113.7', 12));

        // Advancing (or equal) SSSoE at the same address is routine.
        self::assertFalse(NatPmpMaintenance::detectChange('203.0.113.7', 86_400, '203.0.113.7', 86_401));
        self::assertFalse(NatPmpMaintenance::detectChange('203.0.113.7', 86_400, '203.0.113.7', 86_400));
    }

    // ==================================================================
    // nextRetryDelay()
    // ==================================================================

    public function test_backoff_curve_is_exponential_then_bounded(): void
    {
        $curve = [
            // failures => delay
            -5 => NatPmpMaintenance::RETRY_BASE_SECONDS,
            0 => NatPmpMaintenance::RETRY_BASE_SECONDS,
            1 => NatPmpMaintenance::RETRY_BASE_SECONDS,
            2 => 120,
            3 => 240,
            4 => 480,
            5 => NatPmpMaintenance::RETRY_MAX_SECONDS, // 960 -> clamped to 900
            6 => NatPmpMaintenance::RETRY_MAX_SECONDS,
            64 => NatPmpMaintenance::RETRY_MAX_SECONDS,
            100_000 => NatPmpMaintenance::RETRY_MAX_SECONDS,
        ];

        foreach ($curve as $failures => $expected) {
            self::assertSame(
                $expected,
                NatPmpMaintenance::nextRetryDelay((int) $failures),
                "delay after {$failures} consecutive failures"
            );
        }
    }
}
