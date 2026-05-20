<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Markers\ChapterMarker;
use Phlix\Media\Markers\IntroMarker;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\MarkerSet;
use Phlix\Media\Markers\OutroMarker;
use Phlix\Media\Markers\SkipButtonSpec;
use Phlix\Server\Http\Controllers\SessionController;
use Phlix\Server\Http\Request;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;

/**
 * Tests for SessionController marker handling via getProgress()
 */
class SessionControllerTest extends TestCase
{
    /**
     * Test that getProgress includes marker data when session has a media item with markers.
     * Verifies: Positive case - markers are properly returned when playing media with markers.
     */
    public function testGetProgressIncludesMarkerDataWhenSessionHasMediaItemWithMarkers(): void
    {
        $sessionId = 'session-123';
        $userId = 'user-456';
        $mediaItemId = 'media-789';

        // Mock SessionManager
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => 'device-abc',
        ]);

        // Mock PlaybackController
        $playbackController = $this->createMock(PlaybackController::class);
        $playbackController->method('getPlaybackState')->willReturn([
            'session_id' => $sessionId,
            'media_item_id' => $mediaItemId,
            'position_ticks' => 12000000000,
            'duration_ticks' => 36000000000,
            'playback_status' => 'playing',
        ]);

        // Create marker set with intro, outro, and chapters
        $markerSet = new MarkerSet(
            new IntroMarker(10, 100, 100),
            new OutroMarker(2200, 2400, 100),
            [
                new ChapterMarker(0, 120, 'Opening'),
                new ChapterMarker(120, 300, 'Scene 1'),
            ]
        );

        // Mock MarkerService
        $markerService = $this->createMock(MarkerService::class);
        $markerService->method('getMarkers')->with($mediaItemId)->willReturn($markerSet);

        // Create controller with mocked dependencies
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        // Create request with authenticated user
        $request = new Request();
        $request->userId = $userId;

        // Call getProgress
        $response = $controller->getProgress($request, ['id' => $sessionId]);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify progress data is present
        $this->assertArrayHasKey('progress', $body);
        $this->assertEquals($mediaItemId, $body['progress']['media_item_id']);

        // Verify intro marker
        $this->assertArrayHasKey('intro_marker', $body);
        $this->assertNotNull($body['intro_marker']);
        $this->assertEquals(10, $body['intro_marker']['start_seconds']);
        $this->assertEquals(100, $body['intro_marker']['end_seconds']);

        // Verify outro marker
        $this->assertArrayHasKey('outro_marker', $body);
        $this->assertNotNull($body['outro_marker']);
        $this->assertEquals(2200, $body['outro_marker']['start_seconds']);
        $this->assertEquals(2400, $body['outro_marker']['end_seconds']);

        // Verify skip_button_spec
        $this->assertArrayHasKey('skip_button_spec', $body);
        $skipSpec = $body['skip_button_spec'];
        $this->assertEquals(10, $skipSpec['skip_intro_start']);
        $this->assertEquals(100, $skipSpec['skip_intro_end']);
        $this->assertEquals(2200, $skipSpec['skip_outro_start']);
        $this->assertEquals(2400, $skipSpec['skip_outro_end']);

        // Verify chapters
        $this->assertArrayHasKey('chapters', $body);
        $this->assertCount(2, $body['chapters']);
        $this->assertEquals(0, $body['chapters'][0]['start_seconds']);
        $this->assertEquals(120, $body['chapters'][0]['end_seconds']);
        $this->assertEquals('Opening', $body['chapters'][0]['title']);
    }

    /**
     * Test that getProgress returns null/empty markers when item has no markers.
     * Verifies: Negative case - empty marker set returns null/empty values.
     */
    public function testGetProgressReturnsNullMarkersWhenItemHasNoMarkers(): void
    {
        $sessionId = 'session-123';
        $userId = 'user-456';
        $mediaItemId = 'media-789';

        // Mock SessionManager
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => 'device-abc',
        ]);

        // Mock PlaybackController
        $playbackController = $this->createMock(PlaybackController::class);
        $playbackController->method('getPlaybackState')->willReturn([
            'session_id' => $sessionId,
            'media_item_id' => $mediaItemId,
            'position_ticks' => 12000000000,
            'duration_ticks' => 36000000000,
            'playback_status' => 'playing',
        ]);

        // Mock MarkerService returns empty marker set
        $markerService = $this->createMock(MarkerService::class);
        $markerService->method('getMarkers')->with($mediaItemId)->willReturn(MarkerSet::empty());

        // Create controller with mocked dependencies
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        // Create request with authenticated user
        $request = new Request();
        $request->userId = $userId;

        // Call getProgress
        $response = $controller->getProgress($request, ['id' => $sessionId]);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify all markers are null/empty
        $this->assertNull($body['intro_marker']);
        $this->assertNull($body['outro_marker']);
        $this->assertEmpty($body['chapters']);

        // Verify skip_button_spec has null values
        $skipSpec = $body['skip_button_spec'];
        $this->assertNull($skipSpec['skip_intro_start']);
        $this->assertNull($skipSpec['skip_intro_end']);
        $this->assertNull($skipSpec['skip_outro_start']);
        $this->assertNull($skipSpec['skip_outro_end']);
    }

    /**
     * Test that buildMarkerData handles null mediaItemId correctly.
     * Verifies: Edge case - null mediaItemId returns empty marker structure without calling MarkerService.
     */
    public function testBuildMarkerDataHandlesNullMediaItemIdCorrectly(): void
    {
        $sessionId = 'session-123';
        $userId = 'user-456';

        // Mock SessionManager
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => 'device-abc',
        ]);

        // Mock PlaybackController returns state without media_item_id
        $playbackController = $this->createMock(PlaybackController::class);
        $playbackController->method('getPlaybackState')->willReturn([
            'session_id' => $sessionId,
            'media_item_id' => null, // No media item
            'position_ticks' => 0,
            'duration_ticks' => 0,
            'playback_status' => 'stopped',
        ]);

        // MarkerService should NOT be called with null mediaItemId
        $markerService = $this->createMock(MarkerService::class);
        $markerService->expects($this->never())->method('getMarkers');

        // Create controller with mocked dependencies
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        // Create request with authenticated user
        $request = new Request();
        $request->userId = $userId;

        // Call getProgress
        $response = $controller->getProgress($request, ['id' => $sessionId]);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify progress is present but markers are empty
        $this->assertArrayHasKey('progress', $body);
        $this->assertNull($body['intro_marker']);
        $this->assertNull($body['outro_marker']);
        $this->assertEmpty($body['chapters']);
    }

    /**
     * Test that constructor properly accepts MarkerService as 3rd argument.
     * Verifies: Construction - controller instantiates with all three dependencies.
     */
    public function testConstructorProperlyAcceptsMarkerServiceAsThirdArgument(): void
    {
        // Mock all three dependencies
        $sessionManager = $this->createMock(SessionManager::class);
        $playbackController = $this->createMock(PlaybackController::class);
        $markerService = $this->createMock(MarkerService::class);

        // This should not throw an exception
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        $this->assertInstanceOf(SessionController::class, $controller);
    }

    /**
     * Test that getProgress returns 401 when user is not authenticated.
     * Verifies: Negative case - unauthenticated request is rejected.
     */
    public function testGetProgressReturns401WhenUserNotAuthenticated(): void
    {
        $sessionId = 'session-123';

        // Mock SessionManager
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'id' => $sessionId,
            'user_id' => 'user-456',
            'device_id' => 'device-abc',
        ]);

        // Mock PlaybackController
        $playbackController = $this->createMock(PlaybackController::class);
        $playbackController->method('getPlaybackState')->willReturn([
            'session_id' => $sessionId,
            'media_item_id' => 'media-789',
            'position_ticks' => 12000000000,
            'duration_ticks' => 36000000000,
            'playback_status' => 'playing',
        ]);

        // Mock MarkerService
        $markerService = $this->createMock(MarkerService::class);

        // Create controller
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        // Create request WITHOUT authenticated user
        $request = new Request();
        // request->userId is not set

        // Call getProgress
        $response = $controller->getProgress($request, ['id' => $sessionId]);

        $this->assertEquals(403, $response->statusCode);
    }

    /**
     * Test that getProgress returns 404 when session is not found.
     * Verifies: Negative case - missing session returns 404.
     */
    public function testGetProgressReturns404WhenSessionNotFound(): void
    {
        $sessionId = 'non-existent-session';

        // Mock SessionManager returns null (session not found)
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn(null);

        // Mock PlaybackController (should not be called)
        $playbackController = $this->createMock(PlaybackController::class);

        // Mock MarkerService (should not be called)
        $markerService = $this->createMock(MarkerService::class);

        // Create controller
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        // Create request with authenticated user
        $request = new Request();
        $request->userId = 'user-456';

        // Call getProgress
        $response = $controller->getProgress($request, ['id' => $sessionId]);

        $this->assertEquals(404, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Session not found', $body['error']);
    }

    /**
     * Test that getProgress returns null progress when playback state is null.
     * Verifies: Edge case - session exists but no playback state recorded.
     */
    public function testGetProgressReturnsNullProgressWhenNoPlaybackState(): void
    {
        $sessionId = 'session-123';
        $userId = 'user-456';

        // Mock SessionManager
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => 'device-abc',
        ]);

        // Mock PlaybackController returns null (no playback state)
        $playbackController = $this->createMock(PlaybackController::class);
        $playbackController->method('getPlaybackState')->willReturn(null);

        // Mock MarkerService (should not be called)
        $markerService = $this->createMock(MarkerService::class);
        $markerService->expects($this->never())->method('getMarkers');

        // Create controller
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        // Create request with authenticated user
        $request = new Request();
        $request->userId = $userId;

        // Call getProgress
        $response = $controller->getProgress($request, ['id' => $sessionId]);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertNull($body['progress']);
    }

    /**
     * Test that getProgress returns 403 when user does not own the session.
     * Verifies: Negative case - accessing another user's session is forbidden.
     */
    public function testGetProgressReturns403WhenUserDoesNotOwnSession(): void
    {
        $sessionId = 'session-123';

        // Mock SessionManager - session belongs to different user
        $sessionManager = $this->createMock(SessionManager::class);
        $sessionManager->method('getSession')->willReturn([
            'id' => $sessionId,
            'user_id' => 'other-user-999',
            'device_id' => 'device-abc',
        ]);

        // Mock PlaybackController
        $playbackController = $this->createMock(PlaybackController::class);

        // Mock MarkerService
        $markerService = $this->createMock(MarkerService::class);

        // Create controller
        $controller = new SessionController($sessionManager, $playbackController, $markerService);

        // Create request with different authenticated user
        $request = new Request();
        $request->userId = 'user-456'; // Not the session owner

        // Call getProgress
        $response = $controller->getProgress($request, ['id' => $sessionId]);

        $this->assertEquals(403, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Forbidden', $body['error']);
    }
}
