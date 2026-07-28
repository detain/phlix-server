<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Metadata\MetadataFailureKind;
use Phlix\Media\Metadata\MetadataHttpClient;
use Phlix\Media\Metadata\ProviderHealthTracker;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Response;

/**
 * Exercises the real {@see MetadataHttpClient::getResult()} status handling.
 *
 * Only the transport is replaced, so the status classification, caching, and
 * health-tracking wiring under test is the production code path — this is the
 * exact layer where an HTTP 401 body was being returned as if it were data.
 */
class MetadataHttpClientStatusTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * Build a client whose transport returns a canned status + body.
     *
     * @param int    $status HTTP status to return.
     * @param string $body   Raw response body.
     */
    private function clientReturning(
        int $status,
        string $body,
        ?ProviderHealthTracker $health = null
    ): MetadataHttpClient {
        return new class ('https://api.themoviedb.org/3', 'k', 10, $health, $status, $body) extends MetadataHttpClient {
            public function __construct(
                string $baseUrl,
                string $apiKey,
                int $timeout,
                ?ProviderHealthTracker $health,
                private readonly int $cannedStatus,
                private readonly string $cannedBody,
            ) {
                parent::__construct($baseUrl, $apiKey, $timeout, $health);
            }

            protected function requestCurl(string $url, array $headers): ?ResponseInterface
            {
                return new Response($this->cannedStatus, [], $this->cannedBody);
            }
        };
    }

    public function testInvalidApiKeyBodyIsNotReturnedAsData(): void
    {
        // The exact prod payload. Before the fix, get() returned this array —
        // the provider then looked for a 'results' key, did not find one, and
        // logged a DEBUG "search miss".
        $client = $this->clientReturning(401, json_encode([
            'success' => false,
            'status_code' => 7,
            'status_message' => 'Invalid API key: You must be granted a valid key.',
        ]));

        $this->assertNull(
            $client->get('/search/movie', ['query' => 'The Matrix']),
            'an HTTP 401 error body must never be handed back as a response'
        );
    }

    public function testInvalidApiKeyIsClassifiedAsAuthWithTheProviderStatusCode(): void
    {
        $client = $this->clientReturning(401, json_encode([
            'success' => false,
            'status_code' => 7,
            'status_message' => 'Invalid API key: You must be granted a valid key.',
        ]));

        $result = $client->getResult('/search/movie', ['query' => 'The Matrix']);

        $this->assertTrue($result->isAuthFailure());
        $this->assertSame(MetadataFailureKind::Auth, $result->kind);
        $this->assertSame(401, $result->httpStatus);
        $this->assertSame(7, $result->providerStatusCode);
        $this->assertNull($result->body());
    }

    public function testSuccessfulEmptySearchReturnsTheBody(): void
    {
        $client = $this->clientReturning(200, json_encode([
            'page' => 1,
            'results' => [],
            'total_results' => 0,
        ]));

        $body = $client->get('/search/movie', ['query' => 'Nothing']);

        $this->assertIsArray($body);
        $this->assertSame([], $body['results']);
    }

    public function testAuthFailureFeedsTheHealthTracker(): void
    {
        $health = new ProviderHealthTracker();
        $client = $this->clientReturning(401, json_encode(['status_code' => 7]), $health);

        $client->getResult('/search/movie', ['query' => 'a']);

        $snapshot = $health->snapshot();
        $this->assertSame(1, $snapshot['tmdb']['failures']);
        $this->assertFalse($snapshot['tmdb']['ever_succeeded']);
        $this->assertFalse($health->isHealthy('tmdb'));
    }

    public function testSuccessFeedsTheHealthTracker(): void
    {
        $health = new ProviderHealthTracker();
        $client = $this->clientReturning(200, json_encode(['results' => []]), $health);

        $client->getResult('/search/movie', ['query' => 'a']);

        $snapshot = $health->snapshot();
        $this->assertSame(1, $snapshot['tmdb']['successes']);
        $this->assertTrue($snapshot['tmdb']['ever_succeeded']);
        $this->assertTrue($health->isHealthy('tmdb'));
    }

    public function testAuthFailureIsNotCached(): void
    {
        $health = new ProviderHealthTracker();
        $client = $this->clientReturning(401, json_encode(['status_code' => 7]), $health);

        $client->getResult('/search/movie', ['query' => 'a']);
        $client->getResult('/search/movie', ['query' => 'a']);

        // Two real attempts, not one attempt plus a cache hit — a rejected key
        // must not be remembered as if it were a valid answer.
        $this->assertSame(2, $health->snapshot()['tmdb']['failures']);
    }

    public function testProviderLabelIsDerivedFromTheBaseUrl(): void
    {
        $this->assertSame('tmdb', (new MetadataHttpClient('https://api.themoviedb.org/3', 'k'))->providerLabel());
        $this->assertSame('tvdb', (new MetadataHttpClient('https://api.thetvdb.com', 'k'))->providerLabel());
        $this->assertSame('fanart', (new MetadataHttpClient('https://webservice.fanart.tv/v3', 'k'))->providerLabel());
        // Unknown hosts still get their own bucket rather than a shared one.
        $this->assertSame('example.invalid', (new MetadataHttpClient('https://example.invalid/v1', 'k'))->providerLabel());
    }

    public function testNotFoundIsBenignAndDoesNotCountAgainstHealth(): void
    {
        $health = new ProviderHealthTracker();
        $client = $this->clientReturning(404, json_encode(['status_code' => 34]), $health);

        $result = $client->getResult('/movie/99999999');

        $this->assertSame(MetadataFailureKind::NotFound, $result->kind);
        $this->assertNull($result->body());
        // A scan over titles that aren't in TMDB must not look like an outage.
        $this->assertSame([], $health->snapshot());
        $this->assertTrue($health->isHealthy('tmdb'));
    }

    public function testUnparseableBodyIsAFailureNotAnEmptyResult(): void
    {
        $client = $this->clientReturning(200, '<html>gateway error</html>');

        $result = $client->getResult('/search/movie');

        $this->assertSame(MetadataFailureKind::InvalidBody, $result->kind);
        $this->assertNull($result->body());
    }
}
