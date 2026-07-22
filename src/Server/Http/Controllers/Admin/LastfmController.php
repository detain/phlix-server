<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Integrations\Lastfm\DbLastfmOAuthStateStore;
use Phlix\Server\Integrations\Lastfm\LastfmApi;
use Phlix\Server\Integrations\Lastfm\LastfmConfig;
use Phlix\Server\Integrations\Lastfm\LastfmOAuthStateStore;
use Phlix\Server\Integrations\Lastfm\LastfmSessionRepository;
use Phlix\Server\Integrations\Lastfm\SessionLastfmOAuthStateStore;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Workerman\MySQL\Connection;

/**
 * Admin-side "Connect Last.fm" flow controller (JSON / SPA OAuth only).
 *
 * The legacy Smarty SSR pages (`/admin/lastfm[/callback|/disconnect]`) were
 * retired with the rest of the server-side Smarty UI; the remaining surface is:
 *
 *  1. `GET /api/v1/oauth/lastfm` — SPA-friendly entry point that 302-redirects
 *     to `https://www.last.fm/api/auth/?api_key=...&cb=...`.
 *  2. `GET /api/v1/oauth/lastfm/callback?token=...` — exchanges the request
 *     token for a session key via {@see LastfmApi::getSession()} and persists
 *     it for the calling user via {@see LastfmSessionRepository::save()}.
 *
 * Status (`GET /lastfm/status`) and disconnect (`POST /lastfm/disconnect`) are
 * served by {@see status()} / {@see apiDisconnect()} as JSON.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 0.15.0
 */
final class LastfmController
{
    /**
     * Server-side CSRF `state => userId` store for the SPA OAuth flow.
     */
    private readonly LastfmOAuthStateStore $stateStore;
    private ?SettingsRepository $settings;

    /**
     * Maps the dotted server-settings keys to the local config keys they
     * override. {@see self::applySettingsOverrides()} overlays a DB value
     * on top of the env/file value whenever an operator has saved it in
     * the admin Settings page (DB-set wins over environment, which wins
     * over the file literal).
     */
    private const SETTING_KEY_MAP = [
        'lastfm.api_key'       => 'api_key',
        'lastfm.shared_secret' => 'shared_secret',
        'lastfm.enabled'       => 'enabled',
    ];

    /**
     * @param LastfmConfig                 $config     Wraps `config/lastfm.php`.
     * @param LastfmSessionRepository      $sessions   Per-user session-key store.
     * @param LastfmApi                    $api        Last.fm HTTP client.
     * @param LastfmOAuthStateStore|null   $stateStore Server-side store for the
     *     per-request CSRF `state` bound to the initiating user UUID. Defaults
     *     to a `$_SESSION`-backed implementation, swappable for tests / DB-backed
     *     production stores.
     * @param Connection|null             $db         Workerman MySQL connection.
     *     When supplied, the DB-backed {@see DbLastfmOAuthStateStore} is used
     *     instead of the `$_SESSION`-backed store to avoid race conditions.
     * @param SettingsRepository|null      $settings   When supplied, operator
     *     credentials saved in the admin Settings page (server_settings table)
     *     take precedence over the environment/file config.
     */
    public function __construct(
        private readonly LastfmConfig $config,
        private readonly LastfmSessionRepository $sessions,
        private readonly LastfmApi $api,
        ?LastfmOAuthStateStore $stateStore = null,
        ?Connection $db = null,
        ?SettingsRepository $settings = null,
    ) {
        if ($stateStore !== null) {
            $this->stateStore = $stateStore;
        } elseif ($db !== null) {
            $this->stateStore = new DbLastfmOAuthStateStore($db);
        } else {
            $this->stateStore = new SessionLastfmOAuthStateStore();
        }
        $this->settings = $settings;
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

        $userId = $request->userId ?? '';
        if ($userId === '') {
            // AdminMiddleware should already have populated userId; guard so a
            // forged/unauthenticated request can never seed a state entry.
            return $this->redirect('/app/admin/services?lastfm=error');
        }

        // CSRF state, bound server-side to the initiating user, so the callback
        // can both validate the state AND recover which user initiated the flow.
        $state = bin2hex(random_bytes(16));
        $this->stateStore->put($state, $userId);

        // Last.fm appends `&token=<token>` to whatever `cb` we hand it, so we
        // carry our `state` on the cb URL itself (it is preserved verbatim).
        $cb = $this->apiCallbackUrl($request) . '?' . http_build_query(['state' => $state]);

        $query = [
            'api_key' => $this->config->apiKey,
            'cb'      => $cb,
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
     *    exchange failure, not
     *    configured, or a missing/
     *    unknown/expired CSRF state → `/app/admin/services?lastfm=error`
     *
     * The CSRF `state` (issued + stored by {@see self::apiAuthorize()}) is
     * validated and consumed BEFORE any session exchange; the resulting
     * session key is bound to the user recovered FROM the state store (so a
     * forged callback cannot link an attacker's Last.fm account to a victim).
     *
     * @param array<string, string> $params Path parameters (unused).
     */
    public function apiCallback(Request $request, array $params): Response
    {
        if (!$this->config->isUsable()) {
            return $this->redirect('/app/admin/services?lastfm=error');
        }

        // Validate + consume the CSRF state FIRST, before any session exchange.
        // A missing/unknown/expired state is treated as a forged callback: we
        // bail out without touching the token exchange or the session store.
        $stateRaw = $request->query['state'] ?? null;
        if (!is_string($stateRaw) || $stateRaw === '') {
            return $this->redirect('/app/admin/services?lastfm=error');
        }

        $stateUserId = $this->stateStore->consume($stateRaw);
        if ($stateUserId === null || $stateUserId === '') {
            return $this->redirect('/app/admin/services?lastfm=error');
        }

        // The session key MUST be bound to the user who initiated the flow
        // (recovered from the server-side state), not solely to whatever the
        // ambient cookie says. When the cookie is present, it must agree.
        $cookieUserId = $request->userId ?? '';
        if ($cookieUserId !== '' && !hash_equals($stateUserId, $cookieUserId)) {
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

        $this->sessions->save($stateUserId, $session['session_key']);

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
     * Overlay operator credentials saved in the admin Settings page on top of
     * the env/file config. A string DB value wins only when it is non-empty,
     * so an unset (or blank) setting falls back to the environment/file value.
     * A boolean DB value (e.g. `lastfm.enabled`) always wins.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function applySettingsOverrides(array $config): array
    {
        if ($this->settings === null) {
            return $config;
        }

        foreach (self::SETTING_KEY_MAP as $settingKey => $configKey) {
            $override = $this->settings->getOverride($settingKey);
            $value = $override['value'] ?? null;

            if (is_string($value) && $value !== '') {
                $config[$configKey] = $value;
            } elseif (is_bool($value)) {
                $config[$configKey] = $value;
            }
        }

        return $config;
    }

    /**
     * Build the raw config array with DB overrides applied, then construct
     * a {@see LastfmConfig} from it.
     */
    private function buildOverrideAwareConfig(): LastfmConfig
    {
        $configArray = [
            'api_key'       => $this->config->apiKey,
            'shared_secret' => $this->config->sharedSecret,
            'enabled'       => $this->config->enabled,
            'callback_url'  => $this->config->callbackUrl,
            'username'      => $this->config->username,
        ];

        $configArray = $this->applySettingsOverrides($configArray);

        return LastfmConfig::fromArray($configArray);
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

        // Use override-aware config so DB-stored credentials are respected
        $overrideAwareConfig = $this->buildOverrideAwareConfig();

        return (new Response())->json([
            'connected'   => $session !== null,
            'username'    => $username,
            'api_key_set' => $overrideAwareConfig->isUsable(),
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
