<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\MetadataHttpClient;
use Phlix\Media\Metadata\MetadataHttpResult;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unconfigured {@see TmdbProvider} must be OBSERVABLE, and recoverable.
 *
 * ## The defect
 *
 * A provider holding an empty key returns `[]` from every lookup. That is
 * byte-identical to a genuine "no match", so the only symptom is an
 * unmatched-item count that stalls — with nothing in the logs. Production
 * 2026-07-28 hit exactly this: the DI factory swallowed a settings-store
 * failure, baked an empty key into the worker, and the scan reported no
 * matches for hours.
 *
 * ## What is pinned here
 *
 * 1. A lookup attempted with NO key logs a warning — ONCE per provider, not
 *    once per item (a library scan runs this path tens of thousands of times).
 * 2. A key that resolved empty at construction is RE-RESOLVED on a later
 *    lookup, and the recovered key reaches the HTTP client that stamps it onto
 *    the request — otherwise recovery would be cosmetic.
 * 3. Re-resolution is rate-limited, so a settings store that is DOWN is not
 *    re-queried once per item.
 * 4. A resolver that throws is logged and never escapes into the caller: a
 *    metadata lookup must not fail because the settings store did.
 */
final class TmdbProviderMissingKeyTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * An HTTP client stub that answers every lookup with an empty TMDB payload.
     *
     * Both client entry points are stubbed: `getResult()` backs the six lookups
     * that classify the upstream status, `get()` the rest.
     */
    private function httpReturningNoResults(): MetadataHttpClient&\PHPUnit\Framework\MockObject\MockObject
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('get')->willReturn(['results' => []]);
        $http->method('getResult')->willReturn(MetadataHttpResult::success(200, ['results' => []]));

        return $http;
    }

    /**
     * A logger that records every warning it is handed.
     *
     * @param list<array{message: string, context: array<string, mixed>}> $sink
     */
    private function recordingLogger(array &$sink): StructuredLogger
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('warning')->willReturnCallback(
            static function (string|\Stringable $message, array $context = []) use (&$sink): void {
                $sink[] = ['message' => (string) $message, 'context' => $context];
            }
        );

        return $logger;
    }

    private function keyOf(TmdbProvider $provider): string
    {
        $prop = new \ReflectionProperty(TmdbProvider::class, 'apiKey');
        $prop->setAccessible(true);

        /** @var string $key */
        $key = $prop->getValue($provider);

        return $key;
    }

    /**
     * CONSEQUENCE: a lookup with no key is no longer silent.
     *
     * Goes RED before the fix — nothing logged anything about a missing key.
     */
    public function test_a_lookup_with_no_key_logs_a_warning(): void
    {
        $warnings = [];
        $provider = new TmdbProvider('', $this->httpReturningNoResults(), $this->recordingLogger($warnings));

        $this->assertSame([], $provider->search('Inception'));

        $this->assertCount(1, $warnings, 'A keyless lookup produced NO log line — the empty result is unattributable.');
        $this->assertStringContainsStringIgnoringCase('api key', $warnings[0]['message']);
        $this->assertSame('/search/movie', $warnings[0]['context']['endpoint'] ?? null);
    }

    /**
     * …but exactly ONCE. A library scan runs this path once per item; a warning
     * per item would be a log flood, and a flooding rule gets deleted.
     */
    public function test_the_missing_key_warning_is_emitted_only_once(): void
    {
        $warnings = [];
        $provider = new TmdbProvider('', $this->httpReturningNoResults(), $this->recordingLogger($warnings));

        $provider->search('Inception');
        $provider->searchTv('24');
        $provider->getDetails('27205');
        $provider->getImages('27205');
        $provider->getTrailers('27205');

        $this->assertCount(
            1,
            $warnings,
            'The missing-key warning is emitted per lookup. Over a 61k-item library that '
            . 'is 61k identical lines.'
        );
    }

    /**
     * A configured provider must stay quiet — a warning that also fires on the
     * happy path is noise, and noise is what gets a rule switched off.
     */
    public function test_a_configured_provider_logs_no_missing_key_warning(): void
    {
        $warnings = [];
        $provider = new TmdbProvider('a-real-key', $this->httpReturningNoResults(), $this->recordingLogger($warnings));

        $provider->search('Inception');
        $provider->getDetails('27205');

        $this->assertSame([], $warnings);
    }

    /**
     * Every public lookup must funnel through the guarded request path — a
     * method that still called the HTTP client directly would be silent again.
     *
     * @dataProvider lookupProvider
     */
    public function test_every_lookup_entry_point_is_guarded(callable $lookup): void
    {
        $warnings = [];
        $provider = new TmdbProvider('', $this->httpReturningNoResults(), $this->recordingLogger($warnings));

        $lookup($provider);

        $this->assertCount(1, $warnings, 'This lookup bypassed the missing-key guard.');
    }

    /**
     * @return array<string, array{0: callable}>
     */
    public static function lookupProvider(): array
    {
        return [
            'search'                => [static fn (TmdbProvider $p) => $p->search('Inception')],
            'findByImdbId'          => [static fn (TmdbProvider $p) => $p->findByImdbId('tt1375666')],
            'getDetails'            => [static fn (TmdbProvider $p) => $p->getDetails('27205')],
            'searchTv'              => [static fn (TmdbProvider $p) => $p->searchTv('24')],
            'getTvDetails'          => [static fn (TmdbProvider $p) => $p->getTvDetails('1668')],
            'getTvSeason'           => [static fn (TmdbProvider $p) => $p->getTvSeason('1668', 1)],
            'getImages'             => [static fn (TmdbProvider $p) => $p->getImages('27205')],
            'getCollection'         => [static fn (TmdbProvider $p) => $p->getCollection(10)],
            'getCollectionIdForMovie' => [static fn (TmdbProvider $p) => $p->getCollectionIdForMovie(27205)],
            'getTrailers'           => [static fn (TmdbProvider $p) => $p->getTrailers('27205')],
        ];
    }

    /**
     * CONSEQUENCE: an empty key is re-resolved on the next lookup, so a
     * transient settings-store outage at construction is not permanent.
     */
    public function test_an_empty_key_is_re_resolved_on_the_next_lookup(): void
    {
        $provider = new TmdbProvider(
            '',
            $this->httpReturningNoResults(),
            $this->createMock(StructuredLogger::class),
            static fn (): string => 'late-key'
        );

        $this->assertTrue($provider->hasApiKey());
        $this->assertSame('late-key', $this->keyOf($provider));
    }

    /**
     * …and the recovered key must reach the HTTP CLIENT, which is what actually
     * stamps `api_key` onto the outgoing request. Updating only the provider's
     * own field would make `hasApiKey()` report a key while every request still
     * went out unauthenticated.
     */
    public function test_the_recovered_key_reaches_the_http_client(): void
    {
        $http = $this->createMock(MetadataHttpClient::class);
        $http->method('get')->willReturn(['results' => []]);
        $http->method('getResult')->willReturn(MetadataHttpResult::success(200, ['results' => []]));
        $http->expects($this->once())->method('setApiKey')->with('late-key');

        $provider = new TmdbProvider(
            '',
            $http,
            $this->createMock(StructuredLogger::class),
            static fn (): string => 'late-key'
        );

        $provider->search('Inception');
    }

    /**
     * Once recovered, the key stops being re-resolved — the happy path must not
     * pay for a settings read on every single lookup.
     */
    public function test_resolution_stops_once_a_key_is_found(): void
    {
        $calls = 0;
        $provider = new TmdbProvider(
            '',
            $this->httpReturningNoResults(),
            $this->createMock(StructuredLogger::class),
            static function () use (&$calls): string {
                $calls++;

                return 'late-key';
            }
        );

        $provider->search('a');
        $provider->search('b');
        $provider->search('c');

        $this->assertSame(1, $calls, 'The resolver ran again after the key was already known.');
    }

    /**
     * A resolver that keeps returning nothing must be RATE-LIMITED, not retried
     * on every lookup: re-resolution reads the settings store, which issues a DB
     * SELECT, and a scan calls this path once per item.
     */
    public function test_a_still_empty_resolution_is_not_retried_on_every_lookup(): void
    {
        $calls = 0;
        $provider = new TmdbProvider(
            '',
            $this->httpReturningNoResults(),
            $this->createMock(StructuredLogger::class),
            static function () use (&$calls): string {
                $calls++;

                return '';
            }
        );

        for ($i = 0; $i < 50; $i++) {
            $provider->search('item-' . $i);
        }

        $this->assertSame(
            1,
            $calls,
            'Re-resolution is unthrottled: a DOWN settings store would be re-queried once '
            . 'per item for every item in the library.'
        );
    }

    /**
     * A resolver that THROWS must be logged and contained. A metadata lookup
     * must not fail because the settings store did.
     */
    public function test_a_throwing_resolver_is_logged_and_contained(): void
    {
        $warnings = [];
        $provider = new TmdbProvider(
            '',
            $this->httpReturningNoResults(),
            $this->recordingLogger($warnings),
            static function (): string {
                throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
            }
        );

        $this->assertFalse($provider->hasApiKey());
        $this->assertSame([], $provider->search('Inception'), 'The resolver failure escaped into the caller.');

        $joined = json_encode($warnings, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(\RuntimeException::class, $joined);
        $this->assertStringContainsString('Connection refused', $joined);
    }

    /**
     * A resolver returning a non-string (settings stores are untyped — a JSON
     * column, a null row, a bool) must be ignored, never coerced into a key.
     *
     * @dataProvider junkProvider
     */
    public function test_a_junk_resolution_is_ignored(mixed $value): void
    {
        $provider = new TmdbProvider(
            '',
            $this->httpReturningNoResults(),
            $this->createMock(StructuredLogger::class),
            static fn (): mixed => $value
        );

        $this->assertFalse($provider->hasApiKey());
        $this->assertSame('', $this->keyOf($provider));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function junkProvider(): array
    {
        return [
            'null'         => [null],
            'int'          => [42],
            'array'        => [['key']],
            'bool'         => [true],
            'empty string' => [''],
        ];
    }

    /**
     * With no resolver at all (the historical two-argument construction) the
     * provider behaves exactly as before — no resolution, no crash.
     */
    public function test_no_resolver_keeps_the_previous_behaviour(): void
    {
        $provider = new TmdbProvider('', $this->httpReturningNoResults());

        $this->assertFalse($provider->hasApiKey());
        $this->assertSame([], $provider->search('Inception'));
    }
}
