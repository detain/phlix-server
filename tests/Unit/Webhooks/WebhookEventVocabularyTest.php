<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use Phlix\Common\Net\SsrfGuard;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Server\Http\Controllers\Admin\AdminWebhooksController;
use Phlix\Server\Http\Request;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Phlix\Webhooks\WebhookEventCatalog;
use Phlix\Webhooks\WebhookHttpClient;
use Phlix\Webhooks\WebhookService;
use Phlix\Webhooks\WebhookEventSubscriber;
use Phlix\Webhooks\WebhookSubscriptionRecord;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;
use Workerman\Worker;

/**
 * R5-03 — the webhook event vocabulary, end to end on the REAL delivery path.
 *
 * Before this fix the live emitter (WebhookEventSubscriber via WebhookService
 * / System B `webhook_subscriptions`) emitted media-dot and user-dot names
 * while the admin picker catalogue (phlix-shared webhook-events.json, mirrored
 * by the SPA) offered playback-dot / library-dot names — and the strict
 * in_array routing in
 * WebhookSubscriptionRecord::handlesEvent() meant a UI-created subscription
 * could NEVER receive a real domain event. The AdminWebhooksController
 * validator meanwhile admitted a THIRD, entirely fictional vocabulary
 * (media.scanned, transcode.*, user.watched, syncplay.* — nothing emitted any
 * of them). These tests drive the real subscriber -> real WebhookService ->
 * real subscription matching (no mocks that re-encode the bug): the assertion
 * target is the recorded INSERT parameter shape on a faked Connection.
 */
final class WebhookEventVocabularyTest extends TestCase
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $queries = [];

    /** @var list<array<string, mixed>> */
    private array $subscriptionRows = [];

    /** @var array<string, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = [];
        $this->subscriptionRows = [];

        // queueDeliveries() arms a one-shot Workerman Timer per matching
        // subscription; the callback never runs here (no event loop), but
        // Timer::add() needs a registered worker to exist — same harness the
        // pre-existing WebhookServiceTest uses.
        $this->savedWorkers = Worker::getAllWorkers();
        if ($this->savedWorkers === []) {
            new Worker();
        }

        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        SsrfGuard::reset();

        $prop = new \ReflectionProperty(Worker::class, 'workers');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->savedWorkers);

        parent::tearDown();
    }

    /**
     * @param list<string> $events
     * @return array<string, mixed>
     */
    private function subscriptionRow(array $events, bool $active = true, int $id = 3): array
    {
        return [
            'id' => $id,
            'url' => 'https://hooks.example.test/ingest',
            'events' => json_encode($events, JSON_THROW_ON_ERROR),
            'is_active' => $active,
            'created_at' => '2026-10-01 00:00:00',
            'updated_at' => '2026-10-01 00:00:00',
        ];
    }

    private function fakeConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('query')->willReturnCallback(
            function (string $sql, mixed $params = []): array {
                $this->queries[] = ['sql' => $sql, 'params' => is_array($params) ? $params : []];
                if (str_contains($sql, 'FROM webhook_subscriptions')) {
                    return $this->subscriptionRows;
                }
                return [];
            }
        );
        $connection->method('lastInsertId')->willReturn('7');

        return $connection;
    }

    private function service(Connection $connection): WebhookService
    {
        return new WebhookService($connection, $this->createMock(WebhookHttpClient::class));
    }

    private function subscriber(WebhookService $service): WebhookEventSubscriber
    {
        return new WebhookEventSubscriber($service, new \Phlix\Common\Events\ListenerRegistry());
    }

    /**
     * @return list<list<mixed>> the bind params of every delivery INSERT
     */
    private function deliveryInsertParams(): array
    {
        $params = [];
        foreach ($this->queries as $query) {
            if (str_contains($query['sql'], 'INSERT INTO webhook_deliveries')) {
                $params[] = $query['params'];
            }
        }

        return $params;
    }

    /**
     * @return list<list<mixed>> the bind params of every event INSERT
     */
    private function eventInsertParams(): array
    {
        $params = [];
        foreach ($this->queries as $query) {
            if (str_contains($query['sql'], 'INSERT INTO webhook_events')) {
                $params[] = $query['params'];
            }
        }

        return $params;
    }

    /**
     * THE core regression: a subscription created with the UI-catalogue name
     * `playback.started` must actually receive a real PlaybackStarted domain
     * event. Pre-fix this produced ZERO deliveries (emitter said
     * `media.started`; the strict in_array gate never matched).
     */
    public function testPlaybackStartedSubscriberEmitQueuesDeliveryToCatalogueSubscription(): void
    {
        $this->subscriptionRows = [$this->subscriptionRow(['playback.started'])];
        $subscriber = $this->subscriber($this->service($this->fakeConnection()));

        $subscriber->onPlaybackStarted(new PlaybackStarted('sess-1', 'u-1', 'm-1', 'd-1', 0));

        $eventParams = $this->eventInsertParams();
        $this->assertCount(1, $eventParams, 'the domain event must be persisted once');
        $this->assertSame('playback.started', $eventParams[0][0], 'the emitter must speak the catalogue name');

        $deliveries = $this->deliveryInsertParams();
        $this->assertCount(1, $deliveries, 'the catalogue-named subscription must receive exactly one queued delivery');
        $this->assertSame('https://hooks.example.test/ingest', $deliveries[0][1]);
    }

    /**
     * Both PlaybackStopped arms collapse onto the single catalogue event
     * `playback.ended` (the wire truth defines it as "finished OR stopped");
     * the reached_end payload bool is what keeps the distinction alive.
     */
    public function testPlaybackStoppedBothArmsEmitCatalogueNameWithReachedEndPayload(): void
    {
        $this->subscriptionRows = [$this->subscriptionRow(['playback.ended'])];
        $subscriber = $this->subscriber($this->service($this->fakeConnection()));

        $subscriber->onPlaybackStopped(new PlaybackStopped('sess-1', 'u-1', 'm-1', 'd-1', 900, true));
        $subscriber->onPlaybackStopped(new PlaybackStopped('sess-1', 'u-1', 'm-1', 'd-1', 400, false));

        $eventParams = $this->eventInsertParams();
        $this->assertCount(2, $eventParams);
        $this->assertSame('playback.ended', $eventParams[0][0]);
        $this->assertSame('playback.ended', $eventParams[1][0]);

        $this->assertStringContainsString('"reached_end":true', (string) $eventParams[0][1]);
        $this->assertStringContainsString('"reached_end":false', (string) $eventParams[1][1]);

        $this->assertCount(2, $this->deliveryInsertParams(), 'both arms must reach the playback.ended subscriber');
    }

    public function testMediaItemAddedEmitsCatalogueNameAndDelivers(): void
    {
        $this->subscriptionRows = [$this->subscriptionRow(['library.updated'])];
        $subscriber = $this->subscriber($this->service($this->fakeConnection()));

        $subscriber->onMediaItemAdded(new MediaItemAdded('m-1', 'lib-1', '/media/x.mkv', 'movie'));

        $eventParams = $this->eventInsertParams();
        $this->assertCount(1, $eventParams);
        $this->assertSame('library.updated', $eventParams[0][0]);
        $this->assertCount(1, $this->deliveryInsertParams());
    }

    public function testUserEventsKeepTheirNamesAndDeliverToUserSubscriptions(): void
    {
        $this->subscriptionRows = [$this->subscriptionRow(['user.login'])];
        $subscriber = $this->subscriber($this->service($this->fakeConnection()));

        $subscriber->onUserLoggedIn(new UserLoggedIn('u-1', 'sess-1', '203.0.113.9', 'PhlixTest/1.0'));

        $eventParams = $this->eventInsertParams();
        $this->assertCount(1, $eventParams);
        $this->assertSame('user.login', $eventParams[0][0]);
        $this->assertCount(1, $this->deliveryInsertParams());
    }

    /**
     * Migration-free law: rows stored under the OLD media.* names keep
     * receiving the renamed canonical events through the alias map.
     */
    public function testLegacyStoredSubscriptionsStillReceiveRenamedEvents(): void
    {
        $this->subscriptionRows = [
            $this->subscriptionRow(['media.started'], id: 3),
            $this->subscriptionRow(['media.played'], id: 4),
            $this->subscriptionRow(['media.stopped'], id: 5),
        ];
        $subscriber = $this->subscriber($this->service($this->fakeConnection()));

        $subscriber->onPlaybackStarted(new PlaybackStarted('s', 'u', 'm', 'd', 0));
        $subscriber->onPlaybackStopped(new PlaybackStopped('s', 'u', 'm', 'd', 10, false));

        // event 1 (playback.started) -> media.started row only;
        // event 2 (playback.ended)   -> media.played AND media.stopped rows.
        $this->assertCount(3, $this->deliveryInsertParams());
    }

    public function testHandlesEventAliasAndNegativePins(): void
    {
        $legacy = WebhookSubscriptionRecord::fromRow($this->subscriptionRow(['media.added']));
        $this->assertTrue($legacy->handlesEvent('library.updated'), 'legacy media.added rows match canonical library.updated');

        $catalogue = WebhookSubscriptionRecord::fromRow($this->subscriptionRow(['playback.started']));
        $this->assertTrue($catalogue->handlesEvent('playback.started'));
        $this->assertFalse($catalogue->handlesEvent('user.login'), 'distinct catalogue names must not cross-match');

        $inactive = WebhookSubscriptionRecord::fromRow($this->subscriptionRow(['playback.started'], active: false));
        $this->assertFalse($inactive->handlesEvent('playback.started'), 'the isActive gate still holds through the rewrite');
    }

    /**
     * The AdminWebhooksController boundary parses legacy input into canonical
     * storage (parse, don't validate) and rejects the fictional vocabulary the
     * old validator admitted plus the internal-only webhook.test name.
     */
    public function testAdminControllerStoresCanonicalRejectsFictionAndInternalNames(): void
    {
        $connection = $this->fakeConnection();
        $controller = new AdminWebhooksController($this->service($connection));
        // listSubscriptions() reads back the rows after the INSERT; seed the
        // row the id-mock (lastInsertId 7) will resolve to.
        $this->subscriptionRows = [
            $this->subscriptionRow(['playback.started', 'user.login'], id: 7),
        ];

        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [
            'url' => 'https://hooks.example.test/ingest',
            'events' => ['media.started', 'user.login'],
        ];

        $response = $controller->createSubscription($request, []);
        $this->assertSame(201, $response->statusCode);

        $inserts = [];
        foreach ($this->queries as $query) {
            if (str_contains($query['sql'], 'INSERT INTO webhook_subscriptions')) {
                $inserts[] = $query['params'];
            }
        }
        $this->assertCount(1, $inserts);
        $this->assertSame(
            json_encode(['playback.started', 'user.login'], JSON_THROW_ON_ERROR),
            $inserts[0][1],
            'legacy input must be normalised to canonical names at the boundary',
        );

        $body = json_decode((string) $response->body, true);
        $this->assertTrue(is_array($body));
        /** @var array{data?: array{subscription?: array{events?: list<string>}}} $body */
        $this->assertSame(['playback.started', 'user.login'], $body['data']['subscription']['events'] ?? null);

        // Fictional (pre-R5-03) vocabulary is now a 400.
        $fiction = new Request();
        $fiction->userId = 'admin-1';
        $fiction->body = ['url' => 'https://hooks.example.test/ingest', 'events' => ['transcode.completed']];
        $fictionQueries = count($this->queries);
        $this->assertSame(400, $controller->createSubscription($fiction, [])->statusCode);
        foreach (array_slice($this->queries, $fictionQueries) as $query) {
            $this->assertStringNotContainsString('INSERT INTO webhook_subscriptions', $query['sql']);
        }

        // The internal test-only event is not user-subscribable.
        $testName = new Request();
        $testName->userId = 'admin-1';
        $testName->body = ['url' => 'https://hooks.example.test/ingest', 'events' => ['webhook.test']];
        $this->assertSame(400, $controller->createSubscription($testName, [])->statusCode);

        $empty = new Request();
        $empty->userId = 'admin-1';
        $empty->body = ['url' => 'https://hooks.example.test/ingest', 'events' => []];
        $this->assertSame(400, $controller->createSubscription($empty, [])->statusCode);
    }

    /**
     * Literal tripwires on the catalogue SSOT: the EVENTS list mirrors the
     * phlix-shared wire truth (webhook-events.json) plus the three live
     * user.* names, and the SPA picker in @phlix/ui mirrors this same list —
     * any drift here reddens this pin first.
     */
    public function testCatalogueLiteralsArePinned(): void
    {
        $this->assertSame([
            'playback.started',
            'playback.ended',
            'library.updated',
            'download.complete',
            'recording.started',
            'recording.stopped',
            'alert',
            'user.login',
            'user.logout',
            'user.created',
        ], WebhookEventCatalog::EVENTS);

        $this->assertSame([
            'media.started' => 'playback.started',
            'media.played' => 'playback.ended',
            'media.stopped' => 'playback.ended',
            'media.added' => 'library.updated',
        ], WebhookEventCatalog::LEGACY_ALIASES);

        $this->assertSame('alert', WebhookEventCatalog::normalize('alert'), 'canonical names pass through untouched');
        $this->assertSame('brand.new', WebhookEventCatalog::normalize('brand.new'), 'unknown strings pass through (deny happens elsewhere)');
        $this->assertFalse(WebhookEventCatalog::isSubscribable('webhook.test'), 'test events are never subscribable');
        $this->assertTrue(WebhookEventCatalog::isAccepted('media.played'));
        $this->assertFalse(WebhookEventCatalog::isAccepted('media.invented'));
    }
}
