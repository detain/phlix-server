<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Integrations\Trakt;

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
 * @package Phlix\Server\Integrations\Trakt
 * @since 0.14.0
 */
class TraktApi
{
    private const BASE_URL = 'https://api.trakt.tv';

    // Retry constants for rate-limit backoff (SV-3.5 pattern)
    private const RETRY_MAX_ATTEMPTS = 5;
    private const RETRY_BASE_DELAY_MS = 1_000;
    private const RETRY_MAX_DELAY_MS = 32_000;

    // Bounded single-flight refresh registry (M-SERVER-37). Shape follows
    // AuthManager::$rateLimitStore: TTL-on-read expiry, hard cap,
    // sweep-then-evict-oldest before insert. Eviction is age-ordered across
    // entry kinds: exceeding the cap means 256 distinct rotated tokens held
    // flights within ~the grace window — in that pathological flood the oldest
    // entry (possibly a live pending marker) yields its slot and that token's
    // flight may POST twice; the cap protecting every resident worker beats
    // the flood-window dedupe, and flock() Phase 2 still serializes the
    // actual cross-process POSTs.
    private const REFRESH_RESULT_GRACE_SECONDS = 10;
    private const REFRESH_PENDING_STALE_SECONDS = 30;
    private const REFRESH_CACHE_MAX_ENTRIES = 256;
    private const REFRESH_WAIT_MAX_ATTEMPTS = 400; // ~2s ceiling at 5ms per attempt

    /**
     * Same-process single-flight registry for refreshAfterAuthFailure().
     *
     * Entries are structured so lifetimes can differ by kind: a `pending`
     * marker only guards concurrent flights and is taken over once stale; a
     * completed `result` is a plaintext credential and is only served — and
     * only kept — for the grace window. Trakt rotates the refresh token per
     * grant, so a completed key is never revisited; without pruning these
     * entries the resident-worker static would grow forever.
     *
     * @var array<string, array{pending: bool, at: int, result: array<string, mixed>|null}>
     */
    private static array $inFlightRefresh = [];

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
     * @param string|null $redirectUri Optional override for the redirect_uri.
     *     When provided the caller is responsible for ensuring it matches the
     *     URI registered with Trakt (e.g. the admin-settings override).
     *     When null the redirect_uri is read from the local config file.
     *
     * @return string Full authorization URL to redirect user to
     *
     * @since 0.14.0
     */
    public function getAuthUrl(string $state, string $codeVerifier, ?string $redirectUri = null): string
    {
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri ?? $this->getRedirectUri(),
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
     * @param string|null $redirectUri Optional override for the redirect_uri.
     *     When provided the caller is responsible for ensuring it matches the
     *     URI registered with Trakt (e.g. the admin-settings override).
     *     When null the redirect_uri is read from the local config file.
     *
     * @return array<string, mixed> Token response with access_token, refresh_token, expires_in
     *
     * @throws TraktApiException When token exchange fails
     * @since 0.14.0
     */
    public function exchangeCode(string $code, string $codeVerifier, ?string $redirectUri = null): array
    {
        $params = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri ?? $this->getRedirectUri(),
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
     * @param string|null $redirectUri Optional override for the redirect_uri.
     *     When provided the caller is responsible for ensuring it matches the
     *     URI registered with Trakt (e.g. the admin-settings override).
     *     When null the redirect_uri is read from the local config file.
     *
     * @return array<string, mixed> Token response with access_token, refresh_token, expires_in
     *
     * @throws TraktApiException When refresh fails
     * @since 0.14.0
     */
    public function refreshAccessToken(string $refreshToken, ?string $redirectUri = null): array
    {
        $params = [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri ?? $this->getRedirectUri(),
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
     * 1. Static {@see self::$inFlightRefresh} for same-process concurrency
     *    (Workerman coroutines on same worker), bounded: completed results
     *    live only for REFRESH_RESULT_GRACE_SECONDS, the whole store is capped
     *    at REFRESH_CACHE_MAX_ENTRIES entries (evict-oldest), waits are capped
     *    at REFRESH_WAIT_MAX_ATTEMPTS attempts, and a pending marker older
     *    than REFRESH_PENDING_STALE_SECONDS is taken over — a dead holder can
     *    neither leak plaintext credentials nor poison a key forever.
     * 2. flock() for cross-process safety (multiple Workerman workers)
     *
     * @param string $refreshToken Current refresh token
     * @param string|null $redirectUri Optional override for the redirect_uri.
     *     When provided the caller is responsible for ensuring it matches the
     *     URI registered with Trakt (e.g. the admin-settings override).
     *     When null the redirect_uri is read from the local config file.
     *
     * @return array<string, mixed> Token response with access_token, refresh_token, expires_in
     *
     * @throws TraktApiException When refresh fails, when the bounded wait on a
     *     concurrent flight lapses, or when the cross-process lock cannot be
     *     acquired
     * @since 0.14.0
     */
    public function refreshAfterAuthFailure(string $refreshToken, ?string $redirectUri = null): array
    {
        static $lockFile = null;

        if ($lockFile === null) {
            $lockFile = sys_get_temp_dir() . '/phlix_trakt_refresh.lock';
        }

        // Key cache by token hash to avoid cross-token cache pollution
        $tokenKey = md5($refreshToken);

        // Every call entry is a chance to shed lapsed entries, completed
        // results first among them: they are plaintext credentials for a
        // rotated (hence never-reused) key, and an idle resident worker must
        // clear them at the next touch rather than at an unspecified later
        // sweep. Prune-then-decide, mirroring the TTL-on-read of
        // AuthManager::$rateLimitStore.
        self::pruneRefreshRegistry(time());

        // Phase 1: Same-process single-flight (Workerman coroutines on same worker)
        // Use \Co\sleep to yield to the event loop instead of blocking with usleep().
        // The wait is bounded like the flock loop below; a marker still pending
        // past the stale ceiling belongs to a holder that died without cleanup
        // (e.g. coroutine cancellation skipped the catch below), so it is taken
        // over instead of spun behind forever.
        $waitAttempts = 0;
        while (isset(self::$inFlightRefresh[$tokenKey]) && self::$inFlightRefresh[$tokenKey]['pending']) {
            if (time() - self::$inFlightRefresh[$tokenKey]['at'] >= self::REFRESH_PENDING_STALE_SECONDS) {
                unset(self::$inFlightRefresh[$tokenKey]);
                break;
            }
            if (++$waitAttempts > self::REFRESH_WAIT_MAX_ATTEMPTS) {
                throw new TraktApiException('Timed out waiting for a concurrent Trakt token refresh');
            }
            if (function_exists('\Co\sleep')) {
                \Co\sleep(0.005); // 5ms - yields to event loop in async context
            } else {
                usleep(5000); // Fallback for non-Swoole (unit tests)
            }
        }

        if (isset(self::$inFlightRefresh[$tokenKey])) {
            $result = self::$inFlightRefresh[$tokenKey]['result'];
            if (is_array($result)) {
                return $result;
            }
        }

        self::boundRefreshRegistry(time());
        self::$inFlightRefresh[$tokenKey] = ['pending' => true, 'at' => time(), 'result' => null];

        // Phase 2: Cross-process mutex via flock()
        $fp = fopen($lockFile, 'c+');
        if (!$fp) {
            unset(self::$inFlightRefresh[$tokenKey]);
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
            unset(self::$inFlightRefresh[$tokenKey]);
            throw new TraktApiException('Could not acquire refresh lock');
        }

        try {
            $result = $this->refreshAccessToken($refreshToken, $redirectUri);
            // Replace our own pending marker in place: the key already holds a
            // slot, so no new bound check is needed for this write.
            self::$inFlightRefresh[$tokenKey] = ['pending' => false, 'at' => time(), 'result' => $result];
            flock($fp, LOCK_UN);
            fclose($fp);

            return $result;
        } catch (\Throwable $e) {
            unset(self::$inFlightRefresh[$tokenKey]);
            flock($fp, LOCK_UN);
            fclose($fp);

            throw $e;
        }
    }

    /**
     * Drop registry entries whose lifetime has lapsed.
     *
     * Completed results live for the grace window only (they are plaintext
     * OAuth credentials); pending markers survive until the stale ceiling,
     * past which their holder is presumed dead and the key is freed for
     * takeover. Called at every refresh entry so an idle worker's store is
     * swept at the next touch rather than growing untouched.
     */
    private static function pruneRefreshRegistry(int $now): void
    {
        foreach (self::$inFlightRefresh as $key => $entry) {
            $age = $now - $entry['at'];
            $lapses = $entry['pending']
                ? $age >= self::REFRESH_PENDING_STALE_SECONDS
                : $age >= self::REFRESH_RESULT_GRACE_SECONDS;
            if ($lapses) {
                unset(self::$inFlightRefresh[$key]);
            }
        }
    }

    /**
     * Enforce the hard cap before admitting a new entry.
     *
     * AuthManager::boundFallbackStore() shape: under the cap it returns
     * immediately; at the cap it first lets the expiry sweep free what it can,
     * then evicts the oldest entry until a slot is guaranteed.
     */
    private static function boundRefreshRegistry(int $now): void
    {
        if (count(self::$inFlightRefresh) < self::REFRESH_CACHE_MAX_ENTRIES) {
            return;
        }

        self::pruneRefreshRegistry($now);

        while (count(self::$inFlightRefresh) >= self::REFRESH_CACHE_MAX_ENTRIES) {
            $oldestKey = null;
            $oldestAt = null;
            foreach (self::$inFlightRefresh as $key => $entry) {
                if ($oldestAt === null || $entry['at'] < $oldestAt) {
                    $oldestAt = $entry['at'];
                    $oldestKey = $key;
                }
            }
            if ($oldestKey === null) {
                break; // empty store: headroom is already guaranteed
            }
            unset(self::$inFlightRefresh[$oldestKey]);
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
                $this->apiHeaders($accessToken)
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
     * Get one page of watched history for a user (for Trakt → Phlix sync).
     *
     * Returns the page's items together with the total page count Trakt reports
     * in the `X-Pagination-Page-Count` response header, so the caller can drive a
     * page loop across the full history (SV-3.6d). The header is surfaced via the
     * HTTP client's {@see HttpClientInterface::getWithHeaders()} sibling (plain
     * `get()` discards headers after decoding the body). When the header is
     * absent or unparseable, `pageCount` is `0` (unknown) and the caller falls
     * back to loop-until-short-page.
     *
     * Implements retry with jittered exponential backoff for rate-limited
     * (429) and server (5xx) responses, following the SV-3.5 pattern.
     *
     * @param string $username Trakt username
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page (default 100, max 1000)
     * @param string $accessToken OAuth access token
     *
     * @return array{items: array<mixed>, pageCount: int} This page's items plus
     *   the reported total page count (0 when the pagination header is absent).
     *
     * @throws TraktApiException|TraktAuthenticationException On API error
     * @since 0.14.0
     */
    public function getWatchedHistory(
        string $username,
        int $page = 1,
        int $limit = 100,
        string $accessToken = ''
    ): array {
        $headers = $this->apiHeaders($accessToken);

        $params = [
            'page' => $page,
            'limit' => min($limit, 1000),
        ];

        $url = self::BASE_URL . '/users/' . urlencode($username) . '/watched';

        // Retry loop with jittered exponential backoff for rate-limit/server errors (SV-3.5 pattern)
        $lastException = null;
        for ($attempt = 0; $attempt <= self::RETRY_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http->getWithHeaders($url, $params, $headers);
                $items = $response['body'];
                $pageCount = $this->extractPageCount($response['headers']);

                $this->logger->debug('Trakt watched history response', [
                    'username' => $username,
                    'page' => $page,
                    'count' => count($items),
                    'page_count' => $pageCount,
                ]);

                return ['items' => $items, 'pageCount' => $pageCount];
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

        // Safety net, not a branch: phpstan 2.3.1 proves this point is reached
        // only with a caught TraktRateLimitException in hand (every other path
        // returned or threw), so its own model calls the instanceof certain —
        // but psalm types the catch variable nullable and demands a guard
        // before the re-throw. The guard is the runtime-loud shape (a throw
        // can't silently mask a future control-flow edit); the ignore names
        // phpstan's certainty artifact and self-clears the moment the fold
        // stops firing (reportUnmatchedIgnoredErrors=true).
        if (!$lastException instanceof TraktRateLimitException) { // @phpstan-ignore instanceof.alwaysTrue
            throw new TraktApiException('Trakt history retry loop exited without a result');
        }

        throw $lastException;
    }

    /**
     * Parse the total page count from Trakt's pagination response headers.
     *
     * Trakt reports the total number of pages in the `X-Pagination-Page-Count`
     * header (companion to `X-Pagination-Item-Count`/`-Page`/`-Limit`). The
     * header map is lowercased by the HTTP client, so the lookup is on
     * `x-pagination-page-count`.
     *
     * @param array<string, string> $headers Lowercased response headers.
     *
     * @return int Reported page count, or 0 when the header is absent/unparseable
     *   (the caller then falls back to loop-until-short-page).
     */
    private function extractPageCount(array $headers): int
    {
        $raw = $headers['x-pagination-page-count'] ?? null;
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed !== '' && ctype_digit($trimmed)) {
                return (int) $trimmed;
            }
        }
        return 0;
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
        $headers = $this->apiHeaders($accessToken);

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

        // Safety net, not a branch (see getWatchedHistory): guard satisfies
        // psalm's nullable view; the inline ignore names phpstan 2.3.1's
        // certainty artifact and self-clears if the fold ever stops firing.
        if (!$lastException instanceof TraktRateLimitException) { // @phpstan-ignore instanceof.alwaysTrue
            throw new TraktApiException('Trakt playback progress retry loop exited without a result');
        }

        throw $lastException;
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
            $this->apiHeaders($accessToken)
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
     * Get the authenticated user's profile (for retrieving username after OAuth).
     *
     * @param string $accessToken OAuth access token
     *
     * @return array<string, mixed>|null User profile array or null on failure
     *
     * @since 0.14.0
     */
    public function getMe(string $accessToken): ?array
    {
        try {
            /** @var array<string, mixed> $response */
            $response = $this->http->get(
                self::BASE_URL . '/users/me',
                [],
                $this->apiHeaders($accessToken)
            );

            return [
                'username' => is_string($response['username'] ?? null) ? $response['username'] : '',
                'name' => is_string($response['name'] ?? null) ? $response['name'] : '',
                'joined_at' => is_string($response['joined_at'] ?? null) ? $response['joined_at'] : '',
            ];
        } catch (TraktApiException $e) {
            $this->logger->warning('Trakt getMe failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
     * Build the mandatory Trakt v2 API headers for a data-API request.
     *
     * Every Trakt data-API endpoint (`/users`, `/sync`, `/scrobble`, `/search`,
     * …) REQUIRES `trakt-api-key` (the OAuth application client id) and
     * `trakt-api-version: 2` on the request; without both, Trakt rejects the
     * call with 403. This is the single source of those two headers, applied by
     * every data-API call site in this class ({@see self::scrobble()},
     * {@see self::getWatchedHistory()}, {@see self::getPlaybackProgress()},
     * {@see self::addToHistory()}), so no call site can silently omit them.
     *
     * The OAuth token-exchange endpoints (`/oauth/token`) are deliberately NOT
     * routed through this builder: per Trakt's OAuth contract they authenticate
     * with `client_id`/`client_secret` in the request BODY and do not use the
     * api-key header, so {@see self::exchangeCode()} /
     * {@see self::refreshAccessToken()} post without it.
     *
     * @param string|null $bearer Optional OAuth access token; when non-empty an
     *     `Authorization: Bearer <token>` header is added.
     *
     * @return array<string, string> Header map for the HTTP client.
     *
     * @throws TraktApiException When no client id is configured (see below).
     */
    private function apiHeaders(?string $bearer = null): array
    {
        // Defense-in-depth: TraktPlugin::initApi() only constructs this client
        // once a non-empty client_id is configured (otherwise the plugin's api
        // stays null → isConfigured() is false → the sync skips before reaching
        // here), so an empty client id is unreachable in the resident server.
        // If it ever is empty we must NOT send an empty `trakt-api-key` (Trakt
        // would 403 the request anyway) — fail fast with a clear message so the
        // caller's TraktApiException catch logs it and the run skips cleanly.
        if ($this->clientId === '') {
            $this->logger->warning('Trakt client_id not configured; skipping API request');
            throw new TraktApiException('Trakt client_id not configured');
        }

        $headers = [
            'trakt-api-key' => $this->clientId,
            'trakt-api-version' => '2',
        ];

        if ($bearer !== null && $bearer !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }

        return $headers;
    }

    /**
     * Get the configured redirect URI.
     *
     * @return string
     */
    private function getRedirectUri(): string
    {
        $config = $this->loadConfig();

        return is_string($config['redirect_uri'] ?? null) ? $config['redirect_uri'] :
            'https://localhost/api/v1/oauth/trakt/callback';
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
