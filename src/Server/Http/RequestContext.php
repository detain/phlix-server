<?php

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
}
