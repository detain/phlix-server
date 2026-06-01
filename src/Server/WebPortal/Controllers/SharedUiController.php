<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Serves the HTML shell for the shared Vue 3 SPA (`@phlix/ui`) at `/app/*`.
 *
 * The SPA is built by the thin Vite consumer in `web-ui/`, whose output
 * is committed to `public/assets/app/`. This controller returns that built
 * `index.html` shell for `/app` and any `/app/*` deep link so client-side
 * routing works on reload. All data is fetched by the SPA from the existing
 * JWT-authed JSON API (`/api/v1/*`); this controller renders no data itself.
 *
 * Unlike {@see AdminAppController}, this controller does NOT gate access —
 * the Vue SPA itself handles authentication via `ApiClient` + `tokenStore`.
 * The shell is served unconditionally; a missing bundle returns 503 with an
 * actionable message.
 *
 * @package Phlix\Server\WebPortal\Controllers
 * @since   0.11.0 (Phase C)
 */
final class SharedUiController
{
    /**
     * Relative path (under the public root) of the built SPA shell.
     */
    private const SHELL_RELATIVE_PATH = '/assets/app/index.html';

    /**
     * @param string $publicRoot Absolute path to the server's `public/`
     *                           directory. The built SPA shell is read from
     *                           `$publicRoot . self::SHELL_RELATIVE_PATH`.
     */
    public function __construct(private readonly string $publicRoot)
    {
    }

    /**
     * Return the SPA HTML shell.
     *
     * Reads `public/assets/app/index.html`. If the bundle has not been
     * built/committed yet (the file is absent), returns a 503 with an
     * actionable message rather than a confusing blank 200.
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response 200 HTML shell, or 503 when the bundle is missing.
     *
     * @since 0.11.0 (Phase C)
     */
    public function shell(Request $request, array $params = []): Response
    {
        unset($request, $params);

        $shellPath = $this->publicRoot . self::SHELL_RELATIVE_PATH;
        $real = realpath($shellPath);

        if (
            $real === false
            || ! str_starts_with($real, $this->publicRoot . DIRECTORY_SEPARATOR)
            || ! is_file($real)
        ) {
            return (new Response())
                ->status(503)
                ->html(
                    '<h1>503 — Shared UI not built</h1>'
                    . '<p>The Vue SPA bundle is missing. '
                    . 'Run <code>cd web-ui &amp;&amp; npm install &amp;&amp; npm run build</code>.</p>'
                );
        }

        $html = file_get_contents($real);
        if ($html === false) {
            return (new Response())
                ->status(503)
                ->html('<h1>503 — Shared UI could not be read</h1>');
        }

        return (new Response())->html($html);
    }
}
