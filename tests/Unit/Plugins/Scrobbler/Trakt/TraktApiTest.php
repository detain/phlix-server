<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
use Phlix\Plugins\Scrobbler\Trakt\TraktRateLimitException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TraktApiTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const CLIENT_SECRET = 'test-client-secret';

    public function testGetAuthUrlContainsExpectedParams(): void
    {
        $http = new MockHttpClient();
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $state = 'test-state-12345';
        $codeVerifier = 'test-code-verifier';

        $authUrl = $api->getAuthUrl($state, $codeVerifier);

        $this->assertStringContainsString('client_id=test-client-id', $authUrl);
        $this->assertStringContainsString('response_type=code', $authUrl);
        $this->assertStringContainsString('state=test-state-12345', $authUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $authUrl);
        $this->assertStringContainsString('https://api.trakt.tv/oauth/authorize?', $authUrl);
    }

    public function testExchangeCodeReturnsTokens(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->exchangeCode('auth-code', 'code-verifier');

        $this->assertSame('new-access', $result['access_token']);
        $this->assertSame('new-refresh', $result['refresh_token']);
        $this->assertSame(3600, $result['expires_in']);
    }

    public function testRefreshAccessTokenReturnsNewTokens(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'refreshed-access', 'refresh_token' => 'refreshed-refresh', 'expires_in' => 7200]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->refreshAccessToken('old-refresh-token');

        $this->assertSame('refreshed-access', $result['access_token']);
        $this->assertSame('refreshed-refresh', $result['refresh_token']);
        $this->assertSame(7200, $result['expires_in']);
    }

    public function testGetWatchedHistoryReturnsItemsAndPageCount(): void
    {
        $history = [['id' => 1, 'title' => 'Test Movie']];
        // SV-3.6d: getWatchedHistory now returns {items, pageCount}, surfacing
        // Trakt's X-Pagination-Page-Count header so the caller can paginate.
        $http = new MockHttpClient([$history], [['x-pagination-page-count' => '3']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame($history, $result['items']);
        $this->assertSame(3, $result['pageCount']);
    }

    public function testGetWatchedHistoryPageCountDefaultsToZeroWhenHeaderAbsent(): void
    {
        // No pagination header supplied → pageCount 0 (unknown); the caller then
        // falls back to loop-until-short-page.
        $http = new MockHttpClient([[['id' => 1]]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame(0, $result['pageCount']);
    }

    public function testExchangeCodeThrowsOnError(): void
    {
        $http = new MockHttpClient([['error' => 'invalid_grant', 'error_description' => 'Code expired']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $api->exchangeCode('auth-code', 'code-verifier');
    }

    public function testRefreshTokenThrowsOnError(): void
    {
        $http = new MockHttpClient([['error' => 'invalid_grant']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $api->refreshAccessToken('old-refresh-token');
    }

    public function testGetWatchedHistoryUsesCorrectEndpoint(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame('GET', $http->lastMethod);
        $this->assertStringContainsString('/users/testuser/watched', $http->lastUrl);
    }

    /**
     * Test that concurrent calls to refreshAfterAuthFailure result in only ONE POST.
     *
     * This simulates the scenario where multiple scrobble calls each receive a 401
     * and all call refreshAfterAuthFailure(). Without single-flight gating, each
     * would POST to /oauth/token. With the mutex, only the first call performs
     * the POST; subsequent calls await and reuse the same result.
     *
     * Note: In a real async context (Workerman), concurrent coroutines all see
     * the same static $inFlightRefresh and the first one wins. The POST count
     * verification proves the single-flight is working.
     */
    public function testRefreshAfterAuthFailureIsSingleFlighted(): void
    {
        $http = new MockHttpClient([
            ['access_token' => 'refreshed-access', 'refresh_token' => 'refreshed-refresh', 'expires_in' => 7200]
        ]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        // Simulate 3 concurrent 401 failures all calling refreshAfterAuthFailure.
        // In a real async context, these would be truly concurrent. In this sync
        // test, they run sequentially but the static mutex prevents duplicate POSTs.
        $api->refreshAfterAuthFailure('old-refresh-token');
        $api->refreshAfterAuthFailure('old-refresh-token');
        $api->refreshAfterAuthFailure('old-refresh-token');

        // Only ONE POST should have been made to /oauth/token
        // (the single-flight mutex ensures subsequent calls use cached result)
        $this->assertSame(1, $http->postCallCount);
        $this->assertStringContainsString('/oauth/token', $http->lastUrl);
    }

    /**
     * Test that refreshAfterAuthFailure properly propagates errors.
     */
    public function testRefreshAfterAuthFailureThrowsOnError(): void
    {
        $http = new MockHttpClient([['error' => 'invalid_grant', 'error_description' => 'Refresh token expired']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $api->refreshAfterAuthFailure('expired-refresh-token');
    }

    /**
     * Test that the cross-process lock acquire is non-blocking (LOCK_EX | LOCK_NB)
     * rather than a bare blocking flock(). If another process/holder has the
     * lock file exclusively locked and never releases it, refreshAfterAuthFailure()
     * must eventually give up (polling with a yield, not blocking the event loop
     * forever) and throw TraktApiException rather than hang indefinitely.
     *
     * This directly guards against a regression back to a blocking
     * flock($fp, LOCK_EX), which would stall the whole Workerman/Swoole worker
     * event loop while the lock is contended.
     */
    public function testRefreshAfterAuthFailureThrowsWhenLockIsHeldByAnotherProcess(): void
    {
        $lockFile = sys_get_temp_dir() . '/phlix_trakt_refresh.lock';

        // Simulate another worker process holding the cross-process mutex by
        // acquiring an exclusive lock on the SAME lock file path used internally
        // by TraktApi::refreshAfterAuthFailure(), and never releasing it during
        // this test. If the implementation uses a bare blocking flock(LOCK_EX),
        // this test would hang forever; with LOCK_NB + bounded polling it must
        // instead throw within a bounded time.
        $externalHolder = fopen($lockFile, 'c+');
        $this->assertNotFalse($externalHolder, 'Could not open lock file for test setup');
        $this->assertTrue(flock($externalHolder, LOCK_EX), 'Could not acquire external test lock');

        try {
            $http = new MockHttpClient([
                ['access_token' => 'irrelevant', 'refresh_token' => 'irrelevant', 'expires_in' => 3600],
            ]);
            $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

            $this->expectException(TraktApiException::class);
            $this->expectExceptionMessage('Could not acquire refresh lock');

            // Use a refresh token unique to this test so the static same-process
            // single-flight cache (keyed by md5(refreshToken)) can't short-circuit
            // and return a cached result from another test in the same process.
            $api->refreshAfterAuthFailure('lock-contention-test-token-' . __METHOD__);
        } finally {
            flock($externalHolder, LOCK_UN);
            fclose($externalHolder);
        }
    }

    /**
     * SV-3.6e regression guard for the HIGH cumulative-review finding #1.
     *
     * Trakt's API v2 REJECTS (403) any data-API request that does not carry BOTH
     * `trakt-api-key: <clientId>` and `trakt-api-version: 2`. The pull sync's
     * getWatchedHistory() call MUST send them (plus an `Authorization: Bearer`
     * when a token is set). This asserts the OUTGOING request headers captured by
     * the mock client.
     *
     * Mutation reasoning: reverting the fix — i.e. dropping the `trakt-api-key`
     * and/or `trakt-api-version` pair from TraktApi::apiHeaders() (or bypassing
     * the shared builder at this call site, as the code did before the fix) —
     * removes those keys from $http->lastHeaders, so these assertSame() lines go
     * red. This is the test the review said would have caught the AC-blocking bug.
     */
    public function testGetWatchedHistorySendsMandatoryApiHeaders(): void
    {
        $http = new MockHttpClient([[['id' => 1]]], [['x-pagination-page-count' => '1']]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame('test-client-id', $http->lastHeaders['trakt-api-key'] ?? null);
        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertSame('Bearer access-token', $http->lastHeaders['Authorization'] ?? null);
    }

    /**
     * SV-3.6e regression guard for finding #1 on the resume-position endpoint.
     *
     * getPlaybackProgress() routes through the same shared apiHeaders() builder
     * (via HttpClient::get()), so it too must carry both mandatory headers + the
     * Bearer token. Same mutation reasoning as the watched-history case above.
     */
    public function testGetPlaybackProgressSendsMandatoryApiHeaders(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getPlaybackProgress('access-token');

        $this->assertSame('GET', $http->lastMethod);
        $this->assertStringContainsString('/sync/playback', $http->lastUrl);
        $this->assertSame('test-client-id', $http->lastHeaders['trakt-api-key'] ?? null);
        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertSame('Bearer access-token', $http->lastHeaders['Authorization'] ?? null);
    }

    /**
     * The mandatory api-key/version pair is sent even without an OAuth token, and
     * no empty `Authorization: Bearer ` header is emitted for an empty token.
     */
    public function testApiHeadersOmitAuthorizationWhenTokenEmpty(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getPlaybackProgress('');

        $this->assertSame('test-client-id', $http->lastHeaders['trakt-api-key'] ?? null);
        $this->assertSame('2', $http->lastHeaders['trakt-api-version'] ?? null);
        $this->assertArrayNotHasKey('Authorization', $http->lastHeaders);
    }

    /**
     * Defense-in-depth: apiHeaders() throws (never sends an empty `trakt-api-key`)
     * when the client id is not configured. Unreachable in production, but the
     * guard must fail fast rather than send a request Trakt would 403.
     */
    public function testApiCallThrowsWhenClientIdNotConfigured(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, '', self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('client_id not configured');

        $api->getPlaybackProgress('access-token');
    }

    /**
     * SV-3.6e rate-limit backoff (requirement #9) for watched history.
     *
     * A 429 (TraktRateLimitException) on the first attempt must be caught by the
     * SV-3.5 retry loop, backed off (coroutine-safe \Co\sleep in production,
     * usleep fallback here), and retried — eventually succeeding. Proves the
     * retry actually happens: the second queued response (the real page) is what
     * is returned.
     */
    public function testGetWatchedHistoryRetriesAfterRateLimitThenSucceeds(): void
    {
        $items = [['id' => 42, 'title' => 'Rate Limited Then OK']];
        $http = new MockHttpClient(
            [new TraktRateLimitException('Rate limit exceeded', 1), $items],
            [[], ['x-pagination-page-count' => '1']]
        );
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame($items, $result['items']);
        $this->assertSame(1, $result['pageCount']);
    }

    /**
     * SV-3.6e rate-limit backoff (requirement #9) for the resume-position feed.
     *
     * Same retry/backoff contract as getWatchedHistory: a first-attempt 429 is
     * retried and the subsequent success is returned.
     */
    public function testGetPlaybackProgressRetriesAfterRateLimitThenSucceeds(): void
    {
        $playback = [['progress' => 50.0, 'movie' => ['ids' => ['tmdb' => 7]]]];
        $http = new MockHttpClient([new TraktRateLimitException('Rate limit exceeded', 1), $playback]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->getPlaybackProgress('access-token');

        $this->assertSame($playback, $result);
    }

    /**
     * A non-rate-limit API error is re-thrown immediately (not retried) — the
     * retry loop only backs off for 429s. Guards the {@see TraktApiException}
     * catch arm that bypasses backoff.
     */
    public function testGetWatchedHistoryDoesNotRetryNonRateLimitError(): void
    {
        $http = new MockHttpClient([new TraktApiException('Server error', 500)]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('Server error');

        $api->getWatchedHistory('testuser', 1, 100, 'access-token');
    }

    /**
     * A `type` filter narrows the playback endpoint to `/sync/playback/{type}`.
     */
    public function testGetPlaybackProgressWithTypeFilterHitsTypedEndpoint(): void
    {
        $http = new MockHttpClient([[]]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $api->getPlaybackProgress('access-token', 'movies');

        $this->assertStringContainsString('/sync/playback/movies', $http->lastUrl);
    }

    /**
     * The playback feed also re-throws a non-rate-limit API error immediately
     * (no backoff) — mirrors the getWatchedHistory contract.
     */
    public function testGetPlaybackProgressDoesNotRetryNonRateLimitError(): void
    {
        $http = new MockHttpClient([new TraktApiException('Server error', 500)]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $this->expectException(TraktApiException::class);
        $this->expectExceptionMessage('Server error');

        $api->getPlaybackProgress('access-token');
    }
}
