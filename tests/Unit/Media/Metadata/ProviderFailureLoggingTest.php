<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\FanartProvider;
use Phlix\Media\Metadata\MetadataFailureKind;
use Phlix\Media\Metadata\MetadataHttpClient;
use Phlix\Media\Metadata\MetadataHttpResult;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Media\Metadata\TvdbProvider;

/**
 * The regression test for the 2026-07-28 TMDB observability incident.
 *
 * A `tmdb.api_key` holding a 10-character string made every TMDB call return
 * HTTP 401 / `status_code` 7. Because the provider collapsed that into the same
 * DEBUG `TmdbProvider: search miss` line it used for genuine zero-result
 * searches, days of total TMDB downtime read as "these titles just aren't in
 * TMDB" — and silently invalidated an episode match-rate baseline.
 *
 * Each provider is asserted on both sides of that distinction:
 *   (a) reachable provider, nothing found  -> DEBUG, still a "miss"
 *   (b) provider rejected the API key      -> ERROR, explicitly NOT a "miss"
 */
class ProviderFailureLoggingTest extends TestCase
{
    /** The historical log message that must never again describe an auth failure. */
    private const HISTORICAL_MISS_MESSAGE = 'search miss';

    /**
     * The historical "<Provider>: <operation> miss" message shape.
     *
     * Anchored at end-of-string so it matches the old collapsed line but not the
     * new failure line, which mentions "(not a miss)" mid-message on purpose.
     */
    private const HISTORICAL_MISS_PATTERN = '/: \w+ miss$/';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->logs = [];
    }

    /**
     * A logger that records every call so the level and message can be asserted.
     */
    private function capturingLogger(): StructuredLogger
    {
        $logger = $this->createMock(StructuredLogger::class);

        foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
                }
            );
        }

        $logger->method('log')->willReturnCallback(
            function ($level, string $message, array $context = []): void {
                $this->logs[] = ['level' => (string) $level, 'message' => $message, 'context' => $context];
            }
        );

        return $logger;
    }

    /**
     * @param MetadataHttpResult $result Outcome every getResult() call returns.
     */
    private function httpReturning(MetadataHttpResult $result): MetadataHttpClient
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('getResult')->willReturn($result);
        $http->method('get')->willReturn($result->body());

        return $http;
    }

    /**
     * The TMDB 401 payload exactly as prod returned it.
     */
    private static function invalidKeyResult(): MetadataHttpResult
    {
        return MetadataHttpResult::classify(401, [
            'success' => false,
            'status_code' => 7,
            'status_message' => 'Invalid API key: You must be granted a valid key.',
        ]);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function logsAtOrAbove(string ...$levels): array
    {
        return array_values(array_filter(
            $this->logs,
            static fn(array $l): bool => in_array($l['level'], $levels, true)
        ));
    }

    // ---------------------------------------------------------------- TMDB (a)

    public function testTmdbSearchWithValidKeyAndZeroResultsStaysADebugMiss(): void
    {
        $http = $this->httpReturning(MetadataHttpResult::classify(200, [
            'page' => 1,
            'results' => [],
            'total_results' => 0,
        ]));

        $results = (new TmdbProvider('valid-key', $http, $this->capturingLogger()))
            ->search('An Obscure Film Nobody Catalogued');

        $this->assertSame([], $results);
        $this->assertSame([], $this->logsAtOrAbove('warning', 'error'), 'a real empty result must not escalate');
        $this->assertNotSame([], $this->logsAtOrAbove('debug'));
    }

    public function testTmdbSearchWithMalformedButSuccessfulBodyLogsAMiss(): void
    {
        // 200, reachable, but no `results` key at all.
        $http = $this->httpReturning(MetadataHttpResult::classify(200, ['page' => 1]));

        $results = (new TmdbProvider('valid-key', $http, $this->capturingLogger()))->search('Whatever');

        $this->assertSame([], $results);
        $this->assertSame([], $this->logsAtOrAbove('warning', 'error'));
        $this->assertStringContainsString(self::HISTORICAL_MISS_MESSAGE, $this->logs[0]['message']);
        $this->assertSame('none', $this->logs[0]['context']['outcome']);
    }

    // ---------------------------------------------------------------- TMDB (b)

    public function testTmdbSearchWithInvalidKeyLogsAnErrorAndNotAMiss(): void
    {
        $http = $this->httpReturning(self::invalidKeyResult());

        $results = (new TmdbProvider('bad-key', $http, $this->capturingLogger()))
            ->search('The Matrix', ['year' => 1999]);

        $this->assertSame([], $results, 'the caller still gets no results…');

        $escalated = $this->logsAtOrAbove('error');
        $this->assertCount(1, $escalated, '…but it must be reported as an error');

        $log = $escalated[0];
        $this->assertStringContainsString('FAILED', $log['message']);
        $this->assertStringContainsString('rejected the API key', $log['message']);
        $this->assertStringNotContainsString(
            self::HISTORICAL_MISS_MESSAGE,
            $log['message'],
            'an auth failure must never be described as a "search miss" again'
        );

        $this->assertSame('auth', $log['context']['outcome']);
        $this->assertSame(401, $log['context']['http_status']);
        $this->assertSame(7, $log['context']['provider_status_code']);
        $this->assertSame('TmdbProvider', $log['context']['provider']);
        $this->assertSame('The Matrix', $log['context']['query']);
    }

    public function testTmdbTransportFailureIsAWarningNotAMiss(): void
    {
        $http = $this->httpReturning(MetadataHttpResult::failure(MetadataFailureKind::Transport));

        $results = (new TmdbProvider('valid-key', $http, $this->capturingLogger()))->search('Heat');

        $this->assertSame([], $results);
        $warnings = $this->logsAtOrAbove('warning');
        $this->assertCount(1, $warnings);
        $this->assertSame('transport', $warnings[0]['context']['outcome']);
    }

    public function testTmdbNotFoundStaysADebugMiss(): void
    {
        // A 404 is the provider correctly saying "no such record" — benign.
        $http = $this->httpReturning(MetadataHttpResult::classify(404, [
            'status_code' => 34,
            'status_message' => 'The resource you requested could not be found.',
        ]));

        $details = (new TmdbProvider('valid-key', $http, $this->capturingLogger()))->getDetails('999999999');

        $this->assertSame([], $details);
        $this->assertSame([], $this->logsAtOrAbove('warning', 'error'));
        $this->assertSame('not_found', $this->logs[0]['context']['outcome']);
    }

    /**
     * Every TMDB entry point that used to log a collapsed "miss" must escalate.
     *
     * @return array<string, array{string, array<int, mixed>}>
     */
    public static function tmdbEntryPoints(): array
    {
        return [
            'search' => ['search', ['Blade Runner']],
            'searchTv' => ['searchTv', ['Breaking Bad']],
            'getDetails' => ['getDetails', ['603']],
            'getTvDetails' => ['getTvDetails', ['1396']],
            'getTvSeason' => ['getTvSeason', ['1396', 1]],
            'findByImdbId' => ['findByImdbId', ['tt0133093']],
        ];
    }

    /**
     * @dataProvider tmdbEntryPoints
     * @param array<int, mixed> $args
     */
    public function testEveryTmdbEntryPointEscalatesAnInvalidKey(string $method, array $args): void
    {
        $provider = new TmdbProvider(
            'bad-key',
            $this->httpReturning(self::invalidKeyResult()),
            $this->capturingLogger()
        );

        $provider->{$method}(...$args);

        $escalated = $this->logsAtOrAbove('error');
        $this->assertCount(1, $escalated, "{$method}() swallowed an auth failure");
        $this->assertSame('auth', $escalated[0]['context']['outcome']);
        $this->assertDoesNotMatchRegularExpression(
            self::HISTORICAL_MISS_PATTERN,
            $escalated[0]['message'],
            "{$method}() still reports an auth failure using the old miss message"
        );
    }

    // ---------------------------------------------------------------- TVDB

    public function testTvdbSearchWithValidKeyAndZeroResultsStaysADebugMiss(): void
    {
        $http = $this->httpReturning(MetadataHttpResult::classify(200, ['data' => []]));

        $results = (new TvdbProvider('valid-key', 'eng', $this->capturingLogger(), $http))->search('Nothing Here');

        $this->assertSame([], $results);
        $this->assertSame([], $this->logsAtOrAbove('warning', 'error'));
    }

    public function testTvdbSearchWithUnauthorisedKeyLogsAnError(): void
    {
        // TVDB v3 phrases its 401 differently to TMDB.
        $http = $this->httpReturning(MetadataHttpResult::classify(401, ['Error' => 'Not Authorized']));

        $results = (new TvdbProvider('bad-key', 'eng', $this->capturingLogger(), $http))->search('Breaking Bad');

        $this->assertSame([], $results);
        $escalated = $this->logsAtOrAbove('error');
        $this->assertCount(1, $escalated);
        $this->assertSame('auth', $escalated[0]['context']['outcome']);
        $this->assertSame('Not Authorized', $escalated[0]['context']['provider_message']);
        $this->assertStringNotContainsString(self::HISTORICAL_MISS_MESSAGE, $escalated[0]['message']);
    }

    public function testTvdbEpisodeLookupEscalatesAnAuthFailure(): void
    {
        // The episode path is what the match-rate baseline is computed from, so
        // a silent failure here is what corrupted the 94.96% figure.
        $http = $this->httpReturning(MetadataHttpResult::classify(401, ['Error' => 'Not Authorized']));

        $episodes = (new TvdbProvider('bad-key', 'eng', $this->capturingLogger(), $http))
            ->getSeasonEpisodes('81189', 1);

        $this->assertSame([], $episodes);
        $this->assertCount(1, $this->logsAtOrAbove('error'));
    }

    // ---------------------------------------------------------------- Fanart

    public function testFanartWithValidKeyAndNoArtworkStaysADebugMiss(): void
    {
        $http = $this->httpReturning(MetadataHttpResult::classify(404, []));

        $images = (new FanartProvider('valid-key', $this->capturingLogger(), $http))->getTvShowImages('81189');

        $this->assertSame([], $images);
        $this->assertSame([], $this->logsAtOrAbove('warning', 'error'));
        $this->assertSame('not_found', $this->logs[0]['context']['outcome']);
    }

    public function testFanartWithInvalidKeyLogsAnError(): void
    {
        $http = $this->httpReturning(MetadataHttpResult::classify(401, ['error message' => 'Invalid API key']));

        $images = (new FanartProvider('bad-key', $this->capturingLogger(), $http))->getMovieImages('tt0133093');

        $this->assertSame([], $images);
        $escalated = $this->logsAtOrAbove('error');
        $this->assertCount(1, $escalated);
        $this->assertSame('auth', $escalated[0]['context']['outcome']);
        $this->assertSame('FanartProvider', $escalated[0]['context']['provider']);
    }

    public function testFanartUnknownIdTypeIsReportedAsALocalFaultNotAnOutage(): void
    {
        $http = $this->httpReturning(MetadataHttpResult::classify(200, []));

        $details = (new FanartProvider('valid-key', $this->capturingLogger(), $http))
            ->getDetails('123', ['id_type' => 'bogus']);

        $this->assertSame([], $details);
        $warnings = $this->logsAtOrAbove('warning');
        $this->assertCount(1, $warnings);
        $this->assertSame('unsupported', $warnings[0]['context']['outcome']);
    }
}
