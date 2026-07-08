<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClient;
use Phlix\Plugins\Scrobbler\Trakt\HttpClientInterface;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;
use Phlix\Plugins\Scrobbler\Trakt\TraktApiException;
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

    public function testGetWatchedHistoryReturnsArray(): void
    {
        $history = [['id' => 1, 'title' => 'Test Movie']];
        $http = new MockHttpClient([$history]);
        $api = new TraktApi($http, self::CLIENT_ID, self::CLIENT_SECRET, new NullLogger());

        $result = $api->getWatchedHistory('testuser', 1, 100, 'access-token');

        $this->assertSame($history, $result);
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
}

final class MockHttpClient implements HttpClientInterface
{
    public string $lastMethod = '';
    public string $lastUrl = '';
    /** @var array<string, mixed> */
    public array $lastData = [];
    /** @var array<string, mixed> */
    public array $lastHeaders = [];
    public int $postCallCount = 0;

    /** @var array<int, array<array-key, mixed>> */
    private array $responses;
    private int $responseIndex = 0;

    /**
     * @param array<int, array<array-key, mixed>> $responses Queue of responses to return
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        if (!empty($params)) {
            $this->lastUrl .= '?' . http_build_query($params);
        }

        return $this->getNextResponse();
    }

    public function post(string $url, array $data = [], array $headers = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastUrl = $url;
        $this->lastData = $data;
        $this->lastHeaders = $headers;
        ++$this->postCallCount;

        return $this->getNextResponse();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getNextResponse(): array
    {
        if ($this->responseIndex >= count($this->responses)) {
            return [];
        }

        return $this->responses[$this->responseIndex++];
    }
}
