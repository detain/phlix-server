<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Serve-time parental re-check for the HLS/DASH file servers (Finding 1b,
 * defense-in-depth). Even with a valid (leaked/replayed) signed URL, a capped
 * session must not reach an over-cap job's bytes: the controller maps the job
 * back to its media item and 404s BEFORE producing/serving any segment. The
 * owner / un-capped / unauthenticated request is never gated.
 */
class TranscodeServeParentalTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_serveparental_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @param array<string, string|null>                                   $effective id => effective rating
     */
    private function gate(?array $filter, bool $isAdmin = false, array $effective = []): RatingGate
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
        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);

        return new RatingGate($items, $pm, $users);
    }

    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return ['allowedRatings' => ['G', 'PG', 'PG-13'], 'allowUnrated' => true];
    }

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    private function writeSeg(string $jobId, string $file): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("{$dir}/{$file}", 'TSDATA');
    }

    public function testHlsServeBlocksOverCapJobBeforeProducingSegment(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobMediaItemId')->with('job-x')->willReturn('m-mature');
        // Security invariant: no segment is produced for an over-cap job.
        $manager->expects($this->never())->method('ensureSegment');

        $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
        $controller = new HlsController(
            $streamer,
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-mature' => 'R'])
        );

        $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-x', 'file' => 'seg-v1080p-00000.ts']);
        $this->assertSame(404, $resp->statusCode);
    }

    /**
     * S310 — the gate must bite on the fMP4 names too, not just `\.ts$`.
     *
     * The check runs before the filename router, so widening the router could
     * not have bypassed it by construction — but "could not by construction" is
     * an argument, and this step's whole premise is that an argument like that
     * went unchecked for four steps. Both fMP4 shapes are asserted, INIT FIRST:
     * the init is the first byte-bearing request an fMP4 client makes, so it is
     * the one a leaked signed URL would replay.
     *
     * `ensureSegment` is `never()`: an over-cap job must not even be encoded,
     * which is the security invariant, not merely the 404.
     */
    public function testHlsServeBlocksOverCapJobForFmp4InitAndSegment(): void
    {
        foreach (['init-v1080p.m4s', 'seg-v1080p-00000.m4s'] as $file) {
            $manager = $this->createMock(TranscodeManager::class);
            $manager->method('getJobMediaItemId')->with('job-x')->willReturn('m-mature');
            $manager->expects($this->never())->method('ensureSegment');

            $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
            $controller = new HlsController(
                $streamer,
                $manager,
                $this->gate($this->pg13Filter(), false, ['m-mature' => 'R'])
            );

            $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-x', 'file' => $file]);
            $this->assertSame(404, $resp->statusCode, "{$file} must be gated");
        }
    }

    /**
     * The positive control for the case above: the SAME two fMP4 names on a
     * WITHIN-cap job do reach the producer and are served. Without this, the
     * 404s above would be satisfied just as well by a controller that refused
     * every `.m4s` — which is precisely what master did before S310.
     */
    public function testHlsServeAllowsFmp4InitAndSegmentForAWithinCapJob(): void
    {
        foreach (['init-v1080p.m4s', 'seg-v1080p-00000.m4s'] as $file) {
            $manager = $this->createMock(TranscodeManager::class);
            $manager->method('getJobMediaItemId')->with('job-ok')->willReturn('m-family');
            $manager->expects($this->once())
                ->method('ensureSegment')
                ->willReturnCallback(function () use ($file): string {
                    $this->writeSeg('job-ok', $file);
                    return "{$this->segmentDir}/job-ok/{$file}";
                });

            $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
            $controller = new HlsController(
                $streamer,
                $manager,
                $this->gate($this->pg13Filter(), false, ['m-family' => 'PG'])
            );

            $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-ok', 'file' => $file]);
            $this->assertSame(200, $resp->statusCode, "{$file} must be served for a within-cap job");
        }
    }

    public function testHlsServeAllowsWithinCapJob(): void
    {
        $this->writeSeg('job-ok', 'master.m3u8');

        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobMediaItemId')->with('job-ok')->willReturn('m-family');

        $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
        $controller = new HlsController(
            $streamer,
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-family' => 'PG'])
        );

        // A playlist file (no segment production) is served for an allowed job.
        $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-ok', 'file' => 'master.m3u8']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testHlsServeUnfilteredForOwner(): void
    {
        $this->writeSeg('job-ok', 'master.m3u8');

        $manager = $this->createMock(TranscodeManager::class);
        // Owner → null filter → job→media lookup never happens.
        $manager->expects($this->never())->method('getJobMediaItemId');

        $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
        $controller = new HlsController(
            $streamer,
            $manager,
            $this->gate($this->pg13Filter(), true, ['m-mature' => 'NC-17'])
        );

        $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-ok', 'file' => 'master.m3u8']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testDashServeBlocksOverCapJob(): void
    {
        $this->writeSeg('job-x', 'manifest.mpd');

        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobMediaItemId')->with('job-x')->willReturn('m-mature');

        $controller = new DashController(
            $this->segmentDir,
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-mature' => 'R'])
        );

        $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-x', 'file' => 'manifest.mpd']);
        $this->assertSame(404, $resp->statusCode);
    }

    public function testDashServeAllowsWithinCapJob(): void
    {
        $this->writeSeg('job-ok', 'manifest.mpd');

        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobMediaItemId')->with('job-ok')->willReturn('m-family');

        $controller = new DashController(
            $this->segmentDir,
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-family' => 'PG'])
        );

        $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-ok', 'file' => 'manifest.mpd']);
        $this->assertSame(200, $resp->statusCode);
    }

    /**
     * 🔓 S235 regression pin for the DELIBERATE opt-out.
     *
     * `/hls/{job}/{file}` sits behind {@see SignedUrlMiddleware}: a request that
     * reaches this handler with no `userId` has already presented a valid
     * signature (the `<video>`/hls.js manifest fetch can attach no Bearer
     * header). S235 made the gate fail CLOSED for an unidentified request, which
     * — had `transcodeJobOverCap()` kept calling `resolveFilterForUser()` —
     * would have 404'd every transcoded stream on the server. It calls
     * `resolveFilterForSignedRequest()` instead; this test reddens if that is
     * ever "tidied" back.
     *
     * `getJobMediaItemId` is asserted NEVER called: the gate must short-circuit,
     * not merely happen to allow.
     */
    public function testAnAnonymousSignedHlsFetchIsStillServed(): void
    {
        $this->writeSeg('job-ok', 'master.m3u8');

        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('getJobMediaItemId');

        $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
        $controller = new HlsController(
            $streamer,
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-mature' => 'NC-17'])
        );

        $anonymous = new Request();
        $this->assertNull($anonymous->userId, 'fixture must really be anonymous');

        $resp = $controller->serveFile($anonymous, ['job_id' => 'job-ok', 'file' => 'master.m3u8']);
        $this->assertSame(200, $resp->statusCode);
    }

    /**
     * The DASH half of the same opt-out (both controllers share the trait).
     */
    public function testAnAnonymousSignedDashFetchIsStillServed(): void
    {
        $this->writeSeg('job-ok', 'manifest.mpd');

        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('getJobMediaItemId');

        $controller = new DashController(
            $this->segmentDir,
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-mature' => 'NC-17'])
        );

        $anonymous = new Request();
        $resp = $controller->serveFile($anonymous, ['job_id' => 'job-ok', 'file' => 'manifest.mpd']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testDashServeUnfilteredWhenGateUnwired(): void
    {
        $this->writeSeg('job-ok', 'manifest.mpd');

        // Legacy construction (no manager / no gate) → strict no-op serve.
        $controller = new DashController($this->segmentDir);

        $resp = $controller->serveFile($this->cappedRequest(), ['job_id' => 'job-ok', 'file' => 'manifest.mpd']);
        $this->assertSame(200, $resp->statusCode);
    }
}
