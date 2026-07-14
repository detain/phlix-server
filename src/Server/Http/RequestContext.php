<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http;

use support\Context;

/**
 * Thin, typed wrapper around {@see \support\Context} for per-request data.
 *
 * Workerman 5 + Webman 2.2 run HTTP handlers inside coroutines once the
 * Swoole eventLoop driver is enabled (see `start.php` lines ~48-58 and
 * `public/index.php` lines ~22-28, both wired in step 0.2a). Inside a
 * coroutine, ANY use of `static` / `global` / `$GLOBALS` to hold
 * request-scoped data is a correctness bug: the next request handled by
 * the same worker will see (or trample) the previous request's value.
 *
 * `support\Context` (which proxies to {@see \Workerman\Coroutine\Context})
 * is the supported per-request store. Behind the scenes it picks a driver
 * based on the active eventLoop (`Swoole`, `Swow`, or `Fiber` fallback)
 * and isolates values per coroutine — analogous to AsyncLocalStorage in
 * Node.js or `ContextVar` in Python.
 *
 * This class exists so the rest of the codebase has one canonical place
 * to read/write the per-request user-id rather than:
 *
 *   - storing it on a `private static` (broken under coroutines), or
 *   - reaching into `$GLOBALS` (broken under coroutines), or
 *   - sprinkling raw `\support\Context::get('phlix.userId')` strings
 *     across controllers (typo-prone and untyped).
 *
 * The audit run during step 0.2b (see `/tmp/0.2-server-static-audit.txt`)
 * found zero offenders in `src/Server/`, so this wrapper is the canonical
 * "use me when you need to share request-scoped data" entry point that
 * future code (e.g. the admin SPA's audit-logging middleware in step
 * 0.4+) can adopt. It's exercised today by {@see AdminMiddleware}, which
 * publishes the authenticated user-id into the context on the way through
 * so downstream services can read it without re-passing the `Request`.
 *
 * @package Phlix\Server\Http
 * @since   0.10.x (Step 0.2b)
 *
 * @see https://www.workerman.net/doc/webman/components/context.html
 * @see \Workerman\Coroutine\Context
 */
final class RequestContext
{
    /**
     * Namespaced context key for the authenticated user-id of the
     * current request. Namespaced (`phlix.*`) to avoid collisions with
     * webman's own keys (`context.onDestroy`, etc.).
     *
     * @var string
     */
    public const KEY_USER_ID = 'phlix.userId';

    /**
     * Namespaced context key for the active profile-id of the
     * current request.
     *
     * @var string
     */
    public const KEY_PROFILE_ID = 'phlix.profileId';

    /**
     * Namespaced context key for the cancel group of the current request —
     * either a relay channel id OR a per-request direct-connection cancel id.
     *
     * This one key backs BOTH transport paths because they are mutually
     * exclusive by construction (each request-servicing coroutine is either a
     * relayed hub request OR a direct-LAN request, never both, and they run in
     * separate worker processes with separate per-worker registries):
     *
     *  - RELAY: when a request is serviced on behalf of a relayed hub connection
     *    ({@see \Phlix\Hub\RelayConsumer}), this carries the hub-allocated
     *    channel/request id, so an HTTP_CANCEL frame (SV-4.2 / X1) can find and
     *    kill the encode by channel id via {@see setRelayCancelGroup()}.
     *  - DIRECT-LAN: when a request hits the server's HTTP worker directly
     *    ({@see \Phlix\Server\Workerman\HttpHandler}), this carries a per-request
     *    direct-connection cancel id (SV-4.2-disconnect), so the connection's
     *    onClose can kill the encode when the socket FINs/RSTs mid-encode via
     *    {@see setCancelGroup()}.
     *
     * Either way, any on-demand ffmpeg segment encode launched during the
     * dispatch is registered under this id in the
     * {@see \Phlix\Media\Transcoding\SegmentProcessRegistry} — even though the
     * encode itself is tracked by its segment path — so a group kill finds it.
     * {@see \Phlix\Media\Transcoding\TranscodeManager} reads this via
     * {@see getRelayCancelGroup()} for both transports with no per-transport
     * branching.
     *
     * Unset (null) for a request that launches no cancellable encode.
     *
     * @var string
     */
    public const KEY_RELAY_CANCEL_GROUP = 'phlix.relayCancelGroup';

    /**
     * Static-only helper — instantiation is intentionally forbidden.
     */
    private function __construct()
    {
    }

    /**
     * Store the authenticated user-id of the current request.
     *
     * Pass `null` to clear the value (rare — the eventLoop usually
     * destroys the context when the coroutine exits; callers reset
     * explicitly in tests or in long-running background jobs that need
     * to "log out" mid-coroutine).
     *
     * @param string|null $userId Authenticated user-id, or `null` to clear.
     *
     * @return void
     *
     * @since 0.10.x (Step 0.2b)
     */
    public static function setUserId(?string $userId): void
    {
        Context::set(self::KEY_USER_ID, $userId);
    }

    /**
     * Read the authenticated user-id of the current request.
     *
     * Returns `null` when no user-id was published into the context
     * (anonymous request, or middleware not yet run).
     *
     * @return string|null Authenticated user-id, or `null` if unset.
     *
     * @since 0.10.x (Step 0.2b)
     */
    public static function getUserId(): ?string
    {
        $value = Context::get(self::KEY_USER_ID);
        return is_string($value) ? $value : null;
    }

    /**
     * Returns true if a user-id has been published into the current
     * coroutine's context. Does NOT return true for `null` values
     * stored explicitly via {@see setUserId()}.
     *
     * @return bool
     *
     * @since 0.10.x (Step 0.2b)
     */
    public static function hasUserId(): bool
    {
        $value = Context::get(self::KEY_USER_ID);
        return is_string($value) && $value !== '';
    }

    /**
     * Drop the user-id from the current coroutine's context. Equivalent
     * to `setUserId(null)`, expressed positively for call sites that
     * want to assert "clear request state."
     *
     * Useful in long-running background coroutines that handle several
     * "logical" requests in sequence, and in test fixtures that need to
     * reset shared state between assertions.
     *
     * @return void
     *
     * @since 0.10.x (Step 0.2b)
     */
    public static function clearUserId(): void
    {
        Context::set(self::KEY_USER_ID, null);
    }

    /**
     * Store the active profile-id of the current request.
     *
     * @param string|null $profileId Active profile-id (UUID), or null to clear.
     *
     * @return void
     */
    public static function setProfileId(?string $profileId): void
    {
        Context::set(self::KEY_PROFILE_ID, $profileId);
    }

    /**
     * Read the active profile-id of the current request.
     *
     * @return string|null Active profile-id (UUID), or null if unset.
     */
    public static function getProfileId(): ?string
    {
        $value = Context::get(self::KEY_PROFILE_ID);
        return is_string($value) ? $value : null;
    }

    /**
     * Returns true if a profile-id has been published into the current
     * coroutine's context.
     *
     * @return bool
     */
    public static function hasProfileId(): bool
    {
        $value = Context::get(self::KEY_PROFILE_ID);
        return is_int($value);
    }

    /**
     * Drop the profile-id from the current coroutine's context.
     *
     * @return void
     */
    public static function clearProfileId(): void
    {
        Context::set(self::KEY_PROFILE_ID, null);
    }

    /**
     * Store the relay cancel group (hub-allocated channel/request id) of the
     * current request. Set by {@see \Phlix\Hub\RelayConsumer} while dispatching
     * a relayed request so an on-demand segment encode launched during the
     * dispatch can be tracked under this id for HTTP_CANCEL-driven kill
     * (SV-4.2 / X1).
     *
     * The direct-LAN transport publishes into the SAME key via the transport-
     * neutral alias {@see setCancelGroup()} — see {@see KEY_RELAY_CANCEL_GROUP}
     * for why one key safely serves both paths.
     *
     * @param string|null $group Relay channel/request id, or null to clear.
     *
     * @return void
     */
    public static function setRelayCancelGroup(?string $group): void
    {
        Context::set(self::KEY_RELAY_CANCEL_GROUP, $group);
    }

    /**
     * Read the cancel group of the current request (relay channel id OR direct-
     * connection cancel id), or null when the request launched no cancellable
     * encode. Read by {@see \Phlix\Media\Transcoding\TranscodeManager} for both
     * transports; the transport-neutral name is {@see getCancelGroup()}.
     *
     * @return string|null Cancel group id, or null if unset/empty.
     */
    public static function getRelayCancelGroup(): ?string
    {
        $value = Context::get(self::KEY_RELAY_CANCEL_GROUP);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Drop the relay cancel group from the current coroutine's context.
     *
     * @return void
     */
    public static function clearRelayCancelGroup(): void
    {
        Context::set(self::KEY_RELAY_CANCEL_GROUP, null);
    }

    /**
     * Transport-neutral alias of {@see setRelayCancelGroup()} — publishes the
     * current request's cancel group (a per-request direct-connection cancel id)
     * into the shared {@see KEY_RELAY_CANCEL_GROUP} key. Used by the DIRECT-LAN
     * path ({@see \Phlix\Server\Workerman\HttpHandler}) so its call sites read
     * naturally ("cancel group", not "relay cancel group") while still landing
     * in the one key {@see \Phlix\Media\Transcoding\TranscodeManager} reads. The
     * two transports never share a coroutine, so there is no collision.
     *
     * @param string|null $group Direct-connection cancel id, or null to clear.
     *
     * @return void
     */
    public static function setCancelGroup(?string $group): void
    {
        self::setRelayCancelGroup($group);
    }

    /**
     * Transport-neutral alias of {@see getRelayCancelGroup()}.
     *
     * @return string|null Cancel group id, or null if unset/empty.
     */
    public static function getCancelGroup(): ?string
    {
        return self::getRelayCancelGroup();
    }

    /**
     * Transport-neutral alias of {@see clearRelayCancelGroup()}.
     *
     * @return void
     */
    public static function clearCancelGroup(): void
    {
        self::clearRelayCancelGroup();
    }
}
