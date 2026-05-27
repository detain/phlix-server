<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;

/**
 * Gates an HTTP route group behind the `users.is_admin` flag.
 *
 * The middleware is callable so it can be registered with the existing
 * {@see \Phlix\Server\Http\Router::group()} signature, which expects
 * `array<callable>` rather than a typed middleware interface.
 *
 * Behaviour:
 *  - `$request->userId === null` → 401 Unauthorized JSON `{error}`.
 *    This happens before the bearer-token validator has had a chance,
 *    or when the token is invalid / expired.
 *  - `$request->userId` set, but the user is not in `users` with
 *    `is_admin = 1` → 403 Forbidden JSON `{error}` and an
 *    `AuditLogger::logPermissionDenied()` entry so privilege
 *    escalation attempts are traceable.
 *  - Otherwise → returns `null`, signalling the {@see \Phlix\Server\Http\Router}
 *    to continue to the route handler.
 *
 * The "admin row" looked up here is attached to `$request->userId`
 * implicitly — controllers in `Phlix\Server\Http\Controllers\Admin`
 * can re-use `UserRepository::findAdminById()` if they need the row
 * itself.
 *
 * On success, the middleware also publishes the authenticated user-id
 * into the coroutine-local request context via
 * {@see RequestContext::setUserId()} so downstream services can read
 * it without re-receiving the {@see Request}. This is the canonical
 * coroutine-safe replacement for the static/global pattern under the
 * Workerman 5 + Swoole eventLoop runtime introduced in step 0.2; see
 * `phlix-docs/dev/coroutine-runtime.md` for the no-static-state rule.
 *
 * Note on CSRF: the routes this middleware protects are JSON APIs
 * authenticated via the Authorization Bearer header (JWT). Browsers do
 * not auto-attach Authorization headers across origins, so a CSRF
 * token is intentionally NOT required — see also
 * https://detain.github.io/phlix-docs/plugins/install-from-url
 *
 * @package Phlix\Server\Http\Middleware
 * @since   0.10.0 (Step A.5)
 */
final class AdminMiddleware
{
    /**
     * @param UserRepository $users  Repository used for the admin lookup.
     * @param AuditLogger    $audit  Security-event logger; receives every
     *                               403 the middleware emits.
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Run the middleware against a request. Returning `null` means
     * "continue routing"; returning a {@see Response} short-circuits
     * the dispatch chain (per
     * {@see \Phlix\Server\Http\Router::runMiddleware()} semantics).
     *
     * @param Request $request Incoming request. {@see Request::$userId}
     *                         is expected to be filled by the bearer-token
     *                         validation block in `public/index.php`.
     *
     * @return Response|null   401/403 to short-circuit; null to continue.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function __invoke(Request $request): ?Response
    {
        $status = $this->checkAccess($request);
        if ($status === null) {
            return null;
        }
        if ($status === 401) {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }
        return (new Response())->status(403)->json([
            'error' => 'Forbidden',
            'code'  => 'auth.not_admin',
        ]);
    }

    /**
     * Pure auth-decision helper that returns the would-be HTTP status
     * (401/403) when the request fails the admin gate, or `null` when
     * the request should be allowed through.
     *
     * Shared with the SSR page-route branch in `public/index.php` so
     * the role-check logic lives in exactly one place. Side effects
     * (audit logging for the 403 case) happen here too so the page
     * route automatically gets the same security telemetry as the JSON
     * API.
     *
     * @param Request $request Incoming request. {@see Request::$userId}
     *                         is expected to be populated.
     *
     * @return int|null `null` to allow, otherwise an HTTP status code
     *                   (401 or 403) the caller should map to its
     *                   response format.
     *
     * @since 0.10.1
     */
    public function checkAccess(Request $request): ?int
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return 401;
        }

        $admin = $this->users->findAdminById($userId);
        if ($admin === null) {
            $this->audit->logPermissionDenied($userId, 'admin', 'access');
            return 403;
        }

        // Publish the authenticated user-id into the coroutine-local
        // request context so downstream admin controllers / services can
        // read it without re-passing the Request object. This is the
        // canonical replacement for the static/global pattern that
        // resident-memory workers cannot use safely under coroutines.
        // See {@see RequestContext} for the wrapper rationale and
        // step 0.2b in `phlix-docs/dev/coroutine-runtime.md` for the
        // no-static-state rule.
        RequestContext::setUserId($userId);

        return null;
    }
}
