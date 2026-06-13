<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin-side "Connect Last.fm" flow controller.
 *
 * Two-step web flow:
 *
 *  1. `GET /admin/lastfm` — shows the connect page. If the user has not
 *     yet authorised, the page contains a link to
 *     `https://www.last.fm/api/auth/?api_key=...&cb=...` that takes
 *     them off-site to authorise. After approval, Last.fm redirects
 *     back to the configured callback URL with a `?token=...`
 *     parameter.
 *
 *  2. `GET /admin/lastfm/callback?token=...` — exchanges the request
 *     token for a session key via {@see LastfmApi::getSession()} and
 *     persists it for the calling user via
 *     {@see LastfmSessionRepository::save()}.
 *
 * Disconnect uses `POST /admin/lastfm/disconnect` to delete the row.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 0.15.0
 */
final class LastfmController
{
    /**
     * @param LastfmConfig            $config   Wraps `config/lastfm.php`.
     * @param LastfmSessionRepository $sessions Per-user session-key store.
     * @param LastfmApi               $api      Last.fm HTTP client.
     */
    public function __construct(
        private readonly LastfmConfig $config,
        private readonly LastfmSessionRepository $sessions,
        private readonly LastfmApi $api,
    ) {
    }

    /**
     * `GET /admin/lastfm` — render the connect page.
     *
     * Builds the Last.fm authorisation URL when configured. Reports the
     * current session row for the calling user (if any) so the template
     * can show a "connected as X" panel.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function index(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        $session = $userId !== '' ? $this->sessions->findByUserId($userId) : null;
        $configured = $this->config->isUsable();

        $authUrl = '';
        if ($configured) {
            $query = [
                'api_key' => $this->config->apiKey,
            ];
            if ($this->config->callbackUrl !== '') {
                $query['cb'] = $this->config->callbackUrl;
            }
            $authUrl = 'https://www.last.fm/api/auth/?' . http_build_query($query);
        }

        // This controller lives at src/Server/Http/Controllers/Admin/, i.e.
        // five directories below the project root, so the template base is
        // dirname(__DIR__, 5) — NOT 4 (which resolved to the non-existent
        // src/public/templates and threw "Unable to load template
        // admin/lastfm.tpl" → HTTP 500 on the Connect Last.fm page).
        $smarty = new \Smarty();
        $smarty->setTemplateDir(dirname(__DIR__, 5) . '/public/templates');
        $smarty->assign('configured', $configured);
        $smarty->assign('session', $session === null ? null : [
            'username'     => $this->config->username !== '' ? $this->config->username : ($session['user_id']),
            'connected_at' => $session['connected_at'],
        ]);
        $smarty->assign('auth_url', $authUrl);
        $smarty->assign('callback_url', $this->config->callbackUrl);
        // Shared admin nav (partials/admin-nav.tpl) reads $current_page to mark
        // the active tab; without it the layout emits "Undefined array key
        // current_page" warnings on every render.
        $smarty->assign('current_page', 'admin_lastfm');

        return (new Response())->html((string) $smarty->fetch('admin/lastfm.tpl'));
    }

    /**
     * `GET /admin/lastfm/callback?token=...` — finishes the OAuth-like
     * handshake by exchanging the request token for a session key and
     * persisting it for the calling user.
     *
     * Redirects back to `/admin/lastfm` on success, returns 400 JSON on
     * a malformed/missing token.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function callback(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        if (!$this->config->isUsable()) {
            return (new Response())->status(503)->json([
                'error' => 'Service Unavailable',
                'code'  => 'lastfm.not_configured',
            ]);
        }

        $tokenRaw = $request->query['token'] ?? null;
        if (!is_string($tokenRaw) || $tokenRaw === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code'  => 'missing_token',
            ]);
        }

        $session = $this->api->getSession($tokenRaw);
        if ($session === null) {
            return (new Response())->status(502)->json([
                'error' => 'Bad Gateway',
                'code'  => 'lastfm.session_exchange_failed',
            ]);
        }

        $this->sessions->save($userId, $session['session_key']);

        return (new Response())->status(302)->header('Location', '/admin/lastfm');
    }

    /**
     * `POST /admin/lastfm/disconnect` — remove the calling user's
     * Last.fm session.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function disconnect(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }
        $this->sessions->delete($userId);
        return (new Response())->status(302)->header('Location', '/admin/lastfm');
    }

    /**
     * `GET /api/v1/oauth/lastfm` — SPA-friendly "Connect Last.fm" entry point.
     *
     * Mirrors the Trakt flow (`GET /api/v1/oauth/trakt`): instead of rendering
     * the legacy Smarty page, this issues a top-level browser redirect.
     *
     *  - When Last.fm is configured (`isUsable()`), 302-redirects straight to
     *    `https://www.last.fm/api/auth/?api_key=...&cb=<api-callback>`, where
     *    `<api-callback>` is this server's `GET /api/v1/oauth/lastfm/callback`
     *    URL derived from the request host (so the handshake lands on the new
     *    SPA-aware callback, NOT the legacy `/admin/lastfm/callback`).
     *  - When NOT configured, 302-redirects back to the SPA Services page with
     *    `?lastfm=not_configured` rather than rendering any Smarty markup.
     *
     * This is an admin-gated route (registered under AdminMiddleware), so the
     * authenticated user is already established by the time we get here.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function apiAuthorize(Request $request, array $params): Response
    {
        if (!$this->config->isUsable()) {
            return $this->redirect('/app/admin/services?lastfm=not_configured');
        }

        $query = [
            'api_key' => $this->config->apiKey,
            'cb'      => $this->apiCallbackUrl($request),
        ];

        $authUrl = 'https://www.last.fm/api/auth/?' . http_build_query($query);

        return $this->redirect($authUrl);
    }

    /**
     * `GET /api/v1/oauth/lastfm/callback?token=...` — SPA-friendly callback.
     *
     * Reuses the existing exchange ({@see LastfmApi::getSession()} +
     * {@see LastfmSessionRepository::save()}) but, unlike the legacy
     * {@see self::callback()}, ALWAYS resolves to a top-level browser redirect
     * back to the SPA Services page — never a JSON 4xx/5xx that would strand
     * the browser on a blank error body:
     *
     *  - success                  → `/app/admin/services?lastfm=connected`
     *  - missing/invalid token,
     *    exchange failure, or not
     *    configured / no user      → `/app/admin/services?lastfm=error`
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function apiCallback(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '' || !$this->config->isUsable()) {
            return $this->redirect('/app/admin/services?lastfm=error');
        }

        $tokenRaw = $request->query['token'] ?? null;
        if (!is_string($tokenRaw) || $tokenRaw === '') {
            return $this->redirect('/app/admin/services?lastfm=error');
        }

        $session = $this->api->getSession($tokenRaw);
        if ($session === null) {
            return $this->redirect('/app/admin/services?lastfm=error');
        }

        $this->sessions->save($userId, $session['session_key']);

        return $this->redirect('/app/admin/services?lastfm=connected');
    }

    /**
     * Build a 302 redirect to the given location.
     */
    private function redirect(string $location): Response
    {
        return (new Response())->status(302)->header('Location', $location);
    }

    /**
     * Build the absolute URL of this server's new API callback endpoint
     * (`/api/v1/oauth/lastfm/callback`) from the inbound request host.
     *
     * The legacy operator-configured `callback_url` typically points at the
     * old `/admin/lastfm/callback`, so the new flow derives its own callback
     * from the request host (mirroring how Trakt's redirect lands on the API
     * callback). Falls back to a relative path when no Host header is present
     * (e.g. unit-test stubs).
     */
    private function apiCallbackUrl(Request $request): string
    {
        $path = '/api/v1/oauth/lastfm/callback';

        $host = $request->headers['HOST'] ?? ($request->headers['Host'] ?? '');
        if (!is_string($host) || $host === '') {
            return $path;
        }

        // X-Forwarded-Proto is set by the fronting HAProxy/TLS terminator;
        // default to https since Last.fm requires an https callback in
        // production and the public deployment is TLS-terminated.
        $proto = $request->headers['X-FORWARDED-PROTO']
            ?? ($request->headers['X-Forwarded-Proto'] ?? 'https');
        $scheme = (is_string($proto) && $proto !== '') ? $proto : 'https';

        return $scheme . '://' . $host . $path;
    }

    /**
     * `GET /api/v1/admin/services/lastfm/status` — JSON status for the SPA.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function status(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        $session = $this->sessions->findByUserId($userId);
        $username = $session !== null ? ($this->config->username ?: $userId) : null;

        return (new Response())->json([
            'connected'   => $session !== null,
            'username'    => $username,
            'api_key_set' => $this->config->isUsable(),
        ]);
    }

    /**
     * `POST /api/v1/admin/services/lastfm/disconnect` — JSON disconnect for SPA.
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function apiDisconnect(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        $this->sessions->delete($userId);

        return (new Response())->json([
            'message' => 'Disconnected',
        ]);
    }
}
