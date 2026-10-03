<?php

/**
 * Phlix media server: security response-header defaults (W4 / phase-5 exposure).
 *
 * NET-NEW config file (precedent: stats.php and dlna.php shipped the same way).
 * These are the CONFIG-DEFAULT layer for the two settings-schema keys
 * `security.hsts_max_age_seconds` and `security.frame_options` (phlix-shared
 * 5c4b59f): SettingsRepository resolves the dotted keys onto this tree, an
 * admin override in `server_settings` wins over it, and the values below are
 * what a fresh install — and any store outage — answers with. Both defaults
 * reproduce, byte-for-byte, the values SecurityHeaders hardcoded before the
 * keys existed; this file changes no behavior by itself, it makes the values
 * visible and PUT-able.
 *
 * The Content-Security-Policy deliberately has NO entry here. It is assembled
 * in code because parts of it are load-bearing for playback (media-src /
 * worker-src 'self' blob: for hls.js MSE + its transmux Web Worker;
 * style-src 'unsafe-inline' for the token-theme surface), and a free-form
 * schema string would let one PUT break streaming estate-wide. The
 * DO-NOT-EXPOSE decision and the force-inject law are documented at the
 * single source: SecurityHeaders::contentSecurityPolicy().
 *
 * @package Phlix\Config
 * @since 1.10.0
 */

return [
    // Strict-Transport-Security lifetime in seconds. 31536000 = one year,
    // the widely recommended pin. 0 is the standard "clear the pin" signal
    // (RFC 6797 de-registration), e.g. when a host permanently leaves HTTPS.
    // The `includeSubDomains` suffix is NOT configurable and always ships.
    // Bounds mirror the schema (0..31536000); SecurityHeadersPolicy clamps
    // defensively at read time.
    'hsts_max_age_seconds' => 31536000,

    // X-Frame-Options value: 'DENY' | 'SAMEORIGIN' | 'NONE'. SAMEORIGIN is
    // what the header has always sent. 'NONE' omits the LEGACY header only —
    // the CSP frame-ancestors 'self' directive stays emitted regardless and
    // is what modern browsers obey.
    'frame_options' => 'SAMEORIGIN',
];
