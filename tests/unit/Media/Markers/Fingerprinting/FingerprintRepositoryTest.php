<?php

namespace Phlex\Tests\Unit\Media\Markers\Fingerprinting;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\Fingerprinting\FingerprintRepository;
use Workerman\MySQL\Connection;

class FingerprintRepositoryTest extends TestCase
{
    public function testStoreAndRetrieveFingerprint(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            // storeFingerprint: findById
            [
                [
                    'id' => 'media-1',
                    'name' => 'Episode 1',
                    'type' => 'episode',
                    'library_id' => 'lib-1',
                    'path' => '/shows/test/s01e01.mkv',
                    'metadata_json' => '{}',
                ]
            ],
            // storeFingerprint: update (UPDATE returns void, so [])
            [],
            // getFingerprint: findById
            [
                [
                    'id' => 'media-1',
                    'name' => 'Episode 1',
                    'type' => 'episode',
                    'library_id' => 'lib-1',
                    'path' => '/shows/test/s01e01.mkv',
                    'metadata_json' => '{"fingerprint": "fingerprint-data-123"}',
                ]
            ],
        );

        $itemRepo = new ItemRepository($db);
        $repo = new FingerprintRepository($itemRepo);

        $repo->storeFingerprint('media-1', 'fingerprint-data-123');

        $fingerprint = $repo->getFingerprint('media-1');
        $this->assertEquals('fingerprint-data-123', $fingerprint);
    }

    public function testGetReturnsEmptyStringWhenMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $repo = new FingerprintRepository($itemRepo);

        $fingerprint = $repo->getFingerprint('nonexistent-id');

        $this->assertIsString($fingerprint);
        $this->assertEquals('', $fingerprint);
    }

    public function testGetFingerprintedIdsForShow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'ep-1',
                'name' => 'Episode 1',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e01.mkv',
                'metadata_json' => '{"fingerprint": "fp1"}',
            ],
            [
                'id' => 'ep-2',
                'name' => 'Episode 2',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e02.mkv',
                'metadata_json' => '{}',
            ],
            [
                'id' => 'ep-3',
                'name' => 'Episode 3',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e03.mkv',
                'metadata_json' => '{"fingerprint": "fp3"}',
            ],
        ]);

        $itemRepo = new ItemRepository($db);
        $repo = new FingerprintRepository($itemRepo);

        $result = $repo->getFingerprintedIdsForShow('show-1');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains('ep-1', $result);
        $this->assertContains('ep-3', $result);
        $this->assertNotContains('ep-2', $result);
    }

    public function testStoreFingerprintThrowsOnNonexistentItem(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $repo = new FingerprintRepository($itemRepo);

        $this->expectException(\InvalidArgumentException::class);
        $repo->storeFingerprint('nonexistent-id', 'fingerprint');
    }

    public function testStoreFingerprintUpdatesMetadata(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [
                    [
                        'id' => 'media-1',
                        'name' => 'Test Episode',
                        'type' => 'episode',
                        'library_id' => 'lib-1',
                        'parent_id' => 'show-1',
                        'path' => '/shows/test/s01e01.mkv',
                        'metadata_json' => '{"existing_key": "existing_value"}',
                    ]
                ],
                []
            );

        $itemRepo = new ItemRepository($db);
        $repo = new FingerprintRepository($itemRepo);

        $repo->storeFingerprint('media-1', 'new-fingerprint');

        $this->assertTrue(true);
    }
}
