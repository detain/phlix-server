<?php

/**
 * Phlix media server tests: WebPortal.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\StreamProbeBackfill;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Psr\Log\NullLogger;

/**
 * `GET /api/v1/media/{id}` — the media DETAIL endpoint — is the ONLY response
 * that carries video stream rows, and the web player reads the source's video
 * codec out of them to choose direct-play vs transcode (an unknown codec is
 * treated as direct-playable, and an undecodable video then raises no `error`
 * event at all: permanent black screen with audio). The handler therefore runs
 * the lazy stream backfill, exactly like `getPlaybackInfo()` already did.
 *
 * The endpoint is served by {@see WebPortalRouter::getMediaItem()} on the
 * resident Workerman path — `Application`'s router registers only
 * `DELETE /api/v1/media/{id}`, so `HttpHandler` 404s there and falls through to
 * this router ({@see \Phlix\Server\Workerman\HttpHandler} step 1b). The CGI
 * entry point sends every `/api/` path here directly.
 *
 * The backfill is armed with the NARROW {@see StreamProbeBackfill::ensureVideoCodecFor()}
 * trigger, not the pre-071 audio/subtitle trigger `getPlaybackInfo()` uses:
 * detail is hit on every item view, and on the production library 79,218 of
 * 116,325 items (61,130 of them music tracks on sshfs shares) match the broad
 * trigger — arming it here would fork that many blocking ffprobes inside the
 * single-threaded worker. The tests below pin BOTH halves of that contract: the
 * repair happens when the video codec is unknown, and nothing else ever probes.
 */
class WebPortalRouterDetailStreamBackfillTest extends TestCase
{
    /** @var list<string> Temp files unlinked in tearDown. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    /** Real (empty) file on disk so StreamProbeBackfill's is_file() gate passes. */
    private function makeTempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-detail-probe-');
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array<string, mixed> A realistic multi-track ffprobe result. */
    private function multiTrackProbe(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '5000000'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac',
                 'channels' => 2, 'disposition' => ['default' => 1],
                 'tags' => ['language' => 'eng']],
            ],
            'format' => ['duration' => '600.0'],
        ];
    }

    private function makeRouter(
        ItemRepository $itemRepository,
        ?StreamProbeBackfill $backfill = null,
        ?UserProfileManager $profileManager = null,
        ?UserRepository $userRepository = null
    ): WebPortalRouter {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $itemRepository,
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            userRepository: $userRepository,
            profileManager: $profileManager,
            streamBackfill: $backfill,
        );
    }

    /**
     * Decode `{item: {...}}` into the shape the assertions inspect.
     *
     * @return array{item: array<string, mixed>}
     */
    private function decodeItem(string $json): array
    {
        /** @var array{item: array<string, mixed>} $decoded */
        $decoded = json_decode($json, true);
        return $decoded;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function detailStreams(array $item, ItemRepository $repo, ?StreamProbeBackfill $backfill): array
    {
        $request = new Request();
        $request->userId = 'user-1';
        $body = $this->decodeItem(
            $this->makeRouter($repo, $backfill)->getMediaItem($request, ['id' => (string) $item['id']])->body
        );

        return $body['item'];
    }

    /**
     * The motivating case: an item whose stored `video` row carries NO codec.
     * Before the backfill was wired here the handler shaped the stale rows and
     * the player saw an UNKNOWN video codec, so it defaulted to direct play.
     */
    public function testDetailBackfillsStreamsWhenVideoCodecIsMissing(): void
    {
        $path = $this->makeTempFile();
        $item = [
            'id' => 'ep-1',
            'name' => 'Pilot',
            'type' => 'episode',
            'path' => $path,
            'streams_probed_at' => null,
            'metadata' => [],
        ];

        // Stored rows: a video row with NO codec (the defect) + one audio row.
        $stale = [
            ['stream_index' => 0, 'stream_type' => 'video', 'codec' => null],
            ['stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac'],
        ];
        // What the repository holds after the probe replaced the rows.
        $fresh = [
            ['stream_index' => 0, 'stream_type' => 'video', 'codec' => 'hevc'],
            ['stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac'],
        ];

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->with('ep-1')->willReturn($item);
        // Read ONCE by the handler, ONCE by the backfill's post-write re-read —
        // never a third time, which is what a stale second read would look like.
        $repo->expects($this->exactly(2))
            ->method('getItemStreams')
            ->with('ep-1')
            ->willReturnOnConsecutiveCalls($stale, $fresh);
        // ONE atomic replacement (delete + both inserts inside a single
        // transaction — ItemRepository::replaceStreams()), so a concurrent read
        // of this item can never see the half-replaced set.
        $repo->expects($this->once())->method('replaceStreams')
            ->with('ep-1', $this->anything());
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('addStream');
        $repo->expects($this->once())->method('markStreamsProbed')->with('ep-1');

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->with($path)->willReturn($this->multiTrackProbe());

        $shaped = $this->detailStreams(
            $item,
            $repo,
            new StreamProbeBackfill($repo, $ffmpeg, new NullLogger())
        );

        // The SHAPED payload carries the ensured rows — proving shapeDetail was
        // handed the backfilled set, not a second un-backfilled read.
        $this->assertSame($fresh, $shaped['streams']);
        /** @var list<array<string, mixed>> $streams */
        $streams = $shaped['streams'];
        $this->assertSame('hevc', $streams[0]['codec'], 'the player must get a KNOWN video codec');
    }

    /**
     * Perf regression guard #1 — the hot path. An item whose video row already
     * carries a codec must cost ZERO extra work: no probe, and exactly one
     * stream read. This is ~every item in a real library.
     */
    public function testItemWithKnownVideoCodecIsNeverProbed(): void
    {
        $item = [
            'id' => 'ep-2',
            'name' => 'Known',
            'type' => 'episode',
            'path' => $this->makeTempFile(),
            // Unprobed on purpose: the codec check alone must stop the probe,
            // so a pre-071 item is not re-probed just for viewing its detail.
            'streams_probed_at' => null,
            'metadata' => [],
        ];
        $stored = [
            ['stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264'],
            ['stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac'],
        ];

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->expects($this->once())->method('getItemStreams')->willReturn($stored);
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('markStreamsProbed');

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $shaped = $this->detailStreams($item, $repo, new StreamProbeBackfill($repo, $ffmpeg, new NullLogger()));

        $this->assertSame($stored, $shaped['streams']);
    }

    /**
     * Perf regression guard #2 — the `streams_probed_at` marker still wins. An
     * already-probed item never re-probes, even when its video codec is still
     * missing (that file's codec is simply unidentifiable; retrying it on every
     * detail view would be an unbounded blocking cost).
     */
    public function testAlreadyProbedItemIsNeverProbedAgain(): void
    {
        $item = [
            'id' => 'ep-3',
            'name' => 'Stamped',
            'type' => 'episode',
            'path' => $this->makeTempFile(),
            'streams_probed_at' => '2026-07-16 04:16:42',
            'metadata' => [],
        ];
        $stored = [['stream_index' => 0, 'stream_type' => 'video', 'codec' => null]];

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->expects($this->once())->method('getItemStreams')->willReturn($stored);
        $repo->expects($this->never())->method('markStreamsProbed');

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $shaped = $this->detailStreams($item, $repo, new StreamProbeBackfill($repo, $ffmpeg, new NullLogger()));

        $this->assertSame($stored, $shaped['streams']);
    }

    /**
     * Blast-radius guard — the ffprobe-storm case. A music track is unprobed and
     * matches the BROAD pre-071 trigger (one audio row, no subtitle rows): the
     * production library holds 61,130 of these on sshfs shares. Because it has
     * no `video` row at all, the detail endpoint must not probe it.
     */
    public function testUnprobedTrackWithNoVideoRowIsNeverProbed(): void
    {
        $item = [
            'id' => 'track-1',
            'name' => 'Blue in Green',
            'type' => 'track',
            'path' => $this->makeTempFile(),
            'streams_probed_at' => null,
            'metadata' => [],
        ];
        $stored = [['stream_index' => 0, 'stream_type' => 'audio', 'codec' => 'flac']];

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->expects($this->once())->method('getItemStreams')->willReturn($stored);
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('markStreamsProbed');

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $shaped = $this->detailStreams($item, $repo, new StreamProbeBackfill($repo, $ffmpeg, new NullLogger()));

        $this->assertSame($stored, $shaped['streams']);
    }

    /**
     * Security/DoS ordering — the parental RatingGate 404 is UNCHANGED, and the
     * backfill sits after it: an over-cap deep-link must not let an unauthorised
     * caller fork an ffprobe (nor even read the item's stream rows).
     */
    public function testCappedProfileStillGets404AndCannotTriggerAProbe(): void
    {
        $item = [
            'id' => 'ep-4',
            'name' => 'Adults Only',
            'type' => 'episode',
            'content_rating' => 'R',
            'path' => $this->makeTempFile(),
            'streams_probed_at' => null,
            'metadata' => [],
        ];

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->expects($this->never())->method('getItemStreams');
        $repo->expects($this->never())->method('markStreamsProbed');

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->method('getActiveRatingFilter')->willReturn([
            'allowedRatings' => ['G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG', 'PG-13', 'TV-14'],
            'allowUnrated' => true,
        ]);
        $profileManager->method('getActiveProfile')->willReturn(['id' => 'p1']);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn(['id' => 'user-1', 'is_admin' => 0]);

        $router = $this->makeRouter(
            $repo,
            new StreamProbeBackfill($repo, $ffmpeg, new NullLogger()),
            $profileManager,
            $userRepository,
        );

        $request = new Request();
        $request->userId = 'user-1';
        $response = $router->getMediaItem($request, ['id' => 'ep-4']);

        $this->assertSame(404, $response->statusCode);
    }
}
