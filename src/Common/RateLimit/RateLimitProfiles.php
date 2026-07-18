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

    /** Container id for the public JWKS-endpoint limiter (120 / 60s; in-memory). */
    public const string JWKS = 'rate_limiter.jwks';

    /** Container id for the :8097 WS-connect limiter (30 / 60s; in-memory, count=1). */
    public const string WS_CONNECT = 'rate_limiter.ws_connect';

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
            self::JWKS            => ['key' => 'jwks',            'max' => 120, 'window' => 60],
            self::WS_CONNECT      => ['key' => 'ws_connect',      'max' => 30,  'window' => 60],
        ];
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
