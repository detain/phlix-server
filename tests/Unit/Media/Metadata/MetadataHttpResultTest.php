<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\MetadataFailureKind;
use Phlix\Media\Metadata\MetadataHttpResult;

/**
 * Covers the classification that separates "no results" from "bad API key".
 *
 * The regression these guard: an HTTP 401 carrying TMDB `status_code` 7 used to
 * be handed back to providers as an ordinary response body, so it was logged as
 * a routine DEBUG "search miss" alongside genuine zero-result searches.
 */
class MetadataHttpResultTest extends TestCase
{
    public function testTwoHundredWithZeroResultsIsSuccess(): void
    {
        $result = MetadataHttpResult::classify(200, ['page' => 1, 'results' => [], 'total_results' => 0]);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertFalse($result->isAuthFailure());
        $this->assertSame(MetadataFailureKind::None, $result->kind);
        $this->assertSame([], $result->body()['results']);
    }

    public function testInvalidApiKeyIsAnAuthFailureNotAMiss(): void
    {
        // The exact prod payload: TMDB v3 with a 10-character key.
        $result = MetadataHttpResult::classify(401, [
            'success' => false,
            'status_code' => 7,
            'status_message' => 'Invalid API key: You must be granted a valid key.',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isAuthFailure());
        $this->assertTrue($result->countsAgainstHealth());
        $this->assertSame(MetadataFailureKind::Auth, $result->kind);
        $this->assertSame(7, $result->providerStatusCode);
        $this->assertSame(401, $result->httpStatus);
        $this->assertStringContainsString('Invalid API key', (string) $result->providerMessage);
    }

    public function testAuthFailureBodyIsNeverExposedAsData(): void
    {
        $result = MetadataHttpResult::classify(401, [
            'success' => false,
            'status_code' => 7,
            'status_message' => 'Invalid API key: You must be granted a valid key.',
        ]);

        // This is the whole point: an error body must not reach a caller that
        // is about to look for a 'results' key in it.
        $this->assertNull($result->body());
    }

    public function testNotFoundIsBenignAndDoesNotCountAgainstHealth(): void
    {
        $result = MetadataHttpResult::classify(404, [
            'success' => false,
            'status_code' => 34,
            'status_message' => 'The resource you requested could not be found.',
        ]);

        $this->assertSame(MetadataFailureKind::NotFound, $result->kind);
        $this->assertFalse($result->isFailure());
        $this->assertFalse($result->countsAgainstHealth());
        $this->assertFalse($result->isAuthFailure());
        $this->assertSame(34, $result->providerStatusCode);
        // Still no body — a 404 carries no data either.
        $this->assertNull($result->body());
    }

    /**
     * @return array<string, array{int, int, MetadataFailureKind}>
     */
    public static function providerAuthStatusCodes(): array
    {
        return [
            'authentication failed (3)' => [401, 3, MetadataFailureKind::Auth],
            'invalid api key (7)' => [401, 7, MetadataFailureKind::Auth],
            'suspended api key (10)' => [401, 10, MetadataFailureKind::Auth],
            'authentication failed (14)' => [401, 14, MetadataFailureKind::Auth],
            'device denied (16)' => [401, 16, MetadataFailureKind::Auth],
            'session denied (17)' => [401, 17, MetadataFailureKind::Auth],
        ];
    }

    /**
     * @dataProvider providerAuthStatusCodes
     */
    public function testProviderAuthStatusCodesAllClassifyAsAuth(
        int $httpStatus,
        int $providerCode,
        MetadataFailureKind $expected
    ): void {
        $result = MetadataHttpResult::classify($httpStatus, [
            'success' => false,
            'status_code' => $providerCode,
        ]);

        $this->assertSame($expected, $result->kind);
    }

    public function testProviderStatusCodeWinsOverHttpStatus(): void
    {
        // A 200 that nonetheless carries an auth status_code is still auth-broken.
        $result = MetadataHttpResult::classify(200, ['status_code' => 7, 'success' => false]);

        $this->assertSame(MetadataFailureKind::Auth, $result->kind);
    }

    public function testNumericStringStatusCodeIsRecognised(): void
    {
        $result = MetadataHttpResult::classify(401, ['status_code' => '7']);

        $this->assertSame(MetadataFailureKind::Auth, $result->kind);
        $this->assertSame(7, $result->providerStatusCode);
    }

    public function testForbiddenIsAuthFailure(): void
    {
        $this->assertSame(MetadataFailureKind::Auth, MetadataHttpResult::classify(403, [])->kind);
    }

    public function testRateLimitAndServerErrorsAreClassified(): void
    {
        $this->assertSame(MetadataFailureKind::RateLimited, MetadataHttpResult::classify(429, [])->kind);
        $this->assertSame(MetadataFailureKind::ServerError, MetadataHttpResult::classify(500, [])->kind);
        $this->assertSame(MetadataFailureKind::ServerError, MetadataHttpResult::classify(503, [])->kind);
        $this->assertSame(MetadataFailureKind::ClientError, MetadataHttpResult::classify(422, [])->kind);
    }

    public function testTwoHundredWithExplicitFailureFlagIsNotSuccess(): void
    {
        $result = MetadataHttpResult::classify(200, ['success' => false]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(MetadataFailureKind::ClientError, $result->kind);
    }

    public function testTransportFailureCarriesNoStatus(): void
    {
        $result = MetadataHttpResult::failure(MetadataFailureKind::Transport);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->countsAgainstHealth());
        $this->assertNull($result->httpStatus);
        $this->assertNull($result->body());
    }

    public function testUnsupportedLookupDoesNotCountAgainstHealth(): void
    {
        $result = MetadataHttpResult::unsupported("unknown id_type 'bogus'");

        // A local caller bug must not be attributed to the upstream provider…
        $this->assertFalse($result->countsAgainstHealth());
        // …but it is still a fault, not a miss.
        $this->assertTrue($result->isFailure());
        $this->assertSame("unknown id_type 'bogus'", $result->providerMessage);
    }

    public function testLogContextNamesTheOutcomeAndOmitsNulls(): void
    {
        $context = MetadataHttpResult::classify(401, [
            'status_code' => 7,
            'status_message' => 'Invalid API key: You must be granted a valid key.',
        ])->logContext();

        $this->assertSame('auth', $context['outcome']);
        $this->assertSame(401, $context['http_status']);
        $this->assertSame(7, $context['provider_status_code']);

        $bare = MetadataHttpResult::failure(MetadataFailureKind::Transport)->logContext();
        $this->assertSame(['outcome' => 'transport'], $bare);
    }

    public function testTvdbAndFanartErrorMessageKeysAreRecognised(): void
    {
        $tvdb = MetadataHttpResult::classify(401, ['Error' => 'Not Authorized']);
        $this->assertSame('Not Authorized', $tvdb->providerMessage);

        $fanart = MetadataHttpResult::classify(401, ['error message' => 'Invalid API key']);
        $this->assertSame('Invalid API key', $fanart->providerMessage);
    }

    public function testProviderMessageIsTruncated(): void
    {
        $result = MetadataHttpResult::classify(401, ['status_message' => str_repeat('x', 500)]);

        $this->assertSame(200, mb_strlen((string) $result->providerMessage));
    }

    public function testLogLevelsMatchOperationalSeverity(): void
    {
        $this->assertSame('error', MetadataFailureKind::Auth->logLevel());
        $this->assertSame('warning', MetadataFailureKind::Transport->logLevel());
        $this->assertSame('warning', MetadataFailureKind::RateLimited->logLevel());
        $this->assertSame('warning', MetadataFailureKind::ServerError->logLevel());
        $this->assertSame('warning', MetadataFailureKind::InvalidBody->logLevel());
        // Benign outcomes stay at DEBUG so a library scan cannot flood the log.
        $this->assertSame('debug', MetadataFailureKind::None->logLevel());
        $this->assertSame('debug', MetadataFailureKind::NotFound->logLevel());
    }
}
