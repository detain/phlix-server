<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal\Controllers;

use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Serves the HTML shell for the client-side admin SPA (step 0.4).
 *
 * The admin console is a React + TypeScript + Vite single-page app whose
 * source lives in `phlix-server/admin-ui/` and whose built bundle is
 * committed to `public/assets/admin/` (see the step 0.4 worklog for the
 * "commit built assets" decision — the production Workerman server then
 * has no Node build dependency at runtime). This controller does one
 * thing: return that built `index.html` shell for `/admin` and any
 * `/admin/*` deep link so client-side routing works on reload. All data
 * is fetched by the SPA from the existing JWT-authed JSON API
 * (`/api/v1/*`); this controller renders no data itself.
 *
 * AuthN/AuthZ mirrors the {@see PluginAdminPageController} precedent: the
 * controller does NOT re-validate the admin role — the caller gates the
 * route with {@see AdminMiddleware::checkAccess()} (single source of truth
 * for the admin gate + audit logging). For the SPA shell, a failed gate
 * (401 unauthenticated OR 403 non-admin) maps to a 302 redirect to
 * `/login` rather than a JSON/HTML error body, because the shell route is
 * a browser navigation and the user must (re-)authenticate via the SSR
 * login flow. {@see self::gateRedirect()} centralises that mapping so both
 * entry points (`public/index.php` and
 * `src/Server/Workerman/HttpHandler.php`) behave identically.
 *
 * The existing SSR `/admin/plugins` and `/admin/dashboard` routes are NOT
 * affected: callers MUST dispatch those specific prefixes BEFORE the
 * generic `/admin` + `/admin/*` SPA catch-all, so they keep winning until
 * they are folded into the SPA in a later phase.
 *
 * @package Phlix\Server\WebPortal\Controllers
 * @since   0.11.0 (Step 0.4)
 */
final class AdminAppController
{
    /**
     * Relative path (under the public root) of the built SPA shell.
     */
    private const SHELL_RELATIVE_PATH = '/assets/admin/index.html';

    /**
     * @param string $publicRoot Absolute path to the server's `public/`
     *                           directory (the same value the static-file
     *                           handler uses). The built SPA shell is read
     *                           from `$publicRoot . self::SHELL_RELATIVE_PATH`.
     */
    public function __construct(private readonly string $publicRoot)
    {
    }

    /**
     * Return the SPA HTML shell for an authorised admin.
     *
     * Reads the committed `public/assets/admin/index.html`. If the bundle
     * has not been built/committed yet (the file is absent), returns a 503
     * with an actionable message rather than a confusing blank 200 — this
     * makes a missing build obvious in development and CI.
     *
     * @param Request              $request The HTTP request (unused; the
     *                                       shell is static).
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response 200 HTML shell, or 503 when the bundle is missing.
     *
     * @since 0.11.0 (Step 0.4)
     */
    public function shell(Request $request, array $params = []): Response
    {
        unset($request, $params);

        $shellPath = $this->publicRoot . self::SHELL_RELATIVE_PATH;
        $real = realpath($shellPath);

        // Defence in depth: the resolved path must stay under public/ and
        // be a real file (the constant is fixed, so this only guards
        // against a mis-configured public root / symlink escape).
        if (
            $real === false
            || ! str_starts_with($real, $this->publicRoot . DIRECTORY_SEPARATOR)
            || ! is_file($real)
        ) {
            return (new Response())
                ->status(503)
                ->html(
                    '<h1>503 — Admin UI not built</h1>'
                    . '<p>The admin SPA bundle is missing. '
                    . 'Run <code>cd admin-ui &amp;&amp; npm install &amp;&amp; npm run build</code>.</p>'
                );
        }

        $html = file_get_contents($real);
        if ($html === false) {
            return (new Response())
                ->status(503)
                ->html('<h1>503 — Admin UI could not be read</h1>');
        }

        return (new Response())->html($html);
    }

    /**
     * Map an {@see AdminMiddleware::checkAccess()} result to the SPA's
     * response. A `null` gate result means "allowed" and the caller should
     * render {@see self::shell()}; a non-null result (401 or 403) is mapped
     * to a 302 redirect to `/login` for both entry points.
     *
     * Shared so the redirect-on-deny behaviour for the SPA shell route is
     * defined in exactly one place.
     *
     * @param int|null $gateStatus The value returned by
     *                             {@see AdminMiddleware::checkAccess()}.
     *
     * @return Response|null `null` when the request is allowed (caller
     *                        renders the shell); otherwise a 302 redirect
     *                        to `/login`.
     *
     * @since 0.11.0 (Step 0.4)
     */
    public function gateRedirect(?int $gateStatus): ?Response
    {
        if ($gateStatus === null) {
            return null;
        }

        // Both 401 (unauthenticated) and 403 (authenticated non-admin) send
        // the browser to the SSR login page, where the user authenticates
        // / escalates. The SPA shell cannot render a JSON error envelope.
        return (new Response())->redirect('/login', 302);
    }
}
