<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Middleware;

use Phlex\Auth\UserRepository;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * Gates an HTTP route group behind the `users.is_admin` flag.
 *
 * The middleware is callable so it can be registered with the existing
 * {@see \Phlex\Server\Http\Router::group()} signature, which expects
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
 *  - Otherwise → returns `null`, signalling the {@see \Phlex\Server\Http\Router}
 *    to continue to the route handler.
 *
 * The "admin row" looked up here is attached to `$request->userId`
 * implicitly — controllers in `Phlex\Server\Http\Controllers\Admin`
 * can re-use `UserRepository::findAdminById()` if they need the row
 * itself.
 *
 * Note on CSRF: the routes this middleware protects are JSON APIs
 * authenticated via the Authorization Bearer header (JWT). Browsers do
 * not auto-attach Authorization headers across origins, so a CSRF
 * token is intentionally NOT required — see also
 * `docs/plugins/install-from-url.md`.
 *
 * @package Phlex\Server\Http\Middleware
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
     * {@see \Phlex\Server\Http\Router::runMiddleware()} semantics).
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
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        $admin = $this->users->findAdminById($userId);
        if ($admin === null) {
            $this->audit->logPermissionDenied($userId, 'admin', 'access');
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code'  => 'auth.not_admin',
            ]);
        }

        return null;
    }
}
