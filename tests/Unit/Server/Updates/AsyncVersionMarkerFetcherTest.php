<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Updates;

use Phlix\Server\Updates\AsyncVersionMarkerFetcher;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Tests\Support\Updates\CapturingHttpClient;
use Phlix\Tests\Support\Updates\StubMarkerResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Http\Client;
use Workerman\Timer;
use Workerman\Worker;

/**
 * {@see AsyncVersionMarkerFetcher} — S74.
 *
 * ## Why this class exists at all, and why it is testable
 *
 * The step text prescribes
 * {@see \Phlix\Plugins\Catalog\PluginCatalogService::defaultFetcher()}. That
 * shape forks on `WorkerContext::inCoroutine()` and waits on a
 * `Swoole\Coroutine\Channel`. Under PHPUnit `Coroutine::getCid()` is ALWAYS
 * `-1`, so the async arm — the one production takes — is structurally
 * unreachable from any test in this suite (the S170 defect class).
 *
 * This implementation has no such fork: `Client::request()` with BOTH `success`
 * and `error` supplied never enters its coroutine branch (guarded by
 * `!isset($options['success']) && Coroutine::isCoroutine()`, vendor
 * `Client.php:78`). Every test below drives the SAME code path production does.
 * {@see testTheVendorGuardThisClassDependsOnStillReadsTheSuccessCallback} pins
 * that guard so a vendor bump cannot silently reintroduce the fork.
 *
 * @package Phlix\Tests\Unit\Server\Updates
 */
final class AsyncVersionMarkerFetcherTest extends TestCase
{
    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Workerman\Http\Client defers a SYNCHRONOUS failure onto
        // Timer::add(0.000001, …) rather than rethrowing (vendor
        // Client.php:393-405), and Timer::add itself throws when the worker
        // registry is empty. Seeding it keeps that vendor arm from reporting as
        // a failure of THIS class.
        $this->savedWorkers = Worker::getAllWorkers();

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $stub = new Worker();
        $workers->setValue(null, [spl_object_id($stub) => $stub]);

        Timer::delAll();
    }

    protected function tearDown(): void
    {
        Timer::delAll();

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        parent::tearDown();
    }

    private function fetcherWith(Client $client): AsyncVersionMarkerFetcher
    {
        return new AsyncVersionMarkerFetcher(10, $client);
    }

    /**
     * Drive one fetch and return the `[body, error]` pair the callback saw.
     *
     * @param callable(CapturingHttpClient):void $drive Invokes one of the captured callbacks.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function outcomeOf(callable $drive): array
    {
        $client = new CapturingHttpClient();
        /** @var array{0: string|null, 1: string|null} $seen */
        $seen = [null, null];

        $this->fetcherWith($client)->fetch(
            'https://example.invalid/VERSION',
            static function (?string $body, ?string $error) use (&$seen): void {
                $seen = [$body, $error];
            },
        );

        $drive($client);

        return $seen;
    }

    /**
     * A completion callback that appends every `[body, error]` pair it is
     * invoked with to `$seen`, in order — the multi-leg (fallback) tests drive
     * several legs before completing, so they observe a list, not a pair.
     *
     * @param array<int, array{0: string|null, 1: string|null}> $seen Accumulator, by reference.
     */
    private static function recorder(array &$seen): callable
    {
        return static function (?string $body, ?string $error) use (&$seen): void {
            $seen[] = [$body, $error];
        };
    }

    public function testItIsTheProductionImplementationOfTheInterface(): void
    {
        self::assertInstanceOf(
            VersionMarkerFetcherInterface::class,
            $this->fetcherWith(new CapturingHttpClient()),
        );
    }

    /**
     * The property the whole design rests on: BOTH callbacks are supplied, and
     * the method is GET.
     */
    public function testBothCallbacksAreSuppliedSoTheCoroutineBranchIsNeverTaken(): void
    {
        $client = new CapturingHttpClient();
        $this->fetcherWith($client)->fetch('https://example.invalid/VERSION', static function (): void {
        });

        self::assertSame('https://example.invalid/VERSION', $client->url);
        self::assertSame('GET', $client->options['method'] ?? null);
        self::assertArrayHasKey('success', $client->options);
        self::assertArrayHasKey('error', $client->options);
        self::assertIsCallable($client->options['success']);
        self::assertIsCallable($client->options['error']);
    }

    /**
     * A guard in vendor code is only load-bearing while it still says what we
     * think it says. Read the vendor source rather than trusting the docblock.
     */
    public function testTheVendorGuardThisClassDependsOnStillReadsTheSuccessCallback(): void
    {
        $file = (new ReflectionClass(Client::class))->getFileName();
        self::assertIsString($file);
        $source = (string) file_get_contents($file);

        self::assertStringContainsString(
            "!isset(\$options['success']) && Coroutine::isCoroutine()",
            $source,
            'workerman/http-client no longer skips its coroutine branch when a success callback is '
            . 'supplied. AsyncVersionMarkerFetcher depends on that guard to stay fork-free; re-read '
            . 'Client::request() before bumping the dependency.',
        );
    }

    public function testASuccessfulResponseYieldsTheBodyAndNoError(): void
    {
        self::assertSame(
            ["1.2.3\n", null],
            $this->outcomeOf(static function (CapturingHttpClient $c): void {
                ($c->successCallback())(new StubMarkerResponse("1.2.3\n"));
            }),
        );
    }

    public function testATransportErrorYieldsAnErrorAndNoBody(): void
    {
        self::assertSame(
            [null, 'connection refused'],
            $this->outcomeOf(static function (CapturingHttpClient $c): void {
                ($c->errorCallback())(new \RuntimeException('connection refused'));
            }),
        );
    }

    public function testANonThrowableErrorArgumentIsStillDescribed(): void
    {
        self::assertSame(
            [null, 'socket timeout'],
            $this->outcomeOf(static function (CapturingHttpClient $c): void {
                ($c->errorCallback())('socket timeout');
            }),
        );
    }

    public function testAnEmptyErrorArgumentFallsBackToAGenericMessage(): void
    {
        self::assertSame(
            [null, 'update check: version marker fetch failed'],
            $this->outcomeOf(static function (CapturingHttpClient $c): void {
                ($c->errorCallback())(null);
            }),
        );
    }

    public function testABodyExactlyAtTheLimitIsAccepted(): void
    {
        $body = str_repeat('a', AsyncVersionMarkerFetcher::MAX_BODY_BYTES);

        self::assertSame(
            [$body, null],
            $this->outcomeOf(static function (CapturingHttpClient $c) use ($body): void {
                ($c->successCallback())(new StubMarkerResponse($body));
            }),
        );
    }

    /**
     * A captive portal / GitHub 404 page must never be handed to the parser.
     */
    public function testAnOversizedBodyIsRejected(): void
    {
        $oversized = str_repeat('a', AsyncVersionMarkerFetcher::MAX_BODY_BYTES + 1);

        $seen = $this->outcomeOf(static function (CapturingHttpClient $c) use ($oversized): void {
            ($c->successCallback())(new StubMarkerResponse($oversized));
        });

        self::assertNull($seen[0]);
        self::assertIsString($seen[1]);
        self::assertStringContainsString('exceeds 256 bytes', $seen[1]);
    }

    public function testAResponseWithoutABodyAccessorIsAnError(): void
    {
        self::assertSame(
            [null, 'update check: unexpected response object'],
            $this->outcomeOf(static function (CapturingHttpClient $c): void {
                ($c->successCallback())('not an object');
            }),
        );
    }

    public function testAThrowingBodyAccessorBecomesAnError(): void
    {
        self::assertSame(
            [null, 'stream detached'],
            $this->outcomeOf(static function (CapturingHttpClient $c): void {
                ($c->successCallback())(new StubMarkerResponse('', true));
            }),
        );
    }

    /**
     * The `$done` latch is load-bearing, not decorative: `Client::request()`
     * defers a synchronous failure onto a timer, so both the error and the
     * success path can plausibly fire for one request.
     */
    public function testTheCompletionCallbackFiresAtMostOnce(): void
    {
        $client = new CapturingHttpClient();
        $calls = 0;
        $this->fetcherWith($client)->fetch('u', static function () use (&$calls): void {
            $calls++;
        });

        ($client->successCallback())(new StubMarkerResponse('1.2.2'));
        ($client->successCallback())(new StubMarkerResponse('9.9.9'));
        ($client->errorCallback())(new \RuntimeException('late failure'));

        self::assertSame(1, $calls);
    }

    /**
     * A synchronous throw out of the client must arrive as an error argument,
     * never as an exception — this runs inside a Workerman timer callback.
     */
    public function testASynchronousClientThrowBecomesAnErrorArgument(): void
    {
        /** @var array{0: string|null, 1: string|null} $seen */
        $seen = [null, null];

        $this->fetcherWith(new CapturingHttpClient(true))->fetch(
            'u',
            static function (?string $body, ?string $error) use (&$seen): void {
                $seen = [$body, $error];
            },
        );

        self::assertSame([null, 'bad address'], $seen);
    }

    /**
     * The client is built lazily and REUSED — one pooled client per process, not
     * one per check. A per-fetch client in a resident-memory worker is a leak.
     */
    public function testTheClientIsCreatedLazilyRatherThanInTheConstructor(): void
    {
        $fetcher = new AsyncVersionMarkerFetcher(10);

        $prop = new ReflectionProperty(AsyncVersionMarkerFetcher::class, 'client');
        $prop->setAccessible(true);

        self::assertNull($prop->getValue($fetcher), 'Constructing the fetcher must not build an HTTP client.');
    }

    /**
     * The read-body helper's declared return shape is what lets the success
     * callback destructure into typed locals instead of spreading (Psalm cannot
     * see through an argument-unpack into a Closure-typed variable — that cost
     * S75 a red gate).
     */
    public function testReadBodyReturnsAnExactlyTwoElementPair(): void
    {
        $method = new ReflectionMethod(AsyncVersionMarkerFetcher::class, 'readBody');
        $method->setAccessible(true);

        /** @var array<int, string|null> $pair */
        $pair = $method->invoke(null, new StubMarkerResponse('1.2.2'));
        self::assertCount(2, $pair);
        self::assertSame('1.2.2', $pair[0]);
        self::assertNull($pair[1]);
    }

    /**
     * The success callback must pass `(body, error)` in THAT order. Swapping
     * them would make every successful fetch look like a failure whose message
     * happens to be a version string — and `record()` would persist it as an
     * error rather than as the latest version.
     */
    public function testTheCompletionPairIsBodyThenErrorNotTheReverse(): void
    {
        $seen = $this->outcomeOf(static function (CapturingHttpClient $c): void {
            ($c->successCallback())(new StubMarkerResponse('1.2.9'));
        });

        self::assertSame('1.2.9', $seen[0], 'The BODY must arrive as the first argument.');
        self::assertNull($seen[1], 'A successful fetch must report no error.');
    }

    // ===== Fastly → api.github.com fallback leg ==============================

    private const GITHUB_URL = 'https://raw.githubusercontent.com/detain/phlix-server/master/VERSION';

    private const GITHUB_FALLBACK_URL =
        'https://api.github.com/repos/detain/phlix-server/contents/VERSION?ref=master';

    /**
     * (a) A transport error on a raw-github marker re-issues against
     * api.github.com with the derived contents URL and the raw-accept header; a
     * success there is a CLEAN status with no fallback trace.
     */
    public function testAGithubRawErrorFallsBackToApiContentsAndSucceeds(): void
    {
        $client = new CapturingHttpClient();

        $seen = [];
        $this->fetcherWith($client)->fetch(self::GITHUB_URL, self::recorder($seen));

        self::assertSame(1, $client->requestCount());
        self::assertSame(self::GITHUB_URL, $client->urlOf(0), 'the primary leg is the raw marker');

        // The primary leg fails — this is the Fastly read-timeout symptom.
        ($client->errorOf(0))(new \RuntimeException('read timeout'));

        self::assertSame([], $seen, 'the fallback is in flight; nothing completes yet');
        self::assertSame(2, $client->requestCount(), 'exactly one fallback leg');
        self::assertSame(self::GITHUB_FALLBACK_URL, $client->urlOf(1));

        $headers = $client->optionsOf(1)['headers'] ?? null;
        self::assertIsArray($headers, 'the fallback must carry an Accept header');
        self::assertSame('application/vnd.github.raw', $headers['Accept'] ?? null);

        ($client->successOf(1))(new StubMarkerResponse("0.9.9\n"));

        self::assertSame([["0.9.9\n", null]], $seen, 'a successful fallback is a clean status');
    }

    /**
     * (b) When BOTH legs fail, the error combines the primary message with the
     * fallback's so the operator sees the original timeout and why the backup
     * also failed — exactly one completion.
     */
    public function testWhenBothLegsFailTheMessagesCombine(): void
    {
        $client = new CapturingHttpClient();

        $seen = [];
        $this->fetcherWith($client)->fetch(self::GITHUB_URL, self::recorder($seen));

        $primary = 'read 185.199.111.133:443 timeout after 10 seconds';
        ($client->errorOf(0))(new \RuntimeException($primary));
        ($client->errorOf(1))(new \RuntimeException('connection refused'));

        self::assertSame([[null, $primary . ' (fallback: connection refused)']], $seen);
    }

    /**
     * (c) A non-github marker URL (operator mirror via PHLIX_UPDATES_MARKER_URL
     * or server_settings) must NOT trigger a second request — the fallback is
     * scoped to the raw-github shape only.
     */
    public function testANonGithubErrorIssuesNoFallback(): void
    {
        $client = new CapturingHttpClient();

        $seen = [];
        $this->fetcherWith($client)->fetch('https://example.invalid/VERSION', self::recorder($seen));

        ($client->errorOf(0))(new \RuntimeException('connection refused'));

        self::assertSame(1, $client->requestCount(), 'a non-github URL gets no fallback leg');
        self::assertSame([[null, 'connection refused']], $seen);
    }

    /**
     * (d) A successful primary leg is exactly ONE request — the fallback must
     * never fire on success (zero extra traffic on the happy path).
     */
    public function testAGithubSuccessIssuesNoFallback(): void
    {
        $client = new CapturingHttpClient();

        $seen = [];
        $this->fetcherWith($client)->fetch(self::GITHUB_URL, self::recorder($seen));

        ($client->successOf(0))(new StubMarkerResponse('1.2.3'));

        self::assertSame(1, $client->requestCount(), 'a healthy marker costs one request');
        self::assertSame([['1.2.3', null]], $seen);
    }

    /**
     * (e) The byte cap re-applies to the FALLBACK body identically: an oversized
     * fallback response is rejected, not parsed.
     */
    public function testTheByteCapAppliesToTheFallbackBody(): void
    {
        $client = new CapturingHttpClient();

        $seen = [];
        $this->fetcherWith($client)->fetch(self::GITHUB_URL, self::recorder($seen));

        ($client->errorOf(0))(new \RuntimeException('read timeout'));
        $oversized = str_repeat('x', AsyncVersionMarkerFetcher::MAX_BODY_BYTES + 1);
        ($client->successOf(1))(new StubMarkerResponse($oversized));

        self::assertCount(1, $seen);
        self::assertNull($seen[0][0]);
        self::assertStringContainsString('exceeds', (string) $seen[0][1]);
    }

    /**
     * (f) The ref is carried verbatim from the primary URL, so a version-tag
     * (or master) marker stays the same marker on the fallback host.
     *
     * @return array<string, array{string, string}>
     */
    public static function refProvider(): array
    {
        return [
            'master' => [
                'https://raw.githubusercontent.com/detain/phlix-server/master/VERSION',
                'https://api.github.com/repos/detain/phlix-server/contents/VERSION?ref=master',
            ],
            'version tag' => [
                'https://raw.githubusercontent.com/detain/phlix-server/v1.2.3/VERSION',
                'https://api.github.com/repos/detain/phlix-server/contents/VERSION?ref=v1.2.3',
            ],
            'nested path' => [
                'https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh',
                'https://api.github.com/repos/detain/phlix-server/contents/scripts/install.sh?ref=master',
            ],
        ];
    }

    /**
     * @dataProvider refProvider
     */
    public function testTheFallbackPreservesRefAndPath(string $primary, string $expectedFallback): void
    {
        $client = new CapturingHttpClient();

        $this->fetcherWith($client)->fetch($primary, static function (?string $body, ?string $error): void {
        });

        ($client->errorOf(0))(new \RuntimeException('read timeout'));

        self::assertSame(2, $client->requestCount());
        self::assertSame($expectedFallback, $client->urlOf(1));
    }
}
