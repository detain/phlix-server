<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Admin\SettingsRepository;

/**
 * Single enforcement point for the two configurable security headers (W4).
 *
 * ## Why this class exists
 *
 * Phase 5 hardened the response headers but pinned both values in code:
 * `SecurityHeaders` shipped `X-Frame-Options: SAMEORIGIN` as a literal and
 * HSTS `max-age=31536000` as a private constant. Operators deploying Phlix on
 * a short-lived test certificate, behind a frame-hosting portal, or mid
 * HTTPS-rollback had no lever short of a code change. This policy makes the
 * two values settings-backed — schema keys
 * `security.hsts_max_age_seconds` (integer 0..31536000) and
 * `security.frame_options` (enum DENY|SAMEORIGIN|NONE), phlix-shared 5c4b59f,
 * defaults declared in `config/security.php`.
 *
 * ## Read path
 *
 * Class (a) LIVE: `SecurityHeaders::decorate()` runs per request inside
 * `HttpHandler::__invoke()`. Both values resolve through
 * {@see SettingsRepository::getEffective()} — but ONE live DB point-SELECT
 * per key per second, not per response (playback-lag cure C3, INV-A P6):
 * an in-process memo mirrors the phlix-hub `MaintenanceGate` idiom
 * (`MEMO_TTL_SECONDS = 1`, strict-`<` expiry, injectable clock for tests).
 * An admin edit therefore applies to a response within one second —
 * per-decision-bounded staleness BY DESIGN, the same trade the de573d6e
 * resident-worker law contemplates: the bound is the promise, the store is
 * never skipped. The key is that the memo holds the RESOLVED (coerced)
 * value, so a memoised read and a cold read are byte-identical; null store,
 * unreadable store and out-of-band rows all resolve exactly as before.
 * `restart: false` stays the truth for both keys, one second at a time.
 *
 * ## What is DELIBERATELY not exposed
 *
 * The Content-Security-Policy stays assembled in code, single-sourced at
 * {@see SecurityHeaders::contentSecurityPolicy()}. Its `media-src`/`worker-src
 * 'self' blob:` directives are load-bearing for hls.js MSE playback and its
 * transmux Web Worker, and `style-src 'unsafe-inline'` is load-bearing for the
 * token-theme surface (`el.style.setProperty()`); one free-form schema string
 * would let a single admin PUT break streaming on every client at once, with
 * an error class (blocked blob URLs) that no settings validation can catch.
 * That is the DO-NOT-EXPOSE law: exposure requires a STRUCTURED key per
 * load-bearing directive (like frame_options here is for XFO), never a raw
 * header text box. `frame_options: NONE` likewise relaxes only the legacy
 * header — the always-emitted CSP `frame-ancestors 'self'` keeps modern
 * browsers framed-out regardless, so an operator must never read NONE as
 * "framing allowed everywhere".
 *
 * ## Safe degradation
 *
 * Null store, unreadable store, out-of-band value: every failure returns the
 * shipped constant (the byte-identical pre-key value). A settings outage must
 * never WEAKEN a security header — the shipped values are the floor; the keys
 * exist to let operators go stricter (DENY) or make scoped, informed
 * relaxations, not to let an outage silently downgrade anyone.
 *
 * @package Phlix\Server\Http\Middleware
 * @since 1.10.0
 */
final class SecurityHeadersPolicy
{
    /**
     * The dotted settings key backing {@see self::hstsMaxAgeSeconds()}.
     */
    public const SETTING_KEY_HSTS_MAX_AGE = 'security.hsts_max_age_seconds';

    /**
     * The dotted settings key backing {@see self::frameOptions()}.
     */
    public const SETTING_KEY_FRAME_OPTIONS = 'security.frame_options';

    /**
     * One-year HSTS pin — the value `SecurityHeaders` hardcoded before this
     * key existed; the shipped default reproduces it exactly.
     */
    public const DEFAULT_HSTS_MAX_AGE = 31536000;

    /**
     * Same-origin framing — the value `SecurityHeaders` hardcoded before this
     * key existed; the shipped default reproduces it exactly.
     */
    public const DEFAULT_FRAME_OPTIONS = 'SAMEORIGIN';

    /**
     * Sentinel member of {@see self::FRAME_OPTIONS} meaning "omit the legacy
     * X-Frame-Options header entirely" — not an XFO wire value.
     */
    public const NONE = 'NONE';

    /**
     * Closed set of admissible X-Frame-Options emissions. 'NONE' is not an
     * XFO value (the header has no NONE token) — it is this project's
     * sentinel for "omit the legacy header entirely".
     */
    public const FRAME_OPTIONS = ['DENY', 'SAMEORIGIN', 'NONE'];

    /**
     * Memo TTL in seconds for the live settings read — the phlix-hub
     * MaintenanceGate bound. Law-pinned by
     * SecurityHeadersPolicyTest::test_the_memo_bound_is_the_one_second_constant;
     * rotating it is a deliberate act through that pin.
     */
    private const MEMO_TTL_SECONDS = 1;

    /** @var array{value:int, expiresAt:int}|null HSTS resolved-value memo. */
    private ?array $hstsMemo = null;

    /** @var array{value:string, expiresAt:int}|null XFO resolved-value memo. */
    private ?array $frameMemo = null;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to the shipped constants.
     *
     *        NOTE for DI: PHP-DI SKIPS optional constructor parameters during
     *        autowiring, so any binding that needs the settings honoured must
     *        name this parameter explicitly (see CoreServicesProvider).
     * @param callable():int|null     $clock    Injectable second clock for the
     *        one-second memo (tests pin it; production uses time()). NULL is
     *        the production shape — also skipped by PHP-DI autowiring.
     */
    public function __construct(
        private readonly ?SettingsRepository $settings = null,
        private readonly mixed $clock = null,
    ) {
    }

    /**
     * The max-age (seconds) to emit in Strict-Transport-Security.
     *
     * Clamped to the schema bounds 0..31536000 at read time, so a row written
     * by direct SQL or a restored backup can never make the server advertise
     * an out-of-policy pin. 0 is honoured (clear-the-pin signal).
     */
    public function hstsMaxAgeSeconds(): int
    {
        $now = $this->now();
        if ($this->hstsMemo !== null && $now < $this->hstsMemo['expiresAt']) {
            return $this->hstsMemo['value'];
        }

        $value = $this->readHstsMaxAge();
        /** @var array{value:int, expiresAt:int} $memo */
        $memo = ['value' => $value, 'expiresAt' => $now + self::MEMO_TTL_SECONDS];
        $this->hstsMemo = $memo;

        return $value;
    }

    /**
     * The X-Frame-Options verdict for this response.
     *
     * @return string One of {@see self::FRAME_OPTIONS}; anything unrecognised
     *         yields the shipped default, never an arbitrary string (this
     *         method's return value goes into a header verbatim — parse at the
     *         boundary, emit a member of the closed set).
     */
    public function frameOptions(): string
    {
        $now = $this->now();
        if ($this->frameMemo !== null && $now < $this->frameMemo['expiresAt']) {
            return $this->frameMemo['value'];
        }

        $value = $this->readFrameOptions();
        /** @var array{value:string, expiresAt:int} $memo */
        $memo = ['value' => $value, 'expiresAt' => $now + self::MEMO_TTL_SECONDS];
        $this->frameMemo = $memo;

        return $value;
    }

    /**
     * Live store read for the HSTS max-age — memoized by the ONLY caller
     * {@see self::hstsMaxAgeSeconds()}. Failure shape untouched by the memo:
     * null store / thrown read / garbage row all resolve to the shipped
     * constant BEFORE the value is stored, so a poisoned one-second cache is
     * structurally impossible.
     */
    private function readHstsMaxAge(): int
    {
        if ($this->settings === null) {
            return self::DEFAULT_HSTS_MAX_AGE;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY_HSTS_MAX_AGE);
        } catch (\Throwable) {
            return self::DEFAULT_HSTS_MAX_AGE;
        }

        return self::coerceMaxAge($configured);
    }

    /**
     * Live store read for the XFO verdict — same no-poison law as
     * {@see self::readHstsMaxAge()}.
     */
    private function readFrameOptions(): string
    {
        if ($this->settings === null) {
            return self::DEFAULT_FRAME_OPTIONS;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::SETTING_KEY_FRAME_OPTIONS);
        } catch (\Throwable) {
            return self::DEFAULT_FRAME_OPTIONS;
        }

        if (is_string($configured)) {
            $candidate = strtoupper(trim($configured));
            if (in_array($candidate, self::FRAME_OPTIONS, true)) {
                return $candidate;
            }
        }

        return self::DEFAULT_FRAME_OPTIONS;
    }

    /**
     * Current second per the injected clock (production: time()).
     */
    private function now(): int
    {
        if (is_callable($this->clock)) {
            /** @var int $tick */
            $tick = ($this->clock)();

            return $tick;
        }

        return time();
    }

    /**
     * Interpret a persisted max-age as a bounded integer.
     *
     * bool/null/garbage fall back to the shipped default; numeric spellings
     * are truncated toward zero and clamped into 0..31536000. A negative row
     * becomes 0 (the RFC's clear signal), not the default — an explicit "0 or
     * below" from an operator reads as intent to clear the pin.
     */
    private static function coerceMaxAge(mixed $configured): int
    {
        if (is_bool($configured) || $configured === null) {
            return self::DEFAULT_HSTS_MAX_AGE;
        }

        if (is_int($configured)) {
            return max(0, min(self::DEFAULT_HSTS_MAX_AGE, $configured));
        }

        if (is_float($configured)) {
            if (!is_finite($configured)) {
                return self::DEFAULT_HSTS_MAX_AGE;
            }

            return self::coerceMaxAge((int) $configured);
        }

        if (is_string($configured) && is_numeric(trim($configured))) {
            return self::coerceMaxAge((int) trim($configured));
        }

        return self::DEFAULT_HSTS_MAX_AGE;
    }
}
