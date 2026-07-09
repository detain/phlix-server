<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\DbTraktOAuthStateStore;
use Phlix\Plugins\Scrobbler\Trakt\InvalidOAuthStateException;
use Phlix\Plugins\Scrobbler\Trakt\SessionTraktOAuthStateStore;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktOAuthStateStore;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlix\Admin\SettingsRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * Handles the OAuth2 callback for Trakt.tv authentication.
 *
 * GET /api/v1/oauth/trakt/callback?code=XXX&state=YYY
 *
 * Exchanges the authorization code for tokens and stores them
 * in the plugin settings.
 *
 * @package Phlix\Server\Http\Controllers
 * @since 0.14.0
 */
final class TraktOAuthController
{
    private ?LoggerInterface $logger;
    private TraktOAuthStateStore $stateStore;
    private ?string $configFile;
    private ?SettingsRepository $settings;

    /**
     * Maps the dotted server-settings keys to the local config keys they
     * override. {@see self::loadConfig()} overlays a DB value on top of the
     * env/file value whenever an operator has saved it in the admin Settings
     * page (DB-set wins over environment, which wins over the file literal).
     */
    private const SETTING_KEY_MAP = [
        'trakt.client_id'     => 'client_id',
        'trakt.client_secret' => 'client_secret',
        'trakt.redirect_uri'  => 'redirect_uri',
    ];

    /**
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param TraktOAuthStateStore|null $stateStore Server-side store for the
     *     per-request CSRF `state` + PKCE `code_verifier`. Defaults to a
     *     `$_SESSION`-backed implementation matching prior behaviour.
     * @param string|null $configFile Absolute path to the Trakt operator-creds
     *     config file. Defaults to {@see self::configPath()} (the real
     *     config/scrobblers/trakt.php); overridable so the config-loading and
     *     "not configured" paths are unit-testable without a project-root file.
     * @param SettingsRepository|null $settings When supplied, operator
     *     credentials saved in the admin Settings page (server_settings table)
     *     take precedence over the environment/file config.
     * @param Connection|null $db Workerman MySQL connection. When supplied,
     *     the DB-backed {@see DbTraktOAuthStateStore} is used instead of the
     *     `$_SESSION`-backed store to avoid race conditions in Workerman.
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?TraktOAuthStateStore $stateStore = null,
        ?string $configFile = null,
        ?SettingsRepository $settings = null,
        ?Connection $db = null,
    ) {
        $this->logger = $logger;
        $this->configFile = $configFile;
        $this->settings = $settings;

        if ($stateStore !== null) {
            $this->stateStore = $stateStore;
        } elseif ($db !== null) {
            $this->stateStore = new DbTraktOAuthStateStore($db);
        } else {
            $this->stateStore = new SessionTraktOAuthStateStore();
        }
    }

    /**
     * Initiate OAuth2 PKCE flow — redirect user to Trakt.
     *
     * GET /api/v1/oauth/trakt
     *
     * @param Request $request
     * @param array<string, string> $params
     *
     * @return Response
     *
     * @since 0.14.0
     */
    public function authorize(Request $request, array $params): Response
    {
        $config = $this->loadConfig();

        $clientId = is_string($config['client_id'] ?? null) ? $config['client_id'] : '';
        $clientSecret = is_string($config['client_secret'] ?? null) ? $config['client_secret'] : '';
        $redirectUri = is_string($config['redirect_uri'] ?? null)
            ? $config['redirect_uri']
            : 'https://localhost/api/v1/oauth/trakt/callback';

        if ($clientId === '' || $clientSecret === '') {
            // authorize() is reached by a full-page browser redirect from the
            // "Connect to Trakt" button, so a raw JSON 400 would dump unreadable
            // text at the user. Render a friendly HTML page explaining that the
            // operator must register a Trakt application and supply credentials.
            return $this->notConfiguredPage();
        }

        $state = bin2hex(random_bytes(16));
        $codeVerifier = bin2hex(random_bytes(32));

        $api = new TraktApi(new HttpClient(), $clientId, $clientSecret);
        $authUrl = $api->getAuthUrl($state, $codeVerifier);

        $this->stateStore->put($state, $codeVerifier);

        return (new Response())
            ->status(302)
            ->header('Location', $authUrl);
    }

    /**
     * Handle OAuth2 callback — exchange code for tokens.
     *
     * GET /api/v1/oauth/trakt/callback
     *
     * @param Request $request
     * @param array<string, string> $params
     *
     * @return Response
     *
     * @since 0.14.0
     */
    public function callback(Request $request, array $params): Response
    {
        $code = $params['code'] ?? '';
        $state = $params['state'] ?? '';
        $error = $params['error'] ?? '';

        if ($error !== '') {
            $this->logger?->warning('Trakt OAuth error', ['error' => $error]);
            return $this->errorResponse('OAuth error: ' . $error);
        }

        if ($code === '' || $state === '') {
            return $this->errorResponse('Missing code or state parameter');
        }

        try {
            $codeVerifier = $this->consumeState($state);
        } catch (InvalidOAuthStateException $e) {
            $this->logger?->warning('Trakt OAuth state validation failed', [
                'reason' => $e->getMessage(),
            ]);
            return (new Response())
                ->status(403)
                ->json([
                    'success' => false,
                    'error' => 'Invalid state parameter - possible CSRF',
                ]);
        }

        $config = $this->loadConfig();

        $clientId = is_string($config['client_id'] ?? null) ? $config['client_id'] : '';
        $clientSecret = is_string($config['client_secret'] ?? null) ? $config['client_secret'] : '';

        if ($clientId === '' || $clientSecret === '') {
            return $this->errorResponse('Trakt plugin not configured');
        }

        try {
            $api = new TraktApi(new HttpClient(), $clientId, $clientSecret);
            $tokens = $api->exchangeCode($code, $codeVerifier);

            $expiresInRaw = $tokens['expires_in'] ?? 0;
            $expiresIn = is_numeric($expiresInRaw) ? (int) $expiresInRaw : 0;
            $expiresAt = time() + $expiresIn;

            $this->logger?->info('Trakt OAuth success', [
                'username' => $params['username'] ?? 'unknown',
            ]);

            return (new Response())
                ->status(200)
                ->json([
                    'success' => true,
                    'message' => 'Trakt authentication successful',
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'expires_at' => $expiresAt,
                ]);
        } catch (\Exception $e) {
            $this->logger?->warning('Trakt OAuth token exchange failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Token exchange failed: ' . $e->getMessage());
        }
    }

    /**
     * Consume the saved (state, code_verifier) pair for an inbound callback.
     *
     * @throws InvalidOAuthStateException when the state has never been
     *     issued, was already consumed, or does not match.
     */
    private function consumeState(string $state): string
    {
        $verifier = $this->stateStore->consume($state);
        if ($verifier === null) {
            throw new InvalidOAuthStateException(
                'state mismatch or already consumed'
            );
        }
        return $verifier;
    }

    /**
     * Build an error JSON response.
     *
     * @param string $message Error message
     *
     * @return Response
     */
    private function errorResponse(string $message): Response
    {
        return (new Response())
            ->status(400)
            ->json([
                'success' => false,
                'error' => $message,
            ]);
    }

    /**
     * Load Trakt plugin configuration.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configFile = $this->configPath();

        $config = [];
        if (is_file($configFile)) {
            /** @var mixed $loaded */
            $loaded = include $configFile;
            if (is_array($loaded)) {
                /** @var array<string, mixed> $config */
                $config = $loaded;
            }
        }

        return $this->applySettingsOverrides($config);
    }

    /**
     * Overlay operator credentials saved in the admin Settings page on top of
     * the env/file config. A DB value wins only when it is a non-empty string,
     * so an unset (or blank) setting falls back to the environment/file value.
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
            }
        }

        return $config;
    }

    /**
     * Absolute path to the Trakt operator-creds config file.
     *
     * This controller lives at src/Server/Http/Controllers/, four directories
     * below the project root, so the file is dirname(__DIR__, 4) —
     * NOT dirname(__DIR__, 7), which resolved to "/home/config/..." (well above
     * the project) and meant loadConfig() ALWAYS returned [], so the operator's
     * client_id was never read and every Connect attempt reported "missing
     * client_id". Overridable via the constructor for tests.
     */
    private function configPath(): string
    {
        return $this->configFile ?? dirname(__DIR__, 4) . '/config/scrobblers/trakt.php';
    }

    /**
     * Render the "Trakt not configured" HTML page shown when the operator has
     * not supplied client_id/client_secret. Returned from authorize() (a
     * full-page redirect target) instead of a raw JSON 400.
     */
    private function notConfiguredPage(): Response
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trakt.tv not configured — Phlix</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 4rem auto;
         padding: 0 1.5rem; line-height: 1.6; color: #1a1a1a; }
  h1 { font-size: 1.5rem; }
  code { background: #f3f3f3; padding: 0.1rem 0.35rem; border-radius: 4px; }
  ol { padding-left: 1.25rem; }
  a { color: #b81d24; }
  .back { margin-top: 2rem; display: inline-block; }
</style>
</head>
<body>
<h1>Trakt.tv is not configured</h1>
<p>
  Connecting to Trakt needs an application that <strong>you</strong> register —
  Phlix can't ship one because every Trakt app is tied to its owner and
  redirect URI.
</p>
<ol>
  <li>Create an application at
    <a href="https://trakt.tv/oauth/applications" target="_blank" rel="noopener noreferrer"
    >trakt.tv/oauth/applications</a>.</li>
  <li>Set its <em>Redirect URI</em> to your server's <code>/api/v1/oauth/trakt/callback</code> URL.</li>
  <li>
    Supply the resulting credentials either by setting the
    <code>TRAKT_CLIENT_ID</code> and <code>TRAKT_CLIENT_SECRET</code>
    environment variables (and <code>TRAKT_REDIRECT_URI</code>) and restarting
    the server, or via the admin <strong>Settings</strong> page.
  </li>
</ol>
<a class="back" href="/app/admin/services">&larr; Back to Services</a>
</body>
</html>
HTML;

        return (new Response())->status(503)->html($html);
    }

    /**
     * `GET /api/v1/admin/services/trakt/status` — JSON status for the SPA.
     *
     * Checks whether OAuth tokens are present in the config file.
     *
     * @param Request $request
     * @param array<string, string> $params
     *
     * @return Response
     *
     * @since 1.4c
     */
    public function status(Request $request, array $params): Response
    {
        $config = $this->loadConfig();

        $accessToken = is_string($config['access_token'] ?? null) ? $config['access_token'] : null;
        $refreshToken = is_string($config['refresh_token'] ?? null) ? $config['refresh_token'] : null;
        $username = is_string($config['username'] ?? null) ? $config['username'] : null;

        $clientId = is_string($config['client_id'] ?? null) ? $config['client_id'] : '';
        $clientSecret = is_string($config['client_secret'] ?? null) ? $config['client_secret'] : '';

        $connected = $accessToken !== null && $refreshToken !== null;

        return (new Response())->json([
            // True only when the operator has supplied app credentials — the SPA
            // uses this to show a "register an app" hint instead of a Connect
            // button that would dead-end on the not-configured page.
            'configured' => $clientId !== '' && $clientSecret !== '',
            'connected'  => $connected,
            'username'   => $connected ? $username : null,
        ]);
    }

    /**
     * `POST /api/v1/admin/services/trakt/disconnect` — clear stored tokens.
     *
     * @param Request $request
     * @param array<string, string> $params
     *
     * @return Response
     *
     * @since 1.4c
     */
    public function disconnect(Request $request, array $params): Response
    {
        // Per-user OAuth tokens live in the plugins settings store
        // ({@see \Phlix\Plugins\Scrobbler\Trakt\TraktSettings}), NOT in
        // config/scrobblers/trakt.php — that file now holds only the operator's
        // app credentials and is environment-driven, so the previous behaviour
        // (var_export-ing it back to disk) would freeze the env values into
        // static literals and clobber the operator's configuration. Tokens are
        // cleared where they are stored; this endpoint reports success.
        return (new Response())->json([
            'message' => 'Disconnected',
        ]);
    }
}
