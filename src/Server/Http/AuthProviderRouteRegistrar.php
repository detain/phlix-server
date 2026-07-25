<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http;

use Phlix\Server\Http\Controllers\AccountLinkController;
use Phlix\Server\Http\Middleware\AuthMiddleware;
use Phlix\Plugins\Github\Controller\GithubCallbackController;
use Phlix\Plugins\Oidc\Controller\OidcCallbackController;

/**
 * The SINGLE, mandatory registration site for every external-auth-provider HTTP
 * route (S47 route-registration hook).
 *
 * ## Why this exists (the S44 dead-OIDC root cause)
 *
 * The S44 bug was structural, not incidental: {@see OidcCallbackController} was
 * fully implemented but its routes were NEVER wired into the {@see Router}, so
 * the entire OIDC login surface was dead code. Nothing in the codebase FORCED a
 * new auth provider to also register its routes — route wiring was scattered
 * inline in {@see \Phlix\Server\Core\Application}, easy to forget.
 *
 * This registrar closes that class of bug by making auth-provider route
 * registration ONE explicit, unit-tested step. {@see \Phlix\Server\Core\Application}
 * calls {@see self::register()} exactly once; the registrar owns the full
 * inventory of provider routes and their auth grouping.
 *
 * ## MANDATORY CONTRACT for new providers
 *
 * When you add a new external auth provider (S48 GitHub, and any future SAML /
 * passkey / social provider), you MUST add its authorize/callback and any
 * link/unlink routes to {@see self::register()} — do NOT register auth routes
 * anywhere else. The accompanying test
 * ({@see \Phlix\Tests\Unit\Server\Http\AuthProviderRouteRegistrarTest}) asserts
 * the wired routes, so a provider whose routes are missing is caught in CI
 * rather than shipping dead like OIDC did.
 *
 * A full `LifecycleInterface::registerRoutes()` hook was considered but is
 * deferred: that interface lives in the vendored `detain/phlix-shared` package,
 * so adding a method to it is a cross-repo breaking change to every existing
 * plugin implementer — out of scope for this step. This centralized, tested,
 * documented registrar is the "explicit register-provider-routes step + a
 * mandatory contract" the plan permits in its place.
 *
 * @package Phlix\Server\Http
 * @since 0.101.0
 */
final class AuthProviderRouteRegistrar
{
    /**
     * Wire every external-auth-provider route onto the given router.
     *
     * @param Router $router The application router (handlers are `[Class, method]`
     *                       arrays resolved lazily from the DI container at
     *                       dispatch, matching the rest of the auth surface).
     * @return void
     */
    public function register(Router $router): void
    {
        // 1. UNAUTHENTICATED provider login entry points. The OIDC
        //    authorization-code + PKCE flow IS the login entry: the IdP redirect
        //    to /auth/oidc/callback carries no Phlix session, so it cannot sit
        //    behind AuthMiddleware. (S44.)
        $router->oidcAuth(OidcCallbackController::class);

        // S48 GitHub OAuth2 login entry. Same rationale as OIDC: the GitHub
        // redirect to /auth/github/callback carries no Phlix session (the link
        // intent is recovered from the server-side OAuth2 state store), so both
        // routes are unauthenticated.
        $router->githubAuth(GithubCallbackController::class);

        // 2. AUTHENTICATED identity-management endpoints. The current user is
        //    read from the validated session by AuthMiddleware:
        //      GET    /auth/identities            list this user's identities (S45)
        //      DELETE /auth/identities/{id}       UNLINK one identity            (S47)
        //      GET    /auth/identities/link/oidc  START an OIDC link             (S45)
        //      POST   /auth/identities/link/ldap  link via a real LDAP bind      (S45)
        //    The OIDC *callback* stays on the unauthenticated oidcAuth path above
        //    (the IdP redirect carries no session; the link intent is recovered
        //    from the server-side OIDC state store).
        $router->group(
            '',
            static function (Router $r): void {
                $r->get('/auth/identities', [AccountLinkController::class, 'listIdentities']);
                $r->delete('/auth/identities/{id}', [AccountLinkController::class, 'unlink']);
                $r->get('/auth/identities/link/oidc', [OidcCallbackController::class, 'authorizeLink']);
                $r->post('/auth/identities/link/ldap', [AccountLinkController::class, 'linkLdap']);
                // S48: start a GitHub link (the callback stays on the unauthenticated
                // /auth/github/callback path; the link intent is recovered from the
                // server-side OAuth2 state store). Unlink is provider-generic via the
                // DELETE /auth/identities/{id} route above.
                $r->get('/auth/identities/link/github', [GithubCallbackController::class, 'authorizeLink']);
            },
            [new AuthMiddleware()],
        );
    }
}
