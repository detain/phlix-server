<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers\Admin;

use Phlex\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlex\Plugins\Scrobbler\Lastfm\LastfmConfig;
use Phlex\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

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
 * @package Phlex\Server\Http\Controllers\Admin
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

        $smarty = new \Smarty();
        $smarty->setTemplateDir(dirname(__DIR__, 4) . '/public/templates');
        $smarty->assign('configured', $configured);
        $smarty->assign('session', $session === null ? null : [
            'username'     => $this->config->username !== '' ? $this->config->username : ($session['user_id']),
            'connected_at' => $session['connected_at'],
        ]);
        $smarty->assign('auth_url', $authUrl);
        $smarty->assign('callback_url', $this->config->callbackUrl);

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
}
