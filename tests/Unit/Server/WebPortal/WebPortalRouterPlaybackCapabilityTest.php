<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Markers\SkipButtonSpec;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * SV-3.3(2A): the /api/v1/media/{id}/playback direct-play verdict must honour
 * the client's declared decoder capabilities (X-Phlix-Client-Capabilities).
 *
 * An E-AC-3 source told to a client that declares `{"eac3":false}` reports
 * direct_play=false; the same source with supportive/absent capabilities keeps
 * direct_play=true (the historical always-true behaviour). The gate keys on the
 * FIRST audio track (StreamTrackShaper orders tracks by stream_index ascending),
 * matching the first-audio-stream predicate the transcode path
 * (TranscodeManager::computeHlsParams → firstStreamOfType($probe,'audio')) uses —
 * so the verdict and the actual transcode decision agree.
 */
class WebPortalRouterPlaybackCapabilityTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $audioStreams `media_streams` rows.
     * @return ItemRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function repoWithStreams(array $audioStreams)
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'm1',
            'name' => 'Movie',
            'type' => 'movie',
            'path' => '/nonexistent/movie.mkv',
            'content_rating' => null,
            'metadata_json' => '{}',
            // Stamped so the lazy StreamProbeBackfill never runs a blocking probe.
            'streams_probed_at' => '2026-01-01 00:00:00',
        ]);
        $repo->method('getItemStreams')->willReturn($audioStreams);

        return $repo;
    }

    /**
     * @param string $codec Audio codec for the single (default) audio track.
     * @return list<array<string, mixed>>
     */
    private function singleAudio(string $codec): array
    {
        return [[
            'id' => 's1',
            'stream_type' => 'audio',
            'stream_index' => 1,
            'codec' => $codec,
            'language' => 'eng',
            'channels' => 6,
        ]];
    }

    private function router(ItemRepository $repo): WebPortalRouter
    {
        $pms = $this->createMock(PlaybackMarkerService::class);
        // SkipButtonSpec is final and cannot be auto-doubled; return a real one.
        $pms->method('getFullSpec')->willReturn(new SkipButtonSpec(null, null, null, null));

        // profileManager left null → no parental gate (resolveRatingFilter → null),
        // so the item is always reachable and we exercise the capability path only.
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $repo,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $pms,
            $this->createMock(MarkerService::class),
        );
    }

    private function requestWithCaps(?string $capsHeader): Request
    {
        $req = new Request();
        if ($capsHeader !== null) {
            $req->headers = ['X-Phlix-Client-Capabilities' => $capsHeader];
        }

        return $req;
    }

    private function directPlayOf(Response $resp): mixed
    {
        $this->assertSame(200, $resp->statusCode);
        $body = json_decode($resp->body, true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['playback_info'] ?? null);
        $sources = $body['playback_info']['media_sources'] ?? null;
        $this->assertIsArray($sources);
        $this->assertIsArray($sources[0] ?? null);

        return $sources[0]['direct_play'] ?? null;
    }

    public function testDirectPlayFalseWhenClientCannotDecodeEac3(): void
    {
        $router = $this->router($this->repoWithStreams($this->singleAudio('eac3')));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps('{"eac3": false}'),
            ['id' => 'm1']
        );

        $this->assertFalse($this->directPlayOf($resp));
    }

    public function testDirectPlayTrueWhenClientDeclaresEac3Supported(): void
    {
        $router = $this->router($this->repoWithStreams($this->singleAudio('eac3')));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps('{"eac3": true}'),
            ['id' => 'm1']
        );

        $this->assertTrue($this->directPlayOf($resp));
    }

    public function testDirectPlayTrueWhenNoCapabilityHeaderPreservesBackwardCompat(): void
    {
        // No X-Phlix-Client-Capabilities header at all → historical always-true.
        $router = $this->router($this->repoWithStreams($this->singleAudio('eac3')));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps(null),
            ['id' => 'm1']
        );

        $this->assertTrue($this->directPlayOf($resp));
    }

    public function testDirectPlayTrueWhenCapabilityHeaderEmptyPreservesBackwardCompat(): void
    {
        // Empty header parses to an empty (no-explicit) instance → always-true.
        $router = $this->router($this->repoWithStreams($this->singleAudio('eac3')));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps(''),
            ['id' => 'm1']
        );

        $this->assertTrue($this->directPlayOf($resp));
    }

    public function testDirectPlayTrueForAacWhenOnlyOtherCodecDeclaredUnsupported(): void
    {
        // Source is AAC; the client only declared eac3=false. AAC is widely
        // supported and un-declared → default-allow → direct_play stays true.
        $router = $this->router($this->repoWithStreams($this->singleAudio('aac')));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps('{"eac3": false}'),
            ['id' => 'm1']
        );

        $this->assertTrue($this->directPlayOf($resp));
    }

    public function testDirectPlayFalseWhenDefaultCodecExplicitlyUnsupported(): void
    {
        // Even a widely-supported codec is denied when the client explicitly
        // declares it unsupported.
        $router = $this->router($this->repoWithStreams($this->singleAudio('aac')));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps('{"aac": false}'),
            ['id' => 'm1']
        );

        $this->assertFalse($this->directPlayOf($resp));
    }

    public function testDirectPlayGatesOnFirstTrackNotDefaultTrack(): void
    {
        // SV-3.3 reconcile: the verdict must key on the FIRST audio stream (the
        // one TranscodeManager::computeHlsParams → firstStreamOfType selects),
        // NOT the default-disposition track. Here the first track is AAC (no
        // default disposition) while the second is E-AC-3 and IS the default.
        // Because the gate keys on the first track (aac, un-declared → allowed),
        // `{"eac3":false}` leaves direct_play=true — proving the verdict follows
        // computeHlsParams' first-stream choice, not the default disposition.
        $streams = [
            [
                'id' => 's1',
                'stream_type' => 'audio',
                'stream_index' => 1,
                'codec' => 'aac',
                'language' => 'eng',
                'channels' => 2,
            ],
            [
                'id' => 's2',
                'stream_type' => 'audio',
                'stream_index' => 2,
                'codec' => 'eac3',
                'language' => 'eng',
                'channels' => 6,
                'disposition' => 1,
            ],
        ];

        $router = $this->router($this->repoWithStreams($streams));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps('{"eac3": false}'),
            ['id' => 'm1']
        );

        $this->assertTrue($this->directPlayOf($resp));
    }

    public function testDirectPlayGatesOnFirstTrackCodecWhenFirstIsUnsupported(): void
    {
        // Mirror of the above with the codecs swapped so the FIRST track is the
        // undecodable one: first = E-AC-3 (no default disposition), second = AAC
        // and IS the default. The gate keys on the first (eac3) track, so
        // `{"eac3":false}` → direct_play=false. Together the two tests pin the
        // selection to the first ffprobe audio stream regardless of disposition.
        $streams = [
            [
                'id' => 's1',
                'stream_type' => 'audio',
                'stream_index' => 1,
                'codec' => 'eac3',
                'language' => 'eng',
                'channels' => 6,
            ],
            [
                'id' => 's2',
                'stream_type' => 'audio',
                'stream_index' => 2,
                'codec' => 'aac',
                'language' => 'eng',
                'channels' => 2,
                'disposition' => 1,
            ],
        ];

        $router = $this->router($this->repoWithStreams($streams));
        $resp = $router->getPlaybackInfo(
            $this->requestWithCaps('{"eac3": false}'),
            ['id' => 'm1']
        );

        $this->assertFalse($this->directPlayOf($resp));
    }
}
