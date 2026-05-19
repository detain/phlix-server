<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Plugins\Scrobbler\Trakt\HttpClient;
use Phlex\Plugins\Scrobbler\Trakt\InvalidOAuthStateException;
use Phlex\Plugins\Scrobbler\Trakt\SessionTraktOAuthStateStore;
use Phlex\Plugins\Scrobbler\Trakt\TraktApi;
use Phlex\Plugins\Scrobbler\Trakt\TraktOAuthStateStore;
use Phlex\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Handles the OAuth2 callback for Trakt.tv authentication.
 *
 * GET /api/v1/oauth/trakt/callback?code=XXX&state=YYY
 *
 * Exchanges the authorization code for tokens and stores them
 * in the plugin settings.
 *
 * @package Phlex\Server\Http\Controllers
 * @since 0.14.0
 */
final class TraktOAuthController
{
    private ?LoggerInterface $logger;
    private TraktOAuthStateStore $stateStore;

    /**
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param TraktOAuthStateStore|null $stateStore Server-side store for the
     *     per-request CSRF `state` + PKCE `code_verifier`. Defaults to a
     *     `$_SESSION`-backed implementation matching prior behaviour.
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?TraktOAuthStateStore $stateStore = null,
    ) {
        $this->logger = $logger;
        $this->stateStore = $stateStore ?? new SessionTraktOAuthStateStore();
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

        if ($clientId === '') {
            return $this->errorResponse('Trakt plugin not configured: missing client_id');
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
        $configFile = dirname(__DIR__, 7) . '/config/scrobblers/trakt.php';

        if (is_file($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;

            return $config;
        }

        return [];
    }
}
