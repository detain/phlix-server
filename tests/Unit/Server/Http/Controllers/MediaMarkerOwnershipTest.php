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

        $response = $controller->deleteMarker(self::ITEM_ID, '7');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([7], $this->deletedIds, 'the owner arm must actually delete row 7');
    }

    public function testForeignOwnedMarkerStaysForbidden(): void
    {
        RequestContext::setUserId('user-2');
        $controller = $this->harness([
            $this->markerRow(7, userId: 'user-1'),
        ]);

        $response = $controller->deleteMarker(self::ITEM_ID, '7');

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

        $response = $controller->deleteMarker(self::ITEM_ID, '7');

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

        $response = $controller->deleteMarker(self::ITEM_ID, '7');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([7], $this->deletedIds, 'the admin curation arm must actually delete');
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
