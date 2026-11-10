<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\WebPortal\Controllers;

use Phlix\Server\Http\ErrorPages;
use Phlix\Server\Http\Middleware\SecurityHeaders;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\ViteAssets;

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
     * @param Request              $request The HTTP request — its Host header
     *                                      seeds the CSP `connect-src` ws
     *                                      allowance (see below).
     * @param array<string,string> $params  Path parameters (unused).
     *
     * @return Response 200 HTML shell, or 503 when the bundle is missing.
     *
     * @since 0.11.0 (Phase C)
     */
    public function shell(Request $request, array $params = []): Response
    {
        unset($params);

        // Validate build exists via ViteAssets manifest check.
        try {
            $viteAssets = new ViteAssets($this->publicRoot);
            $viteAssets->getEntryJsPath();
        } catch (\RuntimeException) {
            return ErrorPages::uiNotBuilt();
        }

        $shellPath = $this->publicRoot . self::SHELL_RELATIVE_PATH;
        $real = realpath($shellPath);

        if (
            $real === false
            || ! str_starts_with($real, $this->publicRoot . DIRECTORY_SEPARATOR)
            || ! is_file($real)
        ) {
            return ErrorPages::uiNotBuilt();
        }

        $html = file_get_contents($real);
        if ($html === false) {
            return ErrorPages::uiUnreadable();
        }

        // Inject window.__PHLIX__ config
        $config = [
            'app' => 'server',
            // apiBase: empty string = relative URLs (same-origin). Cross-origin would set actual URL.
            'apiBase' => '',
            'routerBase' => '/app',
            'menu' => [],
            'extraRoutes' => [],
            'features' => (object) [],
            // LW-077-a: operator-pinned WebSocket endpoint hints for the SPA,
            // mirroring the hub's shipped seam (`HUB_WS_URL`/`HUB_WS_PATH` ->
            // `wsUrl`/`wsPath`). Both default to null = "not configured", so
            // the ui lane's socketHint consumer falls back to its legacy
            // same-origin socket derivation (current shipped behavior). The
            // vendored @phlix/ui bundle already READS these keys; this row is
            // what makes the read actually resolve on server-hosted shells.
            'wsUrl' => self::configuredSocketBaseUrl(),
            'wsPath' => self::configuredSocketBasePath(),
        ];

        // The bootstrap config is emitted as an inline <script>, which the
        // repo-wide CSP (`script-src 'self'`) would otherwise block. Because
        // this block is server-templated (its JSON contents can vary per request
        // / per UI build), a per-request cryptographic nonce is the robust fix:
        // the same nonce is stamped on the <script> tag and on the CSP header, so
        // exactly this inline block executes without ever weakening script-src to
        // 'unsafe-inline'. A fresh nonce is generated on every response.
        $nonce = base64_encode(random_bytes(16));

        $configScript = '<script nonce="' . $nonce . '">window.__PHLIX__ = '
            . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . "\n";
        // Anchor-robust injection (review minor 3): `<head>` with optional
        // attributes and any casing matches — a future build emitting
        // `<head data-x>` or `<HEAD >` no longer silently skips the bootstrap
        // and strands the SPA without window.__PHLIX__. The matched tag is
        // re-emitted verbatim via the callback, so nothing between <head and >
        // is rewritten.
        $html = preg_replace_callback(
            '/<head\b[^>]*>/i',
            static fn(array $m): string => $m[0] . "\n" . $configScript,
            $html,
            1
        ) ?? $html;

        // The watch-party WebSocket lives on :8097 — a different port than
        // this response's origin — so a bare `connect-src 'self'` would make
        // every browser refuse the SPA's SyncPlay socket (consolidated review
        // C1). Allow ws/wss on the request's own validated Host (portless:
        // matches any port on this host — the HTTP worker cannot see the WS
        // worker's env-resolved port). Never the Origin header.
        $wsHost = SecurityHeaders::webSocketHostFromHeader($request->getHeader('Host'));

        return (new Response())
            ->html($html)
            ->header('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy(
                $nonce,
                $wsHost,
                // A pinned cross-host origin must be dial-able: 'self' plus the
                // portless same-host allowance would otherwise CSP-block it.
                // Same-host pins are deduped inside the CSP builder.
                $config['wsUrl'],
            ));
    }

    /**
     * Optional explicit WebSocket base origin for the SPA (`PHLIX_WS_URL`).
     *
     * When set to a valid `ws://`/`wss://` origin (optional `:port`, NO path —
     * the path belongs to {@see self::configuredSocketBasePath()}), it is
     * emitted as `window.__PHLIX__.wsUrl` so the ui lane can dial an
     * operator-pinned socket endpoint instead of deriving same-origin.
     * Absent, blank, or malformed yields `null` = the client's legacy
     * same-origin derivation (unchanged behavior). Fail-soft by design: the
     * bootstrap hint must never break the shell, mirroring the
     * `HUB_COOKIE_INSECURE` precedent — and mirroring, key-for-key, the hub's
     * shipped {@see configuredSocketBaseUrl()} twin (env name follows this
     * repo's `PHLIX_*` convention; grammar and semantics are the hub's).
     *
     * Accepted: `ws://127.0.0.1:8804`, `wss://hub.example.com`, `wss://hub.example.com:8804`.
     * Rejected (→ null): any non-ws(s) scheme, embedded path/query/userinfo,
     * a bare `//host`, or an out-of-range port.
     *
     * @return string|null The validated origin, or null when unset/invalid.
     *
     * @since 1.4.0 (LW-077-a server half)
     */
    public static function configuredSocketBaseUrl(): ?string
    {
        $raw = getenv('PHLIX_WS_URL');
        if ($raw === false) {
            return null;
        }
        $candidate = trim($raw);
        if ($candidate === '') {
            return null;
        }
        // The grammar admits `[0-9]{1,5}` ports; parse, then range-check —
        // the "out-of-range ports are rejected" promise must be real, not
        // aspirational (still fail-soft: rejection yields null, the shell
        // renders, the client falls back to same-origin derivation).
        if (preg_match('#^(wss?)://([A-Za-z0-9._-]+)(?::([0-9]{1,5}))?$#', $candidate, $parts) === 1) {
            $port = $parts[3] ?? '';
            if ($port === '') {
                return $candidate;
            }
            if ((int) $port >= 1 && (int) $port <= 65535) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Optional socket base path (`PHLIX_WS_PATH`), e.g. `/syncplay`.
     *
     * Emitted as `window.__PHLIX__.wsPath`. Must be a single absolute path
     * segment set (leading `/`, path-safe characters only, no query/fragment).
     * Absent, blank, or malformed yields `null` = legacy client behavior.
     *
     * @return string|null The validated path, or null when unset/invalid.
     *
     * @since 1.4.0 (LW-077-a server half)
     */
    public static function configuredSocketBasePath(): ?string
    {
        $raw = getenv('PHLIX_WS_PATH');
        if ($raw === false) {
            return null;
        }
        $candidate = trim($raw);
        if ($candidate === '') {
            return null;
        }
        if (preg_match('#^/[A-Za-z0-9._~/-]*$#', $candidate) === 1) {
            return $candidate;
        }

        return null;
    }
}
