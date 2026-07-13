<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

    // Retry constants for rate-limit backoff (SV-3.5 pattern)
    private const RETRY_MAX_ATTEMPTS = 5;
    private const RETRY_BASE_DELAY_MS = 1_000;
    private const RETRY_MAX_DELAY_MS = 32_000;

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
     * Refresh access token after an auth failure (401).
     *
     * Uses a single-flight mutex so that concurrent 401 responses all await
     * the same refresh POST rather than each POSTing their own. This prevents
     * Trakt's rotating refresh tokens from invalidating each other.
     *
     * Uses a two-level guard:
     * 1. Static $inFlightRefresh for same-process concurrency (Workerman coroutines)
     * 2. flock() for cross-process safety (multiple Workerman workers)
     *
     * @param string $refreshToken Current refresh token
     *
     * @return array<string, mixed> Token response with access_token, refresh_token, expires_in
     *
     * @throws TraktApiException When refresh fails
     * @since 0.14.0
     */
    public function refreshAfterAuthFailure(string $refreshToken): array
    {
        static $inFlightRefresh = [];
        static $lockFile = null;

        if ($lockFile === null) {
            $lockFile = sys_get_temp_dir() . '/phlix_trakt_refresh.lock';
        }

        // Key cache by token hash to avoid cross-token cache pollution
        $tokenKey = md5($refreshToken);

        // Phase 1: Same-process single-flight (Workerman coroutines on same worker)
        // Use \Co\sleep to yield to the event loop instead of blocking with usleep()
        while (isset($inFlightRefresh[$tokenKey]) && $inFlightRefresh[$tokenKey] === 'pending') {
            if (function_exists('\Co\sleep')) {
                \Co\sleep(0.005); // 5ms - yields to event loop in async context
            } else {
                usleep(5000); // Fallback for non-Swoole (unit tests)
            }
        }

        if (isset($inFlightRefresh[$tokenKey]) && is_array($inFlightRefresh[$tokenKey])) {
            /** @var array<string, mixed> $result */
            $result = $inFlightRefresh[$tokenKey];

            return $result;
        }

        $inFlightRefresh[$tokenKey] = 'pending';

        // Phase 2: Cross-process mutex via flock()
        $fp = fopen($lockFile, 'c+');
        if (!$fp) {
            unset($inFlightRefresh[$tokenKey]);
            throw new TraktApiException('Could not open refresh lock file');
        }

        // Acquire the lock with a NON-BLOCKING flock (LOCK_NB) in a polling retry
        // loop, yielding to the event loop between attempts via the same \Co\sleep
        // (with usleep fallback) idiom as Phase 1 above. A bare blocking
        // flock($fp, LOCK_EX) would stall the entire Workerman/Swoole worker event
        // loop while contended, freezing every other concurrent connection on that
        // worker — defeating the point of the async refactor.
        $maxWaitAttempts = 400; // ~2s ceiling at 5ms per attempt
        $attempts = 0;
        $acquired = flock($fp, LOCK_EX | LOCK_NB);
        while (!$acquired && $attempts < $maxWaitAttempts) {
            if (function_exists('\Co\sleep')) {
                \Co\sleep(0.005); // 5ms - yields to event loop in async context
            } else {
                usleep(5000); // Fallback for non-Swoole (unit tests)
            }
            ++$attempts;
            $acquired = flock($fp, LOCK_EX | LOCK_NB);
        }

        if (!$acquired) {
            fclose($fp);
            unset($inFlightRefresh[$tokenKey]);
            throw new TraktApiException('Could not acquire refresh lock');
        }

        try {
            $result = $this->refreshAccessToken($refreshToken);
            $inFlightRefresh[$tokenKey] = $result;
            flock($fp, LOCK_UN);
            fclose($fp);

            return $result;
        } catch (\Throwable $e) {
            unset($inFlightRefresh[$tokenKey]);
            flock($fp, LOCK_UN);
            fclose($fp);

            throw $e;
        }
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
     * Implements retry with jittered exponential backoff for rate-limited
     * (429) and server (5xx) responses, following the SV-3.5 pattern.
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

        $url = self::BASE_URL . '/users/' . urlencode($username) . '/watched';

        // Retry loop with jittered exponential backoff for rate-limit/server errors (SV-3.5 pattern)
        $lastException = null;
        for ($attempt = 0; $attempt <= self::RETRY_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http->get($url, $params, $headers);

                $this->logger->debug('Trakt watched history response', [
                    'username' => $username,
                    'page' => $page,
                    'count' => count($response),
                ]);

                return $response;
            } catch (TraktRateLimitException $e) {
                $lastException = $e;
                if ($attempt < self::RETRY_MAX_ATTEMPTS) {
                    $delayMs = $this->computeBackoffDelayMs($attempt);
                    $this->logger->info('Trakt rate-limited, backing off before retry', [
                        'username' => $username,
                        'attempt' => $attempt + 1,
                        'delay_ms' => $delayMs,
                        'error' => $e->getMessage(),
                    ]);
                    // Coroutine-friendly sleep: \Co\sleep yields to the event
                    // loop so the resident worker keeps serving other
                    // connections during the backoff; usleep is the fallback for
                    // non-Swoole contexts (unit tests / plain CLI). Mirrors the
                    // idiom in refreshAfterAuthFailure() above.
                    $delaySeconds = $delayMs / 1_000;
                    if (function_exists('\Co\sleep')) {
                        \Co\sleep($delaySeconds);
                    } else {
                        usleep((int) ($delayMs * 1_000));
                    }
                    continue;
                }
                throw $e;
            } catch (TraktApiException $e) {
                // Non-rate-limit API exception: re-throw immediately
                throw $e;
            }
        }

        // Should not reach here, but if it does, throw the last exception
        if ($lastException !== null) {
            throw $lastException;
        }

        return [];
    }

    /**
     * Get in-progress playback items for the authenticated user
     * (for Trakt → Phlix resume-position sync).
     *
     * Calls Trakt's `GET /sync/playback[/{type}]` endpoint, which returns the
     * user's currently in-progress items — each carrying a `progress` float
     * (0-100), a `paused_at` ISO-8601 timestamp, and the movie/episode
     * identifiers (trakt/tmdb/tvdb/imdb) under the same `movie`/`episode`.`ids`
     * shape the watched-history endpoint uses. Unlike watched history this is
     * scoped to the OAuth user (no username path segment) and is not paginated,
     * so a single request returns the full in-progress set.
     *
     * Implements the same retry-with-jittered-backoff loop as
     * {@see self::getWatchedHistory()} for 429/5xx responses (SV-3.5 pattern).
     *
     * @param string $accessToken OAuth access token
     * @param string|null $type Optional type filter: 'movies' or 'episodes'
     *   (null = both). Any other value is ignored and all types are fetched.
     * @param int $limit Items to request (Trakt caps this server-side)
     *
     * @return array<mixed> In-progress playback items
     *
     * @throws TraktApiException|TraktAuthenticationException On API error
     * @since 1.2.3 (SV-3.6c)
     */
    public function getPlaybackProgress(string $accessToken = '', ?string $type = null, int $limit = 100): array
    {
        $headers = [];
        if ($accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        $params = [
            'limit' => min($limit, 1000),
        ];

        $url = self::BASE_URL . '/sync/playback';
        if ($type === 'movies' || $type === 'episodes') {
            $url .= '/' . $type;
        }

        // Retry loop with jittered exponential backoff for rate-limit/server errors (SV-3.5 pattern)
        $lastException = null;
        for ($attempt = 0; $attempt <= self::RETRY_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http->get($url, $params, $headers);

                $this->logger->debug('Trakt playback progress response', [
                    'type' => $type ?? 'all',
                    'count' => count($response),
                ]);

                return $response;
            } catch (TraktRateLimitException $e) {
                $lastException = $e;
                if ($attempt < self::RETRY_MAX_ATTEMPTS) {
                    $delayMs = $this->computeBackoffDelayMs($attempt);
                    $this->logger->info('Trakt rate-limited on playback, backing off before retry', [
                        'attempt' => $attempt + 1,
                        'delay_ms' => $delayMs,
                        'error' => $e->getMessage(),
                    ]);
                    // Coroutine-friendly sleep mirroring getWatchedHistory(): \Co\sleep
                    // yields to the event loop so the resident worker keeps serving other
                    // connections during the backoff; usleep is the non-Swoole fallback.
                    $delaySeconds = $delayMs / 1_000;
                    if (function_exists('\Co\sleep')) {
                        \Co\sleep($delaySeconds);
                    } else {
                        usleep((int) ($delayMs * 1_000));
                    }
                    continue;
                }
                throw $e;
            } catch (TraktApiException $e) {
                // Non-rate-limit API exception: re-throw immediately
                throw $e;
            }
        }

        // Should not reach here, but if it does, throw the last exception
        if ($lastException !== null) {
            throw $lastException;
        }

        return [];
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
     * Compute jittered exponential backoff delay for retry attempt $attempt.
     *
     * Delay = min(RETRY_BASE_DELAY_MS * 2^attempt, RETRY_MAX_DELAY_MS) + jitter.
     * Jitter is a random value in [0, delay] to prevent thundering-herd when
     * multiple clients recover from a shared outage simultaneously.
     *
     * @param int $attempt Zero-based attempt index (0 = first retry).
     *
     * @return float Delay in milliseconds (float for usleep compatibility).
     */
    private function computeBackoffDelayMs(int $attempt): float
    {
        $delay = min(self::RETRY_BASE_DELAY_MS * (2 ** $attempt), self::RETRY_MAX_DELAY_MS);
        // Uniform jitter in [0, delay].
        $jitter = mt_rand(0, (int) $delay);
        return (float) ($delay + $jitter);
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
