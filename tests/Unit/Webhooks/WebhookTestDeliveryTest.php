<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Http\Controllers\Webhooks\WebhookAdminController;
use Phlix\Server\Http\Request;
use Phlix\Webhooks\WebhookDispatcher;
use Phlix\Webhooks\WebhookEvent;
use Phlix\Webhooks\WebhookHttpClient;
use Workerman\MySQL\Connection;

/**
 * Consequence tests for the admin "Test webhook" button.
 *
 * ## What was wrong before
 *
 * `WebhookAdminController::test()` resolved the specific webhook row the
 * operator clicked and then threw that away, calling
 * `WebhookDispatcher::dispatch()` with a `webhook.test` event.
 * `dispatch()` delivers only to rows whose stored `events_json` CONTAINS the
 * event type (`getMatchingWebhooks()`), and the admin UI's subscribable
 * catalogue deliberately excludes `webhook.test`. So for every webhook ever
 * created through the UI:
 *
 *   getMatchingWebhooks('webhook.test') -> []
 *     -> dispatch() short-circuits to DispatchResult(0, 0, [])
 *       -> failureCount === 0
 *         -> the endpoint answered `success: true`
 *
 * ...for a request that never left the process. The button could not fail, and
 * therefore could not test anything.
 *
 * ## What these tests assert
 *
 * The CONSEQUENCE, on two axes: (1) an HTTP delivery is actually ATTEMPTED
 * against the clicked webhook's URL, and (2) the reported `success` tracks the
 * real outcome of that attempt. Asserting only on the response shape would not
 * distinguish the old fabricated `success: true` from a genuine one, so every
 * success case also asserts the recorded outbound request.
 */
final class WebhookTestDeliveryTest extends TestCase
{
    /** Event type the Test button sends -- absent from the UI catalogue. */
    private const TEST_EVENT = 'webhook.test';

    /** Events the fixture webhook subscribes to. Deliberately excludes TEST_EVENT. */
    private const SUBSCRIBED_EVENTS = '["playback.started","library.updated"]';

    private const WEBHOOK_ID = 'wh-clicked-by-admin';
    private const WEBHOOK_URL = 'https://hooks.example.com/deliver';
    private const WEBHOOK_SECRET = 'sh4red-s3cret';

    protected function setUp(): void
    {
        parent::setUp();

        EffectiveConfig::reset();

        // Deterministic, offline SSRF-guard resolution: the fixture host maps
        // to a public IP so sendToWebhook() proceeds without real DNS.
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        EffectiveConfig::reset();
        parent::tearDown();
    }

    /**
     * A DB double serving one webhook row and swallowing the log/counter writes.
     *
     * @param array<string, mixed> $overrides Row fields to replace.
     */
    private function fakeDb(array $overrides = [], bool $rowExists = true): Connection
    {
        $row = array_merge([
            'id' => self::WEBHOOK_ID,
            'name' => 'Operator Webhook',
            'url' => self::WEBHOOK_URL,
            'secret' => self::WEBHOOK_SECRET,
            'events_json' => self::SUBSCRIBED_EVENTS,
            'is_active' => 1,
        ], $overrides);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function ($sql = '', $params = null) use ($row, $rowExists): array {
                $query = is_string($sql) ? $sql : '';

                // Single-row lookup used by dispatchToWebhook().
                if (str_contains($query, 'FROM webhooks WHERE id = ?')) {
                    return $rowExists ? [$row] : [];
                }

                // Subscription-filtered lookup used by dispatch(). Answered
                // honestly: this webhook does NOT subscribe to webhook.test, so
                // the old code path finds nothing here.
                if (str_contains($query, 'is_active = TRUE')) {
                    return [$row];
                }

                // Listing used by the controller's 404 pre-check. Mirrors
                // production in omitting `secret`.
                if (str_contains($query, 'FROM webhooks')) {
                    $listed = $row;
                    unset($listed['secret']);
                    return $rowExists ? [$listed] : [];
                }

                return [];
            }
        );

        return $db;
    }

    /**
     * Build a dispatcher whose HTTP client is the supplied double and whose
     * retry backoff does not really sleep.
     */
    private function dispatcherWith(Connection $db, WebhookHttpClient $http): WebhookDispatcher
    {
        return new class ($db, $this->createMock(StructuredLogger::class), $http) extends WebhookDispatcher {
            public function __construct(
                Connection $db,
                StructuredLogger $logger,
                private readonly WebhookHttpClient $testHttpClient,
            ) {
                parent::__construct($db, $logger);
            }

            protected function getHttpClient(): WebhookHttpClient
            {
                return $this->testHttpClient;
            }

            protected function sleepMilliseconds(int $milliseconds): void
            {
                // No wall-clock delay between retry attempts under test.
            }
        };
    }

    /**
     * An HTTP client double that records every outbound call.
     *
     * @param array{success: bool, response_code: int|null, response_body: string|null, error: string|null} $result
     * @param list<array{url: string, headers: array<string, string>, body: string}> $calls
     */
    private function fakeHttpClient(array $result, array &$calls): WebhookHttpClient
    {
        $http = $this->createMock(WebhookHttpClient::class);
        $http->method('postWithHeaders')->willReturnCallback(
            static function (string $url, array $headers, string $body) use ($result, &$calls): array {
                $calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
                return $result;
            }
        );

        return $http;
    }

    /**
     * @param array{success: bool, response_code: int|null, response_body: string|null, error: string|null}|null $result
     * @return array{success: bool, response_code: int|null, response_body: string|null, error: string|null}
     */
    private function httpOk(?array $result = null): array
    {
        return $result ?? [
            'success' => true,
            'response_code' => 200,
            'response_body' => 'ok',
            'error' => null,
        ];
    }

    private function testEvent(): WebhookEvent
    {
        return new WebhookEvent(
            self::TEST_EVENT,
            ['message' => 'This is a test webhook event', 'webhook_id' => self::WEBHOOK_ID],
            new DateTimeImmutable()
        );
    }

    private function adminRequest(): Request
    {
        $request = new Request();
        $request->userId = 'admin-1';

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'Response body is not JSON: ' . $body);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // -----------------------------------------------------------------
    // (a) The regression: delivery happens even with no matching events.
    // -----------------------------------------------------------------

    /**
     * THE regression test.
     *
     * The webhook subscribes to `playback.started` / `library.updated` and NOT
     * to `webhook.test`, which is the situation for every webhook the admin UI
     * can create. An HTTP delivery must still be attempted, to that webhook's
     * URL, and the success must be the real one.
     *
     * Mutation-verified: re-adding the subscription gate to
     * `dispatchToWebhook()` -- i.e. returning `new DispatchResult(0, 0, [])`
     * when `in_array($event->eventType, $events, true)` is false -- drops the
     * outbound call to zero and the successCount to 0, and this test fails on
     * the first assertion.
     */
    public function test_delivery_is_attempted_even_when_webhook_subscribes_to_no_matching_event(): void
    {
        /** @var list<array{url: string, headers: array<string, string>, body: string}> $calls */
        $calls = [];

        $dispatcher = $this->dispatcherWith(
            $this->fakeDb(),
            $this->fakeHttpClient($this->httpOk(), $calls)
        );

        $result = $dispatcher->dispatchToWebhook(self::WEBHOOK_ID, $this->testEvent());

        // (1) A delivery really left the process, aimed at THIS webhook.
        self::assertCount(1, $calls, 'Expected exactly one outbound delivery attempt.');
        self::assertSame(self::WEBHOOK_URL, $calls[0]['url']);

        // (2) The reported outcome is that real delivery's outcome.
        self::assertSame(1, $result->successCount);
        self::assertSame(0, $result->failureCount);
        self::assertSame([], $result->failures);
    }

    /**
     * The old code path, kept as a pinned contrast: `dispatch()` genuinely does
     * NOT deliver this event to this webhook. This is what made the button lie,
     * and it documents WHY `dispatchToWebhook()` has to exist rather than the
     * controller simply keeping its old call.
     *
     * `dispatch()`'s subscription semantics are intentionally unchanged -- other
     * callers depend on them.
     */
    public function test_subscription_filtered_dispatch_still_delivers_nothing_for_this_event(): void
    {
        /** @var list<array{url: string, headers: array<string, string>, body: string}> $calls */
        $calls = [];

        $dispatcher = $this->dispatcherWith(
            $this->fakeDb(),
            $this->fakeHttpClient($this->httpOk(), $calls)
        );

        $result = $dispatcher->dispatch($this->testEvent());

        self::assertSame([], $calls, 'dispatch() must not deliver an unsubscribed event.');
        self::assertSame(0, $result->successCount);
        self::assertSame(0, $result->failureCount);
    }

    /**
     * Signing is identical to the subscription path: the raw event JSON is the
     * body and `X-Phlix-Signature` carries the HMAC over it, keyed by the row's
     * `secret`.
     *
     * The secret comes from the dispatcher's OWN row lookup -- `listWebhooks()`
     * does not select `secret`, so a delivery driven from a listing row could
     * only ever sign with an empty key.
     */
    public function test_test_delivery_is_signed_with_the_stored_secret(): void
    {
        /** @var list<array{url: string, headers: array<string, string>, body: string}> $calls */
        $calls = [];

        $event = $this->testEvent();

        $dispatcher = $this->dispatcherWith(
            $this->fakeDb(),
            $this->fakeHttpClient($this->httpOk(), $calls)
        );

        $dispatcher->dispatchToWebhook(self::WEBHOOK_ID, $event);

        self::assertCount(1, $calls);
        self::assertSame(
            $event->getSignature(self::WEBHOOK_SECRET),
            $calls[0]['headers']['X-Phlix-Signature'] ?? null
        );
        self::assertNotSame(
            $event->getSignature(''),
            $calls[0]['headers']['X-Phlix-Signature'] ?? null,
            'A signature computed with an empty secret would mean the row lookup dropped it.'
        );
    }

    // -----------------------------------------------------------------
    // (b) Failing deliveries are reported as failures.
    // -----------------------------------------------------------------

    /**
     * An unreachable URL reports `success: false` with the transport error
     * surfaced in `failures`.
     *
     * Mutation-verified: reverting `WebhookAdminController::test()` to
     * `dispatch()` makes this return `success: true` with an empty `failures`,
     * failing here.
     */
    public function test_failing_delivery_reports_failure_with_the_error_surfaced(): void
    {
        /** @var list<array{url: string, headers: array<string, string>, body: string}> $calls */
        $calls = [];

        $dispatcher = $this->dispatcherWith(
            $this->fakeDb(),
            $this->fakeHttpClient([
                'success' => false,
                'response_code' => null,
                'response_body' => null,
                'error' => 'Could not resolve host: hooks.example.com',
            ], $calls)
        );

        $controller = new WebhookAdminController($dispatcher);
        $response = $controller->test($this->adminRequest(), ['id' => self::WEBHOOK_ID]);

        self::assertSame(200, $response->statusCode);

        $body = $this->decodeBody($response->body);
        self::assertFalse($body['success']);
        self::assertSame(0, $body['success_count']);
        self::assertSame(1, $body['failure_count']);

        self::assertIsArray($body['failures']);
        self::assertCount(1, $body['failures']);

        /** @var array<string, string> $failure */
        $failure = $body['failures'][0];
        self::assertSame(self::WEBHOOK_ID, $failure['webhook_id']);
        self::assertSame(self::WEBHOOK_URL, $failure['url']);
        self::assertStringContainsString('Could not resolve host', $failure['error']);
    }

    /**
     * An INACTIVE webhook reports failure and never attempts a delivery.
     *
     * Under `dispatch()` an inactive row was filtered out by
     * `WHERE is_active = TRUE`, producing the same empty result -- and so the
     * same fabricated `success: true`.
     */
    public function test_inactive_webhook_reports_failure_and_sends_nothing(): void
    {
        /** @var list<array{url: string, headers: array<string, string>, body: string}> $calls */
        $calls = [];

        $dispatcher = $this->dispatcherWith(
            $this->fakeDb(['is_active' => 0]),
            $this->fakeHttpClient($this->httpOk(), $calls)
        );

        $controller = new WebhookAdminController($dispatcher);
        $response = $controller->test($this->adminRequest(), ['id' => self::WEBHOOK_ID]);

        $body = $this->decodeBody($response->body);
        self::assertFalse($body['success']);
        self::assertSame(1, $body['failure_count']);

        /** @var array<int, array<string, string>> $failures */
        $failures = $body['failures'];
        self::assertStringContainsString('inactive', $failures[0]['error']);

        self::assertSame([], $calls, 'An inactive webhook must not be contacted.');
    }

    // -----------------------------------------------------------------
    // Controller-level: the endpoint reports the true outcome.
    // -----------------------------------------------------------------

    /**
     * End to end through the controller: a webhook subscribing to none of the
     * test event still gets contacted, and `success: true` is now EARNED.
     *
     * `success_count === 1` is the discriminating assertion -- the old code
     * also answered `success: true`, but with `success_count === 0` and no
     * outbound request at all.
     */
    public function test_endpoint_reports_earned_success_for_an_unsubscribed_webhook(): void
    {
        /** @var list<array{url: string, headers: array<string, string>, body: string}> $calls */
        $calls = [];

        $dispatcher = $this->dispatcherWith(
            $this->fakeDb(),
            $this->fakeHttpClient($this->httpOk(), $calls)
        );

        $controller = new WebhookAdminController($dispatcher);
        $response = $controller->test($this->adminRequest(), ['id' => self::WEBHOOK_ID]);

        self::assertSame(200, $response->statusCode);

        $body = $this->decodeBody($response->body);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['success_count']);
        self::assertSame(0, $body['failure_count']);

        self::assertCount(1, $calls, 'success: true must correspond to a real delivery.');
        self::assertSame(self::WEBHOOK_URL, $calls[0]['url']);
    }

    /**
     * A webhook id that does not exist still 404s -- the controller's
     * pre-check is unchanged.
     */
    public function test_unknown_webhook_id_still_returns_404(): void
    {
        /** @var list<array{url: string, headers: array<string, string>, body: string}> $calls */
        $calls = [];

        $dispatcher = $this->dispatcherWith(
            $this->fakeDb([], false),
            $this->fakeHttpClient($this->httpOk(), $calls)
        );

        $controller = new WebhookAdminController($dispatcher);
        $response = $controller->test($this->adminRequest(), ['id' => 'no-such-webhook']);

        self::assertSame(404, $response->statusCode);
        self::assertSame([], $calls);
    }
}
