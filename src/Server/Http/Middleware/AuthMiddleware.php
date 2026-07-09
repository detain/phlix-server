<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;

/**
 * Gates an HTTP route group behind "must be a signed-in user" — the
 * authenticated-but-not-necessarily-admin counterpart to {@see AdminMiddleware}.
 *
 * Both HTTP entry points populate {@see Request::$userId} from the Bearer token
 * (or the `phlix_session` cookie) BEFORE dispatch — see `public/index.php` and
 * {@see \Phlix\Server\Workerman\HttpHandler}. This middleware simply requires it
 * to be present, so media/library listing + search endpoints are no longer
 * world-readable (a private library must not be enumerable without logging in).
 *
 * Behaviour:
 *  - `$request->userId` empty/null → 401 Unauthorized JSON `{error, code:auth.required}`.
 *  - Otherwise → publishes the id into the coroutine-local {@see RequestContext}
 *    (mirroring {@see AdminMiddleware}) and returns `null` to continue routing.
 *
 * It is dependency-free (only reads the request), so callers can register it as
 * `new AuthMiddleware()` without DI wiring.
 *
 * Note on CSRF: For Bearer-token authenticated requests, no CSRF token is
 * required because browsers never auto-attach the Authorization header
 * cross-origin. However, for requests authenticated via the `phlix_session`
 * cookie, CSRF protection IS required — the browser auto-sends cookies on
 * cross-origin state-changing requests. Both entry points handle this via
 * {@see \Phlix\Server\Http\RequestAuthenticator::validateCsrfOrigin()}.
 *
 * @package Phlix\Server\Http\Middleware
 * @since 0.39.0
 */
final class AuthMiddleware
{
    /**
     * Run the middleware. Returning `null` continues routing; returning a
     * {@see Response} short-circuits the dispatch chain (per
     * {@see \Phlix\Server\Http\Router::runMiddleware()} semantics).
     *
     * @param Request $request Incoming request; {@see Request::$userId} is filled
     *                         by the bearer-token/cookie block in the entry point.
     */
    public function __invoke(Request $request): ?Response
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        RequestContext::setUserId($userId);

        return null;
    }
}
