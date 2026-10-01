<?php

/**
 * Phlix media server component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Network;

/**
 * Pure decision core for NAT-PMP mapping maintenance (RFC 6886).
 *
 * Everything that decides WHEN to renew, HOW LONG to back off, WHETHER an
 * inbound §3.2.1 announcement means "the gateway rebooted", and HOW to parse
 * that announcement lives here — with no sockets, no files and no clocks.
 * {@see NatPmpMaintenanceWorker} owns the sockets and the timers,
 * {@see PortForwardService} owns the persisted state; both consult this class
 * so the decision logic is unit-testable without forking anything.
 *
 * ## Why these decisions are separable from the IO
 *
 * RFC 6886 §3.3 makes renewal timing a property of the persisted grant
 * (`mapping_renew_at` halfway to expiry, DHCP-style) plus a failure ledger
 * (`mapping_retry_at`, `mapping_failed_attempts`) — all integers already on
 * disk. §3.2.1 makes reboot detection a property of two numbers inside a
 * 12-byte datagram compared against two numbers on disk. Neither needs the
 * network to decide; the network is only needed to ACT on the decision.
 *
 * @package Phlix\Network
 * @since 1.8.0
 */
final class NatPmpMaintenance
{
    /**
     * Renewal is due: the caller should attempt one NAT-PMP mapping grant now.
     */
    public const ACTION_RENEW = 'renew';

    /**
     * No state file exists — port-forwarding was never configured. No-op.
     */
    public const ACTION_SKIP_NO_STATE = 'skip_no_state';

    /**
     * The persisted mapping is disabled (operator ran disable()). Never
     * resurrect a mapping the user deleted.
     */
    public const ACTION_SKIP_DISABLED = 'skip_disabled';

    /**
     * The active mapping was granted by UPnP-IGD or STUN, not NAT-PMP — the
     * §3.3 half-lifetime renewal law is NAT-PMP-only (IGD leases are permanent
     * router rules). No-op.
     */
    public const ACTION_SKIP_NOT_NATPMP = 'skip_not_natpmp';

    /**
     * Enabled NAT-PMP state carries no usable `mapping_renew_at` (hand-edited
     * or pre-§3.3 file). Renewing without a deadline would guess; the next
     * successful autoConfigure()/renewOnce() records one. No-op.
     */
    public const ACTION_SKIP_NO_DEADLINE = 'skip_no_deadline';

    /**
     * A previous renewal attempt failed and its backoff window has not yet
     * elapsed. Waiting here is the anti-hot-loop gate.
     */
    public const ACTION_WAIT_BACKOFF = 'wait_backoff';

    /**
     * Still inside the §3.3 halfway window. Nothing to do yet.
     */
    public const ACTION_WAIT_NOT_DUE = 'wait_not_due';

    /**
     * First retry delay after a failed renewal (seconds), doubling from here
     * up to {@see self::RETRY_MAX_SECONDS}.
     */
    public const RETRY_BASE_SECONDS = 60;

    /**
     * Ceiling for the renewal backoff (seconds) — 15 minutes. Bounded because
     * a permanently-gone gateway must not degrade into either a hot loop or a
     * de-facto-forever silence; the deadline itself keeps advancing nothing,
     * so every RETRY_MAX_SECONDS a single bounded exchange re-probes.
     */
    public const RETRY_MAX_SECONDS = 900;

    /**
     * Wire opcode of a §3.2.1 gratuitous address response: the §3.2 address
     * reply (opcode 0) with the response bit (0x80) set, sent unsolicited to
     * 224.0.0.1:5350 by the gateway on address change or boot.
     */
    public const ANNOUNCEMENT_OPCODE = 128;

    /**
     * §3.2 address-response layout: version(1) + opcode(1) + result(2) +
     * SSSoE(4) + IP(4) = 12 bytes, and the whole of an announcement.
     */
    private const ANNOUNCEMENT_LENGTH = 12;

    /**
     * Decide what the maintenance tick should do, given the persisted state.
     *
     * Pure: no clock read of its own — the caller passes `now` so the whole
     * state machine is testable at fixed points in time.
     *
     * Precedence is deliberate:
     *  1. existence (`skip_no_state`) — nothing was ever configured;
     *  2. operator intent (`skip_disabled`, `skip_not_natpmp`) — a deleted or
     *     non-NAT-PMP mapping is never touched again;
     *  3. failure backoff (`wait_backoff`) — beats the deadline so a gateway
     *     that is down is retried on the bounded cadence, not every tick;
     *  4. §3.3 deadline (`wait_not_due` / `renew`).
     *
     * @param array<string,mixed>|null $state Decoded config/port-forward.json.
     * @param int $now Unix epoch second.
     */
    public static function plan(?array $state, int $now): string
    {
        if ($state === null) {
            return self::ACTION_SKIP_NO_STATE;
        }

        if (($state['enabled'] ?? false) !== true) {
            return self::ACTION_SKIP_DISABLED;
        }

        if (($state['method'] ?? null) !== 'natpmp') {
            return self::ACTION_SKIP_NOT_NATPMP;
        }

        $retryAt = $state['mapping_retry_at'] ?? null;
        if (is_int($retryAt) && $now < $retryAt) {
            return self::ACTION_WAIT_BACKOFF;
        }

        $renewAt = $state['mapping_renew_at'] ?? null;
        if (!is_int($renewAt)) {
            return self::ACTION_SKIP_NO_DEADLINE;
        }

        if ($now < $renewAt) {
            return self::ACTION_WAIT_NOT_DUE;
        }

        return self::ACTION_RENEW;
    }

    /**
     * Parse a §3.2.1 gratuitous address-response datagram.
     *
     * Strict by design: this parses UNTRUSTED LAN network bytes at the
     * boundary and hands the worker only trusted, typed data (or null to
     * drop). Gate order mirrors the wire: length, version byte, announcement
     * opcode, result code — anything else (mapping replies, requests, trims)
     * is not an announcement and yields null.
     *
     * @return array{external_ip: string, seconds_since_epoch: int}|null
     *         Parsed announcement, or null when the datagram must be dropped.
     */
    public static function parseAnnouncement(string $datagram): ?array
    {
        if (strlen($datagram) < self::ANNOUNCEMENT_LENGTH) {
            return null;
        }

        /** @var array{version: int, opcode: int, result: int, sssoe: int}|false $head */
        $head = unpack('Cversion/Copcode/nresult/Nsssoe', substr($datagram, 0, 10));
        if ($head === false) {
            return null;
        }

        if ($head['version'] !== 0 || $head['opcode'] !== self::ANNOUNCEMENT_OPCODE) {
            return null;
        }

        // A non-zero result code in an address response means "no address
        // available" — §3.2.1's announcements carry 0; anything else is not
        // an address we may act on.
        if ($head['result'] !== 0) {
            return null;
        }

        /** @var array{ip: int}|false $ipField */
        $ipField = unpack('Nip', substr($datagram, 8, 4));
        if ($ipField === false) {
            return null;
        }

        $externalIp = long2ip($ipField['ip']);
        if ($externalIp === false) {
            return null;
        }

        return [
            'external_ip' => $externalIp,
            'seconds_since_epoch' => $head['sssoe'],
        ];
    }

    /**
     * Does an announcement contradict what we believe about the gateway?
     *
     * Two independent reboot/re-assignment signals, either sufficient:
     *
     *  - **IP mismatch** — the WAN address the gateway now advertises is not
     *    the one our mapping was granted under. Whatever port mapping existed
     *    at the old address is gone.
     *  - **SSSoE regression** — RFC 6886 "Seconds Since Start of Epoch" counts
     *    monotonically until the gateway restarts, then returns to ~0. A
     *    LOWER value than the last one we observed is, for all practical
     *    purposes, proof of a reboot even when the address came back the same
     *    (wrap is 2^32 seconds ≈ 136 years; not modeled).
     *
     * Null references (never observed yet) cannot contradict anything — the
     * first accepted announcement establishes the baseline instead.
     */
    public static function detectChange(
        ?string $cachedExternalIp,
        ?int $lastObservedSssoe,
        string $announcedIp,
        int $announcedSssoe
    ): bool {
        if ($cachedExternalIp !== null && $cachedExternalIp !== $announcedIp) {
            return true;
        }

        if ($lastObservedSssoe !== null && $announcedSssoe < $lastObservedSssoe) {
            return true;
        }

        return false;
    }

    /**
     * Backoff delay in seconds after the Nth consecutive renewal failure.
     *
     * Exponential from {@see self::RETRY_BASE_SECONDS}, capped at
     * {@see self::RETRY_MAX_SECONDS}. The clamp before the exponentiation is
     * what keeps a months-long outage from producing astronomically large
     * shift operands.
     */
    public static function nextRetryDelay(int $consecutiveFailures): int
    {
        if ($consecutiveFailures <= 1) {
            return self::RETRY_BASE_SECONDS;
        }

        $exponent = min($consecutiveFailures - 1, 31);
        $delay = self::RETRY_BASE_SECONDS * (2 ** $exponent);

        return (int) min($delay, self::RETRY_MAX_SECONDS);
    }
}
