<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Media\Library\MediaItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Trakt.tv API v3 client implementing OAuth2 PKCE and scrobble methods.
 *
 * Trakt uses a 3-state scrobble protocol (start/pause/stop) that differs
 * from Last.fm's 2-state (start/stop). This client handles:
 * - OAuth2 PKCE authentication flow
 * - Automatic token refresh on 401 responses
 * - Scrobble start/pause/stop calls
 * - Watched history sync (pull and push)
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
class TraktApi
{
    private const BASE_URL = 'https://api.trakt.tv';

    private readonly LoggerInterface $logger;

    /**
     * @param HttpClientInterface $http HTTP client for API requests
     * @param string $clientId Trakt.tv application client ID
     * @param string $clientSecret Trakt.tv application client secret
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the OAuth2 PKCE authorization URL.
     *
     * @param string $state CSRF protection state token
     * @param string $codeVerifier PKCE code verifier (will be hashed to code_challenge)
     *
     * @return string Full authorization URL to redirect user to
     *
     * @since 0.14.0
     */
    public function getAuthUrl(string $state, string $codeVerifier): string
    {
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return self::BASE_URL . '/oauth/authorize?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for access/refresh tokens.
     *
     * @param string $code Authorization code from OAuth callback
     * @param string $codeVerifier PKCE code verifier used in initial request
     *
     * @return array<string, mixed> Token response with access_token, refresh_token, expires_in
     *
     * @throws TraktApiException When token exchange fails
     * @since 0.14.0
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $params = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->getRedirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ];

        $response = $this->http->post(self::BASE_URL . '/oauth/token', $params);

        if (isset($response['error'])) {
            $message = is_string($response['error_description'] ?? null)
                ? $response['error_description']
                : (is_string($response['error'] ?? null) ? $response['error'] : 'Token exchange failed');
            throw new TraktApiException($message);
        }

        return [
            'access_token' => is_string($response['access_token'] ?? null) ? $response['access_token'] : '',
            'refresh_token' => is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : '',
            'expires_in' => is_int($response['expires_in'] ?? null) ? $response['expires_in'] : 0,
        ];
    }

    /**
     * Refresh an expired access token.
     *
     * @param string $refreshToken Current refresh token
     *
     * @return array<string, mixed> Token response with access_token, refresh_token, expires_in
     *
     * @throws TraktApiException When refresh fails
     * @since 0.14.0
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $params = [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->getRedirectUri(),
            'grant_type' => 'refresh_token',
        ];

        $response = $this->http->post(self::BASE_URL . '/oauth/token', $params);

        if (isset($response['error'])) {
            $message = is_string($response['error_description'] ?? null)
                ? $response['error_description']
                : (is_string($response['error'] ?? null) ? $response['error'] : 'Token refresh failed');
            throw new TraktApiException($message);
        }

        return [
            'access_token' => is_string($response['access_token'] ?? null) ? $response['access_token'] : '',
            'refresh_token' => is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : '',
            'expires_in' => is_int($response['expires_in'] ?? null) ? $response['expires_in'] : 0,
        ];
    }

    /**
     * Submit a scrobble start for a media item.
     *
     * Trakt's scrobble API uses a 3-state model. This method sends the
     * "start" action when playback begins.
     *
     * @param MediaItem $item Media item being played
     * @param int $progress Current playback position in seconds
     * @param string $accessToken OAuth access token
     *
     * @return array<string, mixed> Scrobble response with action and watched_at
     *
     * @throws TraktApiException|TraktAuthenticationException On API error or auth failure
     * @since 0.14.0
     */
    public function scrobbleStart(MediaItem $item, int $progress, string $accessToken): array
    {
        return $this->scrobble('start', $item, $progress, $accessToken);
    }

    /**
     * Submit a scrobble pause for a media item.
     *
     * Trakt's scrobble API uses a 3-state model. This method sends the
     * "pause" action when playback progress is updated.
     *
     * @param MediaItem $item Media item being played
     * @param int $progress Current playback position in seconds
     * @param string $accessToken OAuth access token
     *
     * @return array<string, mixed> Scrobble response with action and watched_at
     *
     * @throws TraktApiException|TraktAuthenticationException On API error or auth failure
     * @since 0.14.0
     */
    public function scrobblePause(MediaItem $item, int $progress, string $accessToken): array
    {
        return $this->scrobble('pause', $item, $progress, $accessToken);
    }

    /**
     * Submit a scrobble stop for a media item.
     *
     * Trakt's scrobble API uses a 3-state model. This method sends the
     * "stop" action when playback ends or is stopped.
     *
     * @param MediaItem $item Media item that was played
     * @param int $progress Final playback position in seconds
     * @param string $accessToken OAuth access token
     *
     * @return array<string, mixed> Scrobble response with action and watched_at
     *
     * @throws TraktApiException|TraktAuthenticationException On API error or auth failure
     * @since 0.14.0
     */
    public function scrobbleStop(MediaItem $item, int $progress, string $accessToken): array
    {
        return $this->scrobble('stop', $item, $progress, $accessToken);
    }

    /**
     * Internal scrobble dispatcher for all three actions.
     *
     * @param string $action Scrobble action (start|pause|stop)
     * @param MediaItem $item Media item
     * @param int $progress Playback position in seconds
     * @param string $accessToken OAuth access token
     *
     * @return array<string, mixed>
     *
     * @throws TraktApiException|TraktAuthenticationException
     */
    private function scrobble(string $action, MediaItem $item, int $progress, string $accessToken): array
    {
        $movie = null;
        $episode = null;

        if ($item->type === 'movie') {
            $movie = [
                'ids' => [
                    'trakt' => $item->metadata['trakt_id'] ?? null,
                    'slug' => $item->metadata['slug'] ?? null,
                    'imdb' => $item->metadata['imdb_id'] ?? null,
                    'tmdb' => $item->metadata['tmdb_id'] ?? null,
                ],
            ];
        } elseif ($item->type === 'episode') {
            $episode = [
                'ids' => [
                    'trakt' => $item->metadata['trakt_id'] ?? null,
                    'tvdb' => $item->metadata['tvdb_id'] ?? null,
                    'imdb' => $item->metadata['imdb_id'] ?? null,
                    'tmdb' => $item->metadata['tmdb_id'] ?? null,
                ],
                'season' => $item->metadata['season_number'] ?? 1,
                'number' => $item->metadata['episode_number'] ?? 1,
            ];
        }

        $payload = [
            'action' => $action,
            'progress' => $progress,
            'movie' => $movie,
            'episode' => $episode,
        ];

        try {
            $response = $this->http->post(
                self::BASE_URL . '/scrobble/' . ($movie !== null ? 'movie' : 'episode'),
                $payload,
                ['Authorization' => 'Bearer ' . $accessToken]
            );

            $this->logger->debug('Trakt scrobble response', [
                'action' => $action,
                'item' => $item->name,
                'response' => $response,
            ]);

            return [
                'action' => is_string($response['action'] ?? null) ? $response['action'] : $action,
                'watched_at' => is_string($response['watched_at'] ?? null) ? $response['watched_at'] : date('c'),
            ];
        } catch (TraktAuthenticationException $e) {
            throw $e;
        } catch (TraktApiException $e) {
            throw $e;
        }
    }

    /**
     * Get watched history for a user (for Trakt → Phlix sync).
     *
     * @param string $username Trakt username
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page (default 100, max 1000)
     * @param string $accessToken OAuth access token
     *
     * @return array<mixed> Watched history items
     *
     * @throws TraktApiException|TraktAuthenticationException On API error
     * @since 0.14.0
     */
    public function getWatchedHistory(string $username, int $page = 1, int $limit = 100, string $accessToken = ''): array
    {
        $headers = [];
        if ($accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        $params = [
            'page' => $page,
            'limit' => min($limit, 1000),
        ];

        $response = $this->http->get(
            self::BASE_URL . '/users/' . urlencode($username) . '/watched',
            $params,
            $headers
        );

        $this->logger->debug('Trakt watched history response', [
            'username' => $username,
            'page' => $page,
            'count' => count($response),
        ]);

        return $response;
    }

    /**
     * Add a media item to Trakt watched history (for Phlix → Trakt sync).
     *
     * @param MediaItem $item Media item that was watched
     * @param \DateTimeImmutable $watchedAt When the item was watched
     * @param string $accessToken OAuth access token
     *
     * @return array<string, mixed> API response
     *
     * @throws TraktApiException|TraktAuthenticationException On API error
     * @since 0.14.0
     */
    public function addToHistory(MediaItem $item, \DateTimeImmutable $watchedAt, string $accessToken): array
    {
        $movie = null;
        $episode = null;

        if ($item->type === 'movie') {
            $movie = [
                'ids' => [
                    'trakt' => $item->metadata['trakt_id'] ?? null,
                    'slug' => $item->metadata['slug'] ?? null,
                    'imdb' => $item->metadata['imdb_id'] ?? null,
                    'tmdb' => $item->metadata['tmdb_id'] ?? null,
                ],
            ];
        } elseif ($item->type === 'episode') {
            $episode = [
                'ids' => [
                    'trakt' => $item->metadata['trakt_id'] ?? null,
                    'tvdb' => $item->metadata['tvdb_id'] ?? null,
                    'imdb' => $item->metadata['imdb_id'] ?? null,
                    'tmdb' => $item->metadata['tmdb_id'] ?? null,
                ],
                'season' => $item->metadata['season_number'] ?? 1,
                'number' => $item->metadata['episode_number'] ?? 1,
            ];
        }

        $payload = [
            'watched_at' => $watchedAt->format('Y-m-d\TH:i:s.vP'),
            'movie' => $movie,
            'episode' => $episode,
        ];

        $response = $this->http->post(
            self::BASE_URL . '/sync/history',
            $payload,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        $this->logger->debug('Trakt add to history response', [
            'item' => $item->name,
            'watched_at' => $watchedAt->format('c'),
            'response' => $response,
        ]);

        /** @var array<string, mixed> */
        return $response;
    }

    /**
     * Get the configured redirect URI.
     *
     * @return string
     */
    private function getRedirectUri(): string
    {
        $config = $this->loadConfig();

        return is_string($config['redirect_uri'] ?? null) ? $config['redirect_uri'] : 'https://localhost/api/v1/oauth/trakt/callback';
    }

    /**
     * Load Trakt configuration.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configFile = dirname(__DIR__, 5) . '/config/scrobblers/trakt.php';

        if (is_file($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;

            return $config;
        }

        return [];
    }

    /**
     * Base64url encode without padding.
     *
     * @param string $data Raw bytes to encode
     *
     * @return string Base64url encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
