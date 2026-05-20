<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Server\Http\Controllers\MarkerController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Workerman\MySQL\Connection;

class MarkerControllerTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    public function testGetMarkersReturns200(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 100,
            'outro_start_seconds' => 2200,
            'outro_end_seconds' => 2400,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getMarkers($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('intro', $body);
        $this->assertArrayHasKey('outro', $body);
        $this->assertArrayHasKey('chapters', $body);
    }

    public function testGetMarkersReturnsEmptySetWhenNotFound(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getMarkers($request, ['id' => 'non-existent']);

        // MarkerSet can be empty but returns 200
        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertNull($body['intro']);
        $this->assertNull($body['outro']);
        $this->assertEmpty($body['chapters']);
    }

    public function testGetIntroMarker(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 100,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getIntroMarker($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals(10, $body['start']);
        $this->assertEquals(100, $body['end']);
    }

    public function testGetIntroMarkerReturns404WhenNotFound(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getIntroMarker($request, ['id' => 'ep-1']);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetOutroMarker(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => 2200,
            'outro_end_seconds' => 2400,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getOutroMarker($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals(2200, $body['start']);
        $this->assertEquals(2400, $body['end']);
    }

    public function testGetOutroMarkerReturns404WhenNotFound(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getOutroMarker($request, ['id' => 'ep-1']);

        $this->assertEquals(404, $response->statusCode);
    }

    public function testGetShowMarkersBulk(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturnCallback(function ($sql, $params) {
            if (strpos($sql, 'parent_id') !== false) {
                return [
                    [
                        'id' => 'ep-1',
                        'name' => 'Episode 1',
                        'type' => 'episode',
                        'library_id' => 'lib-1',
                        'parent_id' => 'show-1',
                        'path' => '/test/ep1.mkv',
                        'metadata_json' => json_encode([]),
                        'intro_start_seconds' => 10,
                        'intro_end_seconds' => 100,
                        'outro_start_seconds' => null,
                        'outro_end_seconds' => null,
                        'chapters_json' => null,
                    ],
                ];
            }
            return [[
                'id' => $params[0],
                'name' => 'Episode',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/test/ep.mkv',
                'metadata_json' => json_encode([]),
                'intro_start_seconds' => 10,
                'intro_end_seconds' => 100,
                'outro_start_seconds' => null,
                'outro_end_seconds' => null,
                'chapters_json' => null,
            ]];
        });

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getShowMarkers($request, ['id' => 'show-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertEquals('show-1', $body['show_id']);
        $this->assertIsArray($body['episodes']);
        $this->assertCount(1, $body['episodes']);
        $this->assertEquals('ep-1', $body['episodes'][0]['id']);
    }

    public function testGetMarkersRequiresId(): void
    {
        $db = $this->createMockConnection();
        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getMarkers($request, []);  // No ID

        $this->assertEquals(400, $response->statusCode);
    }

    public function testGetIntroMarkerRequiresId(): void
    {
        $db = $this->createMockConnection();
        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getIntroMarker($request, []);  // No ID

        $this->assertEquals(400, $response->statusCode);
    }

    public function testGetShowMarkersRequiresId(): void
    {
        $db = $this->createMockConnection();
        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getShowMarkers($request, []);  // No ID

        $this->assertEquals(400, $response->statusCode);
    }

    public function testGetOutroMarkerRequiresId(): void
    {
        $db = $this->createMockConnection();
        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MarkerController($markerService);

        $request = new Request();
        $response = $controller->getOutroMarker($request, []);  // No ID

        $this->assertEquals(400, $response->statusCode);
    }
}
