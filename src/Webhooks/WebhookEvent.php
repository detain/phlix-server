<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

use DateTimeImmutable;

/**
 * An immutable webhook event plus its delivery signing helpers.
 *
 * ## Signature wire format (dual-scheme)
 *
 * Every delivery carries TWO signature headers over the exact raw
 * request-body bytes (the JSON encoding of {@see self::toArray()},
 * produced by {@see self::serializedPayload()} so body and signature
 * provably cover the same bytes):
 *
 *  - `X-Phlix-Signature: sha256=<hex>` — LEGACY. The HMAC covers the
 *    payload ONLY. Retained verbatim so already-deployed receivers keep
 *    validating without change, but it is replayable forever: a
 *    captured delivery carries no notion of when it was signed.
 *
 *  - `X-Phlix-Signature-V2: t=<unix>,v1=<hex>` — Stripe-style. `v1` is
 *    the HMAC-SHA256 over the string `"<t>.<payload>"`, binding the
 *    signature to the delivery timestamp. Receivers MUST additionally
 *    enforce timestamp freshness (a tolerance window) or the timestamp
 *    buys nothing — {@see self::verify()} implements exactly that
 *    check and is the reference for receiver implementations.
 *
 * New receivers should verify the V2 header; the legacy header exists
 * only as a compatibility hatch and should be treated as deprecated.
 * Retry re-dispatches sign with a FRESH timestamp (the dispatcher
 * stamps `time()` per attempt), so a replayed capture carries a stale
 * `t=` and fails the tolerance window.
 */
class WebhookEvent
{
    /**
     * Legacy header: `sha256=<hmac over the raw body>`, no timestamp.
     */
    public const SIGNATURE_HEADER = 'X-Phlix-Signature';

    /**
     * Timestamped header: `t=<unix-seconds>,v1=<hmac over "t.body">`.
     */
    public const TIMESTAMPED_SIGNATURE_HEADER = 'X-Phlix-Signature-V2';

    /**
     * Default receiver-side freshness window, in seconds. A V2 header
     * whose `t=` is further than this from the verifier's now (in the
     * past OR the future) is rejected.
     */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * The canonical delivery bytes: every signature in this class is
     * computed over exactly this string, and the dispatcher sends
     * exactly this string as the raw HTTP body, so "what was signed"
     * and "what was sent" are the same value by construction.
     */
    public function serializedPayload(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * LEGACY signature header value: `sha256=<hex>` over the payload
     * only. Kept byte-identical for already-deployed receivers.
     */
    public function getSignature(string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $this->serializedPayload(), $secret);
    }

    /**
     * Timestamped (V2) signature header value: `t=<unix>,v1=<hex>`
     * where `v1` is the HMAC-SHA256 over `"<t>.<payload>"` — the
     * Stripe-style construction that makes a captured delivery expire.
     */
    public function getTimestampedSignature(string $secret, int $timestamp): string
    {
        $v1 = hash_hmac('sha256', $timestamp . '.' . $this->serializedPayload(), $secret);
        return sprintf('t=%d,v1=%s', $timestamp, $v1);
    }

    /**
     * Reference receiver-side verification against the raw body bytes.
     *
     * Accepts when EITHER:
     *  - the V2 header parses into a numeric `t` within
     *    ±$toleranceSeconds of $now AND at least one `v1` candidate
     *    matches the recomputed HMAC over `"<t>.<body>"` in constant
     *    time; OR
     *  - no V2 header was supplied and the legacy header matches
     *    `"sha256=" . hmac(body)` in constant time (compatibility
     *    path — receivers that must kill replay entirely should pass
     *    `null` for the legacy header).
     *
     * @param string      $secret           Shared webhook secret.
     * @param string      $body             Exact raw request-body bytes.
     * @param string|null $timestampedHeader Value of {@see self::TIMESTAMPED_SIGNATURE_HEADER}, if present.
     * @param string|null $legacyHeader      Value of {@see self::SIGNATURE_HEADER}, if present.
     * @param int         $toleranceSeconds Max |now - t| accepted for the V2 header.
     * @param int|null    $now              Verification clock (injectable for tests).
     */
    public static function verify(
        string $secret,
        string $body,
        ?string $timestampedHeader,
        ?string $legacyHeader = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): bool {
        if ($timestampedHeader !== null && $timestampedHeader !== '') {
            return self::verifyTimestamped($secret, $body, $timestampedHeader, $toleranceSeconds, $now);
        }

        if ($legacyHeader !== null && $legacyHeader !== '') {
            return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $legacyHeader);
        }

        return false;
    }

    /**
     * Parse + verify a `t=<unix>[,v1=<hex>]+` header value.
     */
    private static function verifyTimestamped(
        string $secret,
        string $body,
        string $header,
        int $toleranceSeconds,
        ?int $now,
    ): bool {
        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $raw = substr($part, 2);
                if (preg_match('/^\d+$/', $raw) !== 1) {
                    return false; // malformed timestamp — fail closed
                }
                $timestamp = (int) $raw;
            } elseif (str_starts_with($part, 'v1=')) {
                $candidates[] = substr($part, 3);
            }
        }

        if ($timestamp === null || $candidates === []) {
            return false;
        }

        $now = $now ?? time();
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false; // stale (or clock-skewed future) capture — replay window closed
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
