<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server;

use Phlix\Auth\AuthManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Markers\SkipButtonSpec;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * The playback-info logic is DUPLICATED across two dispatch paths:
 * MediaItemController::getPlaybackInfo() (`GET /api/v1/media/{id}/playback-info`)
 * and WebPortalRouter::getPlaybackInfo() (`GET /api/v1/media/{id}/playback`).
 * Their envelopes differ historically, but the P3B track-selection metadata
 * (`audio_tracks` / `subtitle_tracks`) MUST be byte-identical — both are shaped
 * by the shared {@see \Phlix\Media\Library\StreamTrackShaper}. This test pins
 * that parity so the duplication can't silently drift again.
 */
class PlaybackInfoTracksParityTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function streamRows(): array
    {
        return [
            ['id' => 's-v0', 'stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264', 'language' => null, 'bitrate' => 6000000],
            ['id' => 's-a0', 'stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac', 'language' => 'eng', 'bitrate' => 128000],
            ['id' => 's-a1', 'stream_index' => 2, 'stream_type' => 'audio', 'codec' => 'ac3', 'language' => 'fre', 'bitrate' => 384000],
            ['id' => 's-s0', 'stream_index' => 3, 'stream_type' => 'subtitle', 'codec' => 'subrip', 'language' => 'eng', 'bitrate' => null],
            ['id' => 's-s1', 'stream_index' => 4, 'stream_type' => 'subtitle', 'codec' => 'hdmv_pgs_subtitle', 'language' => 'eng', 'bitrate' => null],
            ['id' => 's-s2', 'stream_index' => 5, 'stream_type' => 'subtitle', 'codec' => 'ass', 'language' => 'jpn', 'title' => 'Signs', 'bitrate' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(): array
    {
        return [
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
        ];
    }

    /**
     * A DB mock returning the item row for media_items queries and the stream
     * rows for media_streams queries.
     */
    private function dbMock(): Connection
    {
        $itemRow = $this->itemRow();
        $streamRows = $this->streamRows();
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'media_streams') ? $streamRows : [$itemRow]
        );

        return $db;
    }

    public function testBothDispatchPathsEmitIdenticalTrackShapes(): void
    {
        // API path: MediaItemController::getPlaybackInfo().
        $itemRepo = new ItemRepository($this->dbMock());
        $markerService = new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo));
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));
        $controller = new MediaItemController(
            $itemRepo,
            $markerService,
            $gapless,
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($this->createMock(Connection::class)),
        );
        /** @var array{audio_tracks: mixed, subtitle_tracks: mixed} $apiBody */
        $apiBody = json_decode($controller->getPlaybackInfo(new Request(), ['id' => 'ep-1'])->body, true);

        // Portal path: WebPortalRouter::getPlaybackInfo().
        $portalItemRepo = new ItemRepository($this->dbMock());
        $playbackMarkers = $this->createMock(PlaybackMarkerService::class);
        $playbackMarkers->method('getFullSpec')->willReturn(new SkipButtonSpec(null, null, null, null));
        $router = new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $portalItemRepo,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $playbackMarkers,
            $this->createMock(MarkerService::class),
        );
        /** @var array{playback_info: array{audio_tracks: mixed, subtitle_tracks: mixed}} $portalBody */
        $portalBody = json_decode($router->getPlaybackInfo(new Request(), ['id' => 'ep-1'])->body, true);
        $portalInfo = $portalBody['playback_info'];

        $this->assertNotSame([], $apiBody['audio_tracks'], 'fixture must produce audio tracks');
        $this->assertNotSame([], $apiBody['subtitle_tracks'], 'fixture must produce subtitle tracks');

        // Signed subtitle URLs embed a freshly-minted expiry, so parity is
        // asserted with the volatile exp/sig stripped — everything else must be
        // byte-identical between the two dispatch paths.
        $this->assertSame(
            $this->stripUrlSignatures($apiBody['audio_tracks']),
            $this->stripUrlSignatures($portalInfo['audio_tracks']),
            'audio_tracks must be identical across both dispatch paths',
        );
        $this->assertSame(
            $this->stripUrlSignatures($apiBody['subtitle_tracks']),
            $this->stripUrlSignatures($portalInfo['subtitle_tracks']),
            'subtitle_tracks must be identical across both dispatch paths',
        );
    }

    /**
     * Replaces each track's signed `url` with its path (dropping the
     * volatile `?exp&sig` token) so shapes can be compared exactly.
     *
     * @param mixed $tracks
     *
     * @return mixed
     */
    private function stripUrlSignatures(mixed $tracks): mixed
    {
        if (!is_array($tracks)) {
            return $tracks;
        }
        foreach ($tracks as $i => $track) {
            if (!is_array($track)) {
                continue;
            }
            $url = $track['url'] ?? null;
            if (is_string($url)) {
                $track['url'] = (string) parse_url($url, PHP_URL_PATH);
                $tracks[$i] = $track;
            }
        }

        return $tracks;
    }
}
