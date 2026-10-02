<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Phlix\LiveTv\Recorder;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Http\Controllers\LiveTvStreamController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Wave-I finding close: the serve-time parental re-check on the DVR recording
 * stream route (`GET /livetv/recording/{id}/stream`).
 *
 * ## The measured bypass (pre-fix)
 *
 * A completed recording is registered as a `media_items` row by
 * {@see \Phlix\LiveTv\Recording\RecordingMediaRegistrar} and linked back via
 * `livetv_recordings.media_item_id` (migration 077). Every library-side access
 * to that row is S235-gated (show / playbackInfo / download / the
 * `transcodeJobOverCap` HLS-and-DASH serve re-check) — but the recording route
 * served the IDENTICAL `.ts` bytes from the recording id alone, with no
 * RatingGate call anywhere on the path. The recording uuid is not secret — the
 * registered item's absolute `path` (`{storage}/{recordingId}.ts`) ships in the
 * item payload (`MediaItemShaper::shape()['path']`) — but (2026-10-02 wave-I
 * review correction, forward-only) CAPPED members generally do not see over-cap
 * rows to mine: capped surfaces filter by the active profile's cap BEFORE
 * shaping, and registered recordings are unrated-at-birth (the registrar writes
 * no `rating`/`official_rating` metadata, so `content_rating` is NULL and only
 * a deny-unrated cap filters them at all). The pre-fix exposure ran through
 * signed-URL replay under the S235 signature-only opt-out, shared-account
 * profile-switch boundaries, and uuids learned via UNCAPPED household surfaces
 * (admin/uncapped payloads do ship `path`) — not "any row a capped member can
 * see". Either way, the serve-time re-check below closes the route for every
 * session-bearing request however the URL was learned.
 *
 * ## The close (parity, no new predicate)
 *
 * `streamRecording()` now runs the exact gate {@see
 * \Phlix\Server\Http\Controllers\TranscodeFileServer::transcodeJobOverCap()}
 * runs: `resolveFilterForSignedRequest()` (the route sits behind
 * SignedUrlMiddleware, so the S235 anonymous signature-only posture is carried
 * over unchanged), then `isAllowed()` against the recording's linked media id,
 * then a 404 whose body is byte-identical to the existing not-found answer —
 * a refusal cannot confirm existence. An UNLINKED recording (in-progress, or a
 * failed capture the registrar never registered) has no library counterpart to
 * key on and stays ungated, mirroring the trait's stale-job pass-through.
 */
final class LiveTvRecordingParentalGateTest extends TestCase
{
    /** @var list<string> Temp paths to clean up after each test. */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Gate behaviour on streamRecording
    // ---------------------------------------------------------------------

    /**
     * The headline red proof: a capped session fetching the stream of a COMPLETED
     * recording whose linked item is over cap must be refused. Pre-fix this
     * returned 200 with the recording bytes — the file exists on disk in this
     * fixture, so only the gate can turn it into a 404.
     */
    public function testCappedSessionIsRefusedTheStreamOfAnOverCapLinkedRecording(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-adult');

        $gate = $this->gate($this->pg13Filter(), isAdmin: false, effective: ['m-adult' => 'TV-MA']);
        $controller = new LiveTvStreamController(
            $this->recorderWith('rec-adult', Recorder::STATUS_COMPLETED, 'm-adult'),
            $dir,
            $gate
        );

        $resp = $controller->streamRecording($this->cappedRequest(), ['id' => 'rec-adult']);

        $this->assertSame(404, $resp->statusCode);
    }

    /**
     * No-existence-oracle pin: the refusal body must be byte-identical to the
     * body a genuinely missing recording produces, so probing the route cannot
     * distinguish "over cap" from "does not exist".
     */
    public function testOverCapRefusalIsByteIdenticalToTheNotFoundResponse(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-adult');

        $gate = $this->gate($this->pg13Filter(), isAdmin: false, effective: ['m-adult' => 'TV-MA']);
        $refused = (new LiveTvStreamController(
            $this->recorderWith('rec-adult', Recorder::STATUS_COMPLETED, 'm-adult'),
            $dir,
            $gate
        ))->streamRecording($this->cappedRequest(), ['id' => 'rec-adult']);

        $missingRecorder = $this->createMock(Recorder::class);
        $missingRecorder->method('getRecording')->willReturn(null);
        $missing = (new LiveTvStreamController($missingRecorder, $dir))
            ->streamRecording($this->cappedRequest(), ['id' => 'rec-adult']);

        $this->assertSame($missing->statusCode, $refused->statusCode);
        $this->assertSame($missing->body, $refused->body);
    }

    /**
     * The positive control: the SAME route, file and request shape serves the
     * stream when the linked item is within cap. Without this, the 404 above
     * would be satisfied equally well by a controller that refused everything.
     */
    public function testCappedSessionIsServedTheStreamOfAWithinCapLinkedRecording(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-family');

        $gate = $this->gate($this->pg13Filter(), isAdmin: false, effective: ['m-family' => 'PG']);
        $controller = new LiveTvStreamController(
            $this->recorderWith('rec-family', Recorder::STATUS_COMPLETED, 'm-family'),
            $dir,
            $gate
        );

        $resp = $controller->streamRecording($this->cappedRequest(), ['id' => 'rec-family']);
        $resp->materializeFileWindow();

        $this->assertSame(200, $resp->statusCode);
        $this->assertSame('RECBYTES', $resp->body);
    }

    /**
     * An unrated cap (allowUnrated: false) must bite a linked recording whose
     * item carries NO rating at all — the honest state of a freshly registered
     * broadcast capture — so the route cannot serve bytes that every library
     * rail refuses as "unrated under a deny-unrated cap".
     */
    public function testDenyUnratedCapRefusesALinkedUnratedRecordingItem(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-raw');

        $filter = ['allowedRatings' => ['G', 'PG'], 'allowUnrated' => false];
        $gate = $this->gate($filter, isAdmin: false, effective: ['m-raw' => null]);
        $controller = new LiveTvStreamController(
            $this->recorderWith('rec-raw', Recorder::STATUS_COMPLETED, 'm-raw'),
            $dir,
            $gate
        );

        $resp = $controller->streamRecording($this->cappedRequest(), ['id' => 'rec-raw']);

        $this->assertSame(404, $resp->statusCode);
    }

    /**
     * Unlinked recordings (in-progress captures, failed/zero-length completions
     * the registrar never registered) have no `media_item_id` to key the gate
     * on — the same stale-job pass-through posture as `transcodeJobOverCap()`.
     * They must still serve, and the gate must resolve NO rating (no DB walk).
     */
    public function testUnlinkedRecordingIsServedAndNeverReachesTheRatingLookup(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-live');

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('effectiveContentRatingsForIds');
        $gate = $this->gateWithItems($this->pg13Filter(), $items);

        $controller = new LiveTvStreamController(
            $this->recorderWith('rec-live', Recorder::STATUS_RECORDING, null),
            $dir,
            $gate
        );

        $resp = $controller->streamRecording($this->cappedRequest(), ['id' => 'rec-live']);
        $resp->materializeFileWindow();

        $this->assertSame(200, $resp->statusCode);
    }

    /**
     * Owner/admin posture: the gate resolves a null filter for an admin account,
     * so every recording serves exactly as before — the null filter short-circuits
     * BEFORE the rating lookup (asserted, not assumed).
     */
    public function testOwnerIsNeverGatedAndTheRatingLookupIsSkipped(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-adult');

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('effectiveContentRatingsForIds');
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($this->pg13Filter());
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 1]);
        $gate = new RatingGate($items, $pm, $users);

        $controller = new LiveTvStreamController(
            $this->recorderWith('rec-adult', Recorder::STATUS_COMPLETED, 'm-adult'),
            $dir,
            $gate
        );

        $resp = $controller->streamRecording($this->cappedRequest(), ['id' => 'rec-adult']);
        $resp->materializeFileWindow();

        $this->assertSame(200, $resp->statusCode);
    }

    /**
     * 🔓 S235 regression pin for the DELIBERATE signed-request opt-out — the
     * exact mirror of TranscodeServeParentalTest's anonymous-HLS pin. This route
     * sits behind SignedUrlMiddleware: a request reaching the handler with no
     * userId has presented a valid signature (a `<video>` element can attach no
     * Bearer header). If the gate were ever "tidied" from
     * resolveFilterForSignedRequest() back to resolveFilterForUser(), the
     * deny-all-for-anonymous cap would 404 EVERY signature-only recording
     * playback — this test reddens, and the never() proves it short-circuits
     * rather than happens-to-allow.
     */
    public function testAnAnonymousSignedRecordingFetchIsStillServed(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-adult');

        $items = $this->createMock(ItemRepository::class);
        $items->expects($this->never())->method('effectiveContentRatingsForIds');
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($this->pg13Filter());
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);
        $gate = new RatingGate($items, $pm, $users);

        $anonymous = new Request();
        $this->assertNull($anonymous->userId, 'fixture must really be anonymous');

        $controller = new LiveTvStreamController(
            $this->recorderWith('rec-adult', Recorder::STATUS_COMPLETED, 'm-adult'),
            $dir,
            $gate
        );

        $resp = $controller->streamRecording($anonymous, ['id' => 'rec-adult']);
        $resp->materializeFileWindow();

        $this->assertSame(200, $resp->statusCode);
    }

    /**
     * Legacy construction (no gate — the two-arg call sites) stays a strict
     * no-op serve, exactly like the trait's unwired posture.
     */
    public function testStreamIsServedWhenNoGateIsWired(): void
    {
        $dir = $this->makeTempDir();
        $this->writeRecording($dir, 'rec-adult');

        $controller = new LiveTvStreamController(
            $this->recorderWith('rec-adult', Recorder::STATUS_COMPLETED, 'm-adult'),
            $dir
        );

        $resp = $controller->streamRecording($this->cappedRequest(), ['id' => 'rec-adult']);
        $resp->materializeFileWindow();

        $this->assertSame(200, $resp->statusCode);
    }

    // ---------------------------------------------------------------------
    // Data plane: the recording row must SURFACE its media_item_id
    // ---------------------------------------------------------------------

    /**
     * `Recorder::getRecording()` reads `SELECT *`, so the linked column is on
     * the row — but the gate can only key on it if `mapRecording()` surfaces it.
     * Pre-fix this key was simply absent (red: assertArrayHasKey).
     */
    public function testGetRecordingSurfacesTheLinkedMediaItemId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([$this->recordingRow(['media_item_id' => 'm-7'])]);

        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $this->logger());
        $recording = $recorder->getRecording('rec-1');

        $this->assertIsArray($recording);
        $this->assertArrayHasKey('media_item_id', $recording);
        $this->assertSame('m-7', $recording['media_item_id']);
    }

    /**
     * The NULL side: an unregistered recording maps to a null link (never a
     * missing key, never a coerced empty string) so `is_string` guards in the
     * gate read honest data.
     */
    public function testGetRecordingMapsANullMediaItemLinkToNull(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([$this->recordingRow(['media_item_id' => null])]);

        $recorder = new Recorder($db, new DbTimeShiftSessionStore($db), '/tmp/recordings', 0, $this->logger());
        $recording = $recorder->getRecording('rec-1');

        $this->assertIsArray($recording);
        $this->assertArrayHasKey('media_item_id', $recording);
        $this->assertNull($recording['media_item_id']);
    }

    // ---------------------------------------------------------------------
    // Wiring guard: Application must actually thread the gate in
    // ---------------------------------------------------------------------

    /**
     * The fa30b871 lesson generalised to `new`-built controllers: an optional
     * trailing ctor param that no factory passes is a silent no-op gate. This
     * route's controller is built in {@see \Phlix\Server\Core\Application::getLiveTvStreamController()},
     * so the pin is on the production call site carrying the gate as the THIRD
     * argument — whole-LINE strict match (no substring fuzz), matching the
     * S236 wire-path discipline: the exact mutation "someone drops the gate
     * argument" must redden it.
     *
     * This pin sees the CALL SITE only. `Application::optionalRatingGate()`
     * resolves the gate inside a catch-all that returns null, so container drift
     * (a broken `RatingGate` binding) keeps this regex green while making the
     * threaded argument a silent no-op — that half is covered by
     * {@see \Phlix\Tests\Unit\Server\Core\LiveTvStreamControllerWiringGuardTest},
     * which composes the PRODUCTION container and asserts the resolved
     * controller's gate is non-null (mutation-proven: forcing the resolver to
     * null reddens the runtime guard while this pin passes).
     */
    public function testApplicationFactoryPassesTheRatingGateToTheStreamController(): void
    {
        $source = file_get_contents(dirname(__DIR__, 5) . '/src/Server/Core/Application.php');
        $this->assertIsString($source);

        $pattern = '/^([ \t]*)return new \\\\Phlix\\\\Server\\\\Http\\\\Controllers\\\\'
            . 'LiveTvStreamController\(\s*\$recorder,\s*\$storagePath,'
            . '\s*\$this->optionalRatingGate\(\),?\s*\);\s*$/m';
        $this->assertSame(
            1,
            preg_match_all($pattern, $source),
            'Application::getLiveTvStreamController() must construct LiveTvStreamController'
            . ' with exactly ($recorder, $storagePath, $this->optionalRatingGate()) —'
            . ' the parental re-check is dead wiring without the gate.'
        );
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function recordingRow(array $overrides = []): array
    {
        return array_merge([
            'recording_id'         => 'rec-1',
            'channel_id'           => 'ch-1',
            'program_id'           => null,
            'user_id'              => null,
            'title'                => 'Late Show',
            'description'          => null,
            'start_time'           => 1_700_000_000,
            'end_time'             => 1_700_003_600,
            'priority'             => Recorder::PRIORITY_NORMAL,
            'quality'              => 'default',
            'storage_path'         => '/tmp/recordings/rec-1.ts',
            'storage_size'         => 0,
            'status'               => Recorder::STATUS_COMPLETED,
            'media_item_id'        => null,
            'error_message'        => null,
            'series_rule_id'       => null,
            'duplicate_group'      => null,
            'pre_padding_seconds'  => 60,
            'post_padding_seconds' => 60,
            'created_at'           => '2024-01-01 00:00:00',
            'updated_at'           => '2024-01-01 00:00:00',
        ], $overrides);
    }

    private function logger(): \Phlix\Common\Logger\StructuredLogger
    {
        return $this->createMock(\Phlix\Common\Logger\StructuredLogger::class);
    }

    /**
     * A Recorder whose getRecording() answers the given status/link — the
     * controller-level fixture (the DB round is pinned separately above).
     */
    private function recorderWith(string $id, string $status, ?string $mediaItemId): Recorder
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getRecording')->with($id)->willReturn([
            'id' => $id,
            'status' => $status,
            'media_item_id' => $mediaItemId,
        ]);
        return $recorder;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_lt_parental_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempPaths[] = $dir;
        return $dir;
    }

    private function writeRecording(string $dir, string $id): void
    {
        $path = "$dir/$id.ts";
        file_put_contents($path, 'RECBYTES');
        $this->tempPaths[] = $path;
    }

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return ['allowedRatings' => ['G', 'PG', 'PG-13'], 'allowUnrated' => true];
    }

    /**
     * A REAL RatingGate over mocked collaborators — the same construction the
     * HLS serve-time pin (TranscodeServeParentalTest) uses, so the recording
     * gate is proven against the same object, not a stand-in.
     *
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @param array<string, string|null>                                    $effective id => effective rating
     */
    private function gate(?array $filter, bool $isAdmin, array $effective): RatingGate
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('effectiveContentRatingsForIds')->willReturnCallback(
            static function (array $ids) use ($effective): array {
                $out = [];
                foreach ($ids as $id) {
                    $out[$id] = $effective[$id] ?? null;
                }
                return $out;
            }
        );
        return $this->gateWithResolved($filter, $isAdmin, $items);
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool} $filter
     */
    private function gateWithItems(array $filter, ItemRepository $items): RatingGate
    {
        return $this->gateWithResolved($filter, false, $items);
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     */
    private function gateWithResolved(?array $filter, bool $isAdmin, ItemRepository $items): RatingGate
    {
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);
        return new RatingGate($items, $pm, $users);
    }
}
