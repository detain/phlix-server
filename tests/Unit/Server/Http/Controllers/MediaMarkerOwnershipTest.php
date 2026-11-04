<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MarkerService;
use Phlix\Media\MarkerType;
use Phlix\Server\Http\Controllers\MediaMarkerController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * minors M-SERVER-80 (W-min100): `deleteMarker` ownership law.
 *
 * Before the cure, a marker row with `user_id IS NULL` (pre-P3-S1 legacy) was
 * deletable by EVERY authenticated user — a member could erase another
 * account's (or the system's) markers on any item. The cure keeps the two
 * legitimate arms and closes the third:
 *
 *   1. the requesting user owns the marker        → delete (unchanged);
 *   2. an ACTIVE admin deletes anything           → delete (curation path);
 *   3. a NULL-owner row for a NON-admin requester → 403, row untouched.
 *
 * `MarkerService` is `final` (and doubling finals is not how this repo tests
 * anyway): the service runs REAL over the house-standard mocked
 * `Workerman\MySQL\Connection`, so the SQL it sends is the boundary under
 * test — the lookup must bind the route item id, and DELETE is recorded by
 * its bind, which is exactly what the once()/never() arms assert.
 * `ItemRepository`/`UserRepository` are mocked; `RequestContext` is the real
 * coroutine-scoped store the controller reads.
 */
final class MediaMarkerOwnershipTest extends TestCase
{
    private const ITEM_ID = 'item-1';

    /** @var list<int> marker ids the real MarkerService deleted through the mock */
    private array $deletedIds = [];

    /** @var list<mixed> bind values recorded from the create path's INSERT */
    private array $insertedParams = [];

    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        parent::tearDown();
    }

    public function testOwnerDeletesOwnMarker(): void
    {
        RequestContext::setUserId('user-1');
        $controller = $this->harness([
            $this->markerRow(7, userId: 'user-1'),
        ]);

        $response = $controller->deleteMarker(new Request(), [
            'id' => self::ITEM_ID,
            'markerId' => '7',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([7], $this->deletedIds, 'the owner arm must actually delete row 7');
    }

    public function testForeignOwnedMarkerStaysForbidden(): void
    {
        RequestContext::setUserId('user-2');
        $controller = $this->harness([
            $this->markerRow(7, userId: 'user-1'),
        ]);

        $response = $controller->deleteMarker(new Request(), [
            'id' => self::ITEM_ID,
            'markerId' => '7',
        ]);

        $this->assertSame(403, $response->statusCode);
        $this->assertSame([], $this->deletedIds, 'a foreign-owned row must never be deleted');
    }

    public function testLegacyNullOwnerMarkerIsNotDeletableByNonAdmin(): void
    {
        RequestContext::setUserId('user-1');
        $controller = $this->harness(
            [$this->markerRow(7, userId: null)],
            admin: false,
        );

        $response = $controller->deleteMarker(new Request(), [
            'id' => self::ITEM_ID,
            'markerId' => '7',
        ]);

        $this->assertSame(403, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('Admin access is required to delete a marker without an owner', $body['error']);
        $this->assertSame([], $this->deletedIds, 'the closed arm must leave the legacy row on disk');
    }

    public function testActiveAdminDeletesNullOwnerLegacyMarker(): void
    {
        RequestContext::setUserId('admin-1');
        $controller = $this->harness(
            [$this->markerRow(7, userId: null)],
            admin: true,
        );

        $response = $controller->deleteMarker(new Request(), [
            'id' => self::ITEM_ID,
            'markerId' => '7',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([7], $this->deletedIds, 'the admin curation arm must actually delete');
    }

    /**
     * 2026-11-03 review server__srphp CRITICAL (second, independent bug): the
     * old handler read `file_get_contents('php://input')` — a CGI/FPM idiom.
     * This is a Workerman CLI SAPI: the body lives on the Request, so the old
     * read always saw an empty string and createMarker could never parse a
     * real JSON body. This test drives the handler through the ROUTER CALLING
     * CONVENTION (`(Request, array $params)`) with the JSON on the Request and
     * asserts the 201 upsert actually happens — red on the old code (400
     * "Invalid JSON body"), green on the cured one.
     */
    public function testCreateMarkerParsesTheJsonBodyFromTheRequest(): void
    {
        RequestContext::setUserId('user-1');
        $controller = $this->createHarness();

        $request = new Request();
        $request->method = 'POST';
        $request->rawBody = json_encode([
            'type' => MarkerType::Chapter->value,
            'startMs' => 1234,
            'endMs' => 5678,
            'label' => 'jump',
        ], JSON_THROW_ON_ERROR);

        $response = $controller->createMarker($request, ['id' => self::ITEM_ID]);

        $this->assertSame(201, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertIsArray($body);
        $this->assertSame(self::ITEM_ID, $this->insertedParams[0] ?? null, 'route param must reach the insert');
        $this->assertSame(MarkerType::Chapter->value, $this->insertedParams[1] ?? null);
        $this->assertSame(1234, $this->insertedParams[2] ?? null, 'startMs must be parsed from the Request body');
        $this->assertSame(5678, $this->insertedParams[3] ?? null);
        $this->assertSame('jump', $this->insertedParams[4] ?? null);
        $this->assertSame('user-1', $this->insertedParams[5] ?? null, 'P3-S1 owner stamp must survive');
    }

    /**
     * The 'Invalid JSON body' 400 envelope must stay byte-identical when the
     * body really is unparseable (estate law: reuse existing envelopes).
     */
    public function testCreateMarkerStillRefusesUnparseableBodiesWithTheExistingEnvelope(): void
    {
        RequestContext::setUserId('user-1');
        $controller = $this->createHarness();

        $request = new Request();
        $request->method = 'POST';
        $request->rawBody = '{not json';

        $response = $controller->createMarker($request, ['id' => self::ITEM_ID]);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode((string) $response->body, true);
        $this->assertSame('Invalid JSON body', $body['error'] ?? null);
        $this->assertSame([], $this->insertedParams, 'a refused body must never reach the insert');
    }

    /**
     * Handlers that violate the `(Request, array)` convention TypeError at
     * dispatch; this file used to call the methods with bare strings, which is
     * exactly the blind spot the review named. The dispatch-level guard for ALL
     * rails lives in
     * {@see \Phlix\Tests\Unit\Server\Core\ApplicationRouterWirePathGuardTest::testEveryRegisteredHandlerAcceptsTheRouterCallingConvention()};
     * these tests keep calling through the same shape the router uses.
     */
    public function testMarkerHandlersDeclareTheRouterCallingConvention(): void
    {
        foreach (['createMarker', 'deleteMarker', 'getMarkers'] as $method) {
            $reflection = new \ReflectionMethod(MediaMarkerController::class, $method);
            $parameters = $reflection->getParameters();

            $this->assertGreaterThanOrEqual(2, count($parameters), $method . ' must accept (Request, params)');
            $firstType = $parameters[0]->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $firstType, $method . ' param 1 must be typed');
            $this->assertSame(Request::class, (string) $firstType, $method . ' param 1 must be Request');
            $secondType = $parameters[1]->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $secondType, $method . ' param 2 must be typed');
            $this->assertSame('array', (string) $secondType, $method . ' param 2 must be array');
        }
    }

    /**
     * Real MarkerService over the house-mocked Connection, plus an
     * ItemRepository stub that finds self::ITEM_ID, for the CREATE arm.
     */
    private function createHarness(): MediaMarkerController
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param mixed $params
             * @return mixed
             */
            function (string $sql, $params = null) {
                if (str_contains($sql, 'INSERT INTO media_markers')) {
                    $this->insertedParams = array_values((array) $params);

                    return 1;
                }
                if (str_contains($sql, 'SELECT * FROM media_markers WHERE id')) {
                    return [$this->markerRow(7, userId: 'user-1')];
                }
                $this->fail('unexpected SQL reached by the create path: ' . $sql);
            }
        );
        $db->method('lastInsertId')->willReturn('7');

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with(self::ITEM_ID)->willReturn(['id' => self::ITEM_ID]);

        return new MediaMarkerController(
            new MarkerService($db),
            $items,
            $this->createMock(UserRepository::class),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows rows the lookup SELECT returns
     */
    private function harness(array $rows, bool $admin = false): MediaMarkerController
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param mixed $params */
            function (string $sql, $params = null) use ($rows) {
                if (str_contains($sql, 'SELECT * FROM media_markers WHERE media_item_id')) {
                    $this->assertSame([self::ITEM_ID], $params, 'the lookup must bind the route item id');

                    return $rows;
                }
                if (str_contains($sql, 'DELETE FROM media_markers WHERE id')) {
                    $this->assertCount(1, (array) $params, 'delete must be a single-id statement');
                    $this->deletedIds[] = (int) $params[0];

                    return 1;
                }
                $this->fail('unexpected SQL reached by the ownership path: ' . $sql);
            }
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')
            ->willReturn($admin ? ['id' => 'admin-1'] : null);

        return new MediaMarkerController(
            new MarkerService($db),
            $this->createMock(ItemRepository::class),
            $users,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function markerRow(int $id, ?string $userId): array
    {
        return [
            'id' => $id,
            'media_item_id' => self::ITEM_ID,
            'marker_type' => MarkerType::Chapter->value,
            'start_time_ms' => 0,
            'end_time_ms' => 1000,
            'label' => 'm',
            'user_id' => $userId,
            'thumbnail_path' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }
}
