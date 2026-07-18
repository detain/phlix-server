<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\TranscodeController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Parental-control ACCESS gate coverage for {@see TranscodeController} — the
 * transcode-pipeline minting endpoints that are the real playback path for
 * non-direct-play files (Finding 1a). A capped profile must NOT be able to start
 * (or poll) a transcode job for an over-cap item, and must receive NO signed
 * HLS/DASH URLs. The owner / un-capped profile is never gated.
 */
class TranscodeControllerParentalTest extends TestCase
{
    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return [
            'allowedRatings' => ['G', 'PG', 'PG-13'],
            'allowUnrated' => true,
        ];
    }

    /**
     * Build a real RatingGate (final class — cannot be mocked) over mocked deps,
     * mirroring MediaItemControllerParentalTest.
     *
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

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    public function testStartBlocksOverCapItemWithNoJobAndNoUrls(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        // Security invariant: no transcode job is ever created for an over-cap item.
        $manager->expects($this->never())->method('ensureHlsJob');

        $controller = new TranscodeController(
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-mature' => 'R'])
        );

        $resp = $controller->start($this->cappedRequest(), ['id' => 'm-mature']);

        $this->assertSame(404, $resp->statusCode);
        // No signed HLS/DASH URL is disclosed in the denial body.
        $this->assertStringNotContainsString('/hls/', $resp->body);
        $this->assertStringNotContainsString('/dash/', $resp->body);
        $this->assertStringNotContainsString('sig=', $resp->body);
    }

    public function testStartAllowsWithinCapItem(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())->method('ensureHlsJob')->willReturn([
            'job_id' => 'job-1',
            'status' => 'completed',
            'master_url' => '/hls/job-1/master.m3u8',
            'hls_url' => '/hls/job-1/master.m3u8',
            'dash_url' => '/dash/job-1/manifest.mpd',
            'reused' => false,
            'subtitles' => [],
        ]);

        $controller = new TranscodeController(
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-family' => 'PG'])
        );

        $resp = $controller->start($this->cappedRequest(), ['id' => 'm-family']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testStartAllowsOverCapForOwnerAdmin(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())->method('ensureHlsJob')->willReturn([
            'job_id' => 'job-1',
            'status' => 'completed',
            'master_url' => '/hls/job-1/master.m3u8',
            'hls_url' => '/hls/job-1/master.m3u8',
            'dash_url' => '/dash/job-1/manifest.mpd',
            'reused' => false,
            'subtitles' => [],
        ]);

        // Admin owner → resolveFilterForUser null → no gate, even for NC-17.
        $controller = new TranscodeController(
            $manager,
            $this->gate($this->pg13Filter(), true, ['m-mature' => 'NC-17'])
        );

        $resp = $controller->start($this->cappedRequest(), ['id' => 'm-mature']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testStartUnfilteredWhenNoProfileContext(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())->method('ensureHlsJob')->willReturn([
            'job_id' => 'job-1',
            'status' => 'completed',
            'master_url' => '/hls/job-1/master.m3u8',
            'hls_url' => '/hls/job-1/master.m3u8',
            'dash_url' => '/dash/job-1/manifest.mpd',
            'reused' => false,
            'subtitles' => [],
        ]);

        // No active cap → null filter → no gate.
        $controller = new TranscodeController(
            $manager,
            $this->gate(null, false, ['m-mature' => 'NC-17'])
        );

        $resp = $controller->start($this->cappedRequest(), ['id' => 'm-mature']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testStartUnfilteredWhenGateUnwired(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())->method('ensureHlsJob')->willReturn([
            'job_id' => 'job-1',
            'status' => 'completed',
            'master_url' => '/hls/job-1/master.m3u8',
            'hls_url' => '/hls/job-1/master.m3u8',
            'dash_url' => '/dash/job-1/manifest.mpd',
            'reused' => false,
            'subtitles' => [],
        ]);

        // No gate injected (legacy) → strict no-op.
        $controller = new TranscodeController($manager);

        $resp = $controller->start($this->cappedRequest(), ['id' => 'm-mature']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testStatusBlocksOverCapJobWithNoUrls(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobMediaItemId')->with('job-x')->willReturn('m-mature');
        // Security invariant: readiness (and thus the signed URLs) is never computed.
        $manager->expects($this->never())->method('getJobReadiness');

        $controller = new TranscodeController(
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-mature' => 'R'])
        );

        $resp = $controller->status($this->cappedRequest(), ['jobId' => 'job-x']);

        $this->assertSame(404, $resp->statusCode);
        $this->assertStringNotContainsString('/hls/', $resp->body);
        $this->assertStringNotContainsString('/dash/', $resp->body);
    }

    public function testStatusAllowsWithinCapJob(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobMediaItemId')->with('job-ok')->willReturn('m-family');
        $manager->expects($this->once())->method('getJobReadiness')->willReturn([
            'job_id' => 'job-ok',
            'status' => 'completed',
            'segments' => 0,
            'playlist_ready' => true,
            'progress' => 100.0,
            'subtitles' => [],
        ]);

        $controller = new TranscodeController(
            $manager,
            $this->gate($this->pg13Filter(), false, ['m-family' => 'PG'])
        );

        $resp = $controller->status($this->cappedRequest(), ['jobId' => 'job-ok']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testStatusUnfilteredForOwner(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        // Owner → null filter → job→media lookup is never needed.
        $manager->expects($this->never())->method('getJobMediaItemId');
        $manager->expects($this->once())->method('getJobReadiness')->willReturn([
            'job_id' => 'job-ok',
            'status' => 'completed',
            'segments' => 0,
            'playlist_ready' => true,
            'progress' => 100.0,
            'subtitles' => [],
        ]);

        $controller = new TranscodeController(
            $manager,
            $this->gate($this->pg13Filter(), true, ['m-mature' => 'NC-17'])
        );

        $resp = $controller->status($this->cappedRequest(), ['jobId' => 'job-ok']);
        $this->assertSame(200, $resp->statusCode);
    }
}
