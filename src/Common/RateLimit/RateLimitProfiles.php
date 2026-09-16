<?php

/**
 * Phlix media server component: RateLimit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\RateLimit;

/**
 * Type-safe catalogue of the server's per-surface rate-limiter container ids,
 * their `config/server.php` override keys, and their default thresholds
 * (SV-4.15 sub-step d).
 *
 * Each previously-UNLIMITED auth surface gets its OWN limiter instance
 * registered under the matching `rate_limiter.<surface>` container id (see
 * {@see \Phlix\Common\Container\Providers\AuthServicesProvider}) with its own
 * `{max, window}` sourced from `config/server.php`'s `rate_limit` section — a
 * single shared budget across unrelated surfaces would be wrong. {@see defaults()}
 * maps each container id to the `rate_limit.<key>` override key plus the default
 * `{max, window}` applied when that key (or one of its members) is absent.
 *
 * `login` is DELIBERATELY absent from this catalogue: it keeps using the
 * existing IP-keyed {@see \Phlix\Auth\DbLoginRateLimitStore} (migration 074),
 * which is already DB-backed/shared-across-workers and wired straight into
 * {@see \Phlix\Auth\AuthManager}. This framework is purely ADDITIVE — it must
 * not touch the login path.
 *
 * ## Backend per surface
 *
 * Which backend a surface needs is captured by {@see dbBacked()} /
 * {@see isDbBacked()} so the DI provider can decide without hard-coding the
 * list:
 *
     * - `register`, `refresh`, `webauthn_start`, `webauthn_finish` are
     *   brute-force / credential-enumeration surfaces that need TRUE-global
     *   enforcement across ALL of the server's HTTP workers, so they resolve to the
     *   shared, DB-backed {@see DbRateLimiter} (migration 085). A worker-local
     *   in-memory limiter would count independently per worker and hand out roughly
     *   `max × workers` (~14×) budget — a genuine weakening on exactly the surfaces
     *   that matter most.
     * - S518's four (`quick_connect_initiate` / `_status` / `_approve` and
     *   `telemetry_heartbeat`) join the DB-backed set for the same reason in a
     *   sharper shape: a quick-connect flow is TWO devices hitting whichever
     *   worker the kernel hands them, so any leg's budget MUST be global or the
     *   pairing surface is silently `max × workers` wide by construction.
 * - `jwks` and `ws_connect` stay on the worker-local in-memory
 *   {@see RateLimiter}: `jwks` is a public, low-value, cache-frontable DoS
 *   surface where a soft per-worker budget is acceptable, and `ws_connect`
 *   protects the `:8097` WebSocket worker which runs `count=1`, so per-worker
 *   IS global there.
 *
 * @package Phlix\Common\RateLimit
 */
final class RateLimitProfiles
{
    /** Container id for the account-registration limiter (5 / 600s; DB-backed). */
    public const string REGISTER = 'rate_limiter.register';

    /** Container id for the token-refresh limiter (30 / 60s; DB-backed). */
    public const string REFRESH = 'rate_limiter.refresh';

    /** Container id for the WebAuthn start-ceremony limiter (10 / 60s; DB-backed). */
    public const string WEBAUTHN_START = 'rate_limiter.webauthn_start';

    /** Container id for the WebAuthn finish-ceremony limiter (10 / 60s; DB-backed). */
    public const string WEBAUTHN_FINISH = 'rate_limiter.webauthn_finish';

    /** Container id for the profile-PIN verification limiter (5 / 300s; DB-backed). */
    public const string PIN_VERIFY = 'rate_limiter.pin_verify';

    /** Container id for the public JWKS-endpoint limiter (120 / 60s; in-memory). */
    public const string JWKS = 'rate_limiter.jwks';

    /** Container id for the :8097 WS-connect limiter (30 / 60s; in-memory, count=1). */
    public const string WS_CONNECT = 'rate_limiter.ws_connect';

    /**
     * Container id for the quick-connect INITIATE limiter (10 / 3600s per IP;
     * DB-backed). One pairing window per 6 minutes per client caps abandoned-
     * pairing churn without touching a household that reboots several devices.
     */
    public const string QUICK_CONNECT_INITIATE = 'rate_limiter.quick_connect_initiate';

    /**
     * Container id for the quick-connect STATUS-POLL limiter (120 / 60s per IP;
     * DB-backed). A TV polls every ~1–2s for up to 10 minutes; 2/s sustained is
     * one honest TV plus margin, and the token redemption shares this bucket
     * (poll-then-redeem is one surface — see QuickConnectController).
     */
    public const string QUICK_CONNECT_STATUS = 'rate_limiter.quick_connect_status';

    /**
     * Container id for the quick-connect APPROVE limiter (30 / 3600s per USER;
     * DB-backed). The pairing secret is 256-bit, so this is an abuse budget,
     * not the entropy defence: it stops a signed-in account (or a stolen
     * session) fishing the 20^6 code space for someone else's pairing.
     */
    public const string QUICK_CONNECT_APPROVE = 'rate_limiter.quick_connect_approve';

    /**
     * Container id for the CLIENT TELEMETRY heartbeat limiter (20 / 3600s per
     * IP; DB-backed). The honest client ticks hourly; 20/h is a whole LAN of
     * devices plus retries, while capping a scripted flood at a fixed cost.
     */
    public const string TELEMETRY_HEARTBEAT = 'rate_limiter.telemetry_heartbeat';

    /**
     * Map of `container id => {config key, default max, default window}`.
     *
     * `key` is the sub-key under `config/server.php`'s `rate_limit` section
     * (also the middle of the `RATE_LIMIT_<KEY>_MAX` / `RATE_LIMIT_<KEY>_WINDOW`
     * env overrides); `max`/`window` are the defaults applied when that key (or
     * its `max`/`window`) is absent.
     *
     * @return array<string, array{key: string, max: int, window: int}>
     */
    public static function defaults(): array
    {
        return [
            self::REGISTER        => ['key' => 'register',        'max' => 5,   'window' => 600],
            self::REFRESH         => ['key' => 'refresh',         'max' => 30,  'window' => 60],
            self::WEBAUTHN_START  => ['key' => 'webauthn_start',  'max' => 10,  'window' => 60],
            self::WEBAUTHN_FINISH => ['key' => 'webauthn_finish', 'max' => 10,  'window' => 60],
            // S81: a self-service verify endpoint over verifyPin() is a PIN
            // oracle with unlimited attempts unless throttled (the S81 blocker
            // record: "no rate limiter anywhere near it"). 4-6 digit PINs are a
            // small keyspace, so the budget is deliberately tight and shared
            // DB-backed across ALL HTTP workers — a worker-local limiter would
            // hand out max × workers attempts (~14×).
            self::PIN_VERIFY      => ['key' => 'pin_verify',      'max' => 5,   'window' => 300],
            self::JWKS            => ['key' => 'jwks',            'max' => 120, 'window' => 60],
            self::WS_CONNECT      => ['key' => 'ws_connect',      'max' => 30,  'window' => 60],
            // S518 (AD-25/AD-27): the quick-connect pairing surface and the
            // client telemetry tick. All four are DB-backed: the pairing flow
            // spans TWO devices and any worker could legally hold any leg
            // (initiate on one, approve on another, poll on a third), so a
            // worker-local budget would multiply by the ~14 resident workers
            // exactly where brute-force matters — the register/webauthn rationale.
            self::QUICK_CONNECT_INITIATE => ['key' => 'quick_connect_initiate', 'max' => 10,  'window' => 3600],
            self::QUICK_CONNECT_STATUS   => ['key' => 'quick_connect_status',   'max' => 120, 'window' => 60],
            self::QUICK_CONNECT_APPROVE  => ['key' => 'quick_connect_approve',  'max' => 30,  'window' => 3600],
            self::TELEMETRY_HEARTBEAT    => ['key' => 'telemetry_heartbeat',    'max' => 20,  'window' => 3600],
        ];
    }

    /**
     * Hard floor for a surface's `max`.
     *
     * **This is a lock-out fail-safe, not a sanity check.** A configured `max`
     * of 0 would reject every request to the surface: `refresh` at 0 signs out
     * every client on the server the moment their access token expires, and
     * `register` at 0 silently blocks all sign-ups. A settings field must not
     * be able to do that, so a configured 0 or negative is raised to 1.
     */
    public const MIN_MAX = 1;

    /**
     * Ceiling for a surface's `max`. Bounds what a mistyped value commits the
     * server to; an operator wanting an effectively unlimited surface can set
     * this ceiling and get it.
     */
    public const MAX_MAX = 100000;

    /**
     * Hard floor for a surface's `window`, in seconds. A 0-second window makes
     * the budget meaningless (every request lands in a fresh bucket), which
     * would silently disable the limiter while the UI showed a limit.
     */
    public const MIN_WINDOW = 1;

    /**
     * Ceiling for a surface's `window`, in seconds — 24 hours. Longer windows
     * hold bucket rows for the whole period, and a day is already far beyond
     * any credible brute-force accounting period.
     */
    public const MAX_WINDOW = 86400;

    /**
     * Clamp a configured `max` into {@see self::MIN_MAX}..{@see self::MAX_MAX}.
     *
     * @since 1.3.0
     */
    public static function clampMax(int $max): int
    {
        return max(self::MIN_MAX, min(self::MAX_MAX, $max));
    }

    /**
     * Clamp a configured `window` into
     * {@see self::MIN_WINDOW}..{@see self::MAX_WINDOW}.
     *
     * @since 1.3.0
     */
    public static function clampWindow(int $window): int
    {
        return max(self::MIN_WINDOW, min(self::MAX_WINDOW, $window));
    }

    /**
     * The subset of profile ids that MUST resolve to the shared, DB-backed
     * {@see DbRateLimiter} for true cross-worker enforcement (the brute-force /
     * credential-enumeration surfaces). Every other id in {@see defaults()}
     * uses the worker-local in-memory {@see RateLimiter}.
     *
     * @return list<string>
     */
    public static function dbBacked(): array
    {
        return [
            self::REGISTER,
            self::REFRESH,
            self::WEBAUTHN_START,
            self::WEBAUTHN_FINISH,
            self::PIN_VERIFY,
            // S518: pairing legs land on arbitrary workers (two devices, one
            // flow) and telemetry is a public flood surface — see defaults().
            self::QUICK_CONNECT_INITIATE,
            self::QUICK_CONNECT_STATUS,
            self::QUICK_CONNECT_APPROVE,
            self::TELEMETRY_HEARTBEAT,
        ];
    }

    /**
     * Whether `$id` must use the shared DB-backed limiter (true) or the
     * worker-local in-memory one (false). Ids not in {@see defaults()} are
     * treated as in-memory (false).
     */
    public static function isDbBacked(string $id): bool
    {
        return in_array($id, self::dbBacked(), true);
    }
}
