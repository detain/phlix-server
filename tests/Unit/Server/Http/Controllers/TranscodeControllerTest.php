<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\TranscodeController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see TranscodeController} — the start/status endpoints that
 * front the {@see TranscodeManager} HLS job lifecycle.
 */
class TranscodeControllerTest extends TestCase
{
    public function testStartReturnsJobAndMasterUrl(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureHlsJob')
            ->with('media-1', 'web')
            ->willReturn([
                'job_id' => 'job-7',
                'status' => 'running',
                'master_url' => '/hls/job-7/master.m3u8',
                'hls_url' => '/hls/job-7/master.m3u8',
                'reused' => false,
                'subtitles' => [
                    ['index' => 0, 'language' => 'eng', 'label' => 'English', 'default' => true,
                        'url' => '/hls/job-7/sub-0.vtt'],
                ],
            ]);
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{job_id: mixed, master_url: string, reused: mixed, subtitles: array<int, array{url: string, label: mixed, default: mixed}>} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('job-7', $body['job_id']);
        // Streaming URLs are now signed (prefix-scoped to the job dir) so the
        // player can fetch the manifest/segments/subtitles without a Bearer header.
        $this->assertSignedUrlFor('/hls/job-7/master.m3u8', $body['master_url']);
        $this->assertFalse($body['reused']);
        $this->assertSignedUrlFor('/hls/job-7/sub-0.vtt', $body['subtitles'][0]['url']);
        $this->assertSame('English', $body['subtitles'][0]['label']);
        $this->assertTrue($body['subtitles'][0]['default']);
    }

    /**
     * An explicit non-empty `?profile=` wins over any device-type header
     * (back-compat for clients that still pass the param).
     */
    public function testStartExplicitProfileWinsOverDeviceTypeHeader(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureHlsJob')
            ->with('media-1', 'mobile-low')
            ->willReturn($this->jobFixture());
        $controller = new TranscodeController($manager);

        $request = new Request();
        $request->query = ['profile' => 'mobile-low'];
        // Header would map to tv-4k, but the explicit param must win.
        $request->headers = ['X-PHLIX-DEVICE-TYPE' => 'samsung-tizen'];

        $response = $controller->start($request, ['id' => 'media-1']);
        $this->assertSame(200, $response->statusCode);
    }

    /**
     * With no explicit `?profile=`, the `X-Phlix-Device-Type` header drives the
     * profile choice instead of always defaulting to `web`.
     *
     * @dataProvider deviceTypeProfileProvider
     */
    public function testStartMapsDeviceTypeHeaderToProfile(string $deviceType, string $expectedProfile): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureHlsJob')
            ->with('media-1', $expectedProfile)
            ->willReturn($this->jobFixture());
        $controller = new TranscodeController($manager);

        $request = new Request();
        $request->headers = ['X-PHLIX-DEVICE-TYPE' => $deviceType];

        $response = $controller->start($request, ['id' => 'media-1']);
        $this->assertSame(200, $response->statusCode);

        // Invariant: whatever profile the header maps to must be one that
        // QualitySelector actually defines (no mapping to a phantom profile).
        $this->assertNotNull(
            (new QualitySelector())->getProfile($expectedProfile),
            "mapped profile '{$expectedProfile}' must be a known QualitySelector profile",
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function deviceTypeProfileProvider(): array
    {
        return [
            'samsung-tizen → tv-4k' => ['samsung-tizen', 'tv-4k'],
            'tizen → tv-4k' => ['tizen', 'tv-4k'],
            'roku → tv-4k' => ['roku', 'tv-4k'],
            'android → mobile-high' => ['android', 'mobile-high'],
            'ios → mobile-high' => ['ios', 'mobile-high'],
            'windows → generic' => ['windows', 'generic'],
            'unknown → web' => ['some-future-device', 'web'],
            'empty → web' => ['', 'web'],
            // Case-insensitivity.
            'SAMSUNG-TIZEN (upper) → tv-4k' => ['SAMSUNG-TIZEN', 'tv-4k'],
            'Android (mixed) → mobile-high' => ['Android', 'mobile-high'],
            'Roku (mixed) → tv-4k' => ['Roku', 'tv-4k'],
            'iOS (mixed) → mobile-high' => ['iOS', 'mobile-high'],
        ];
    }

    /**
     * No explicit profile AND no device-type header at all → falls back to `web`
     * (the historical default is preserved).
     */
    public function testStartDefaultsToWebWhenNoProfileAndNoHeader(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureHlsJob')
            ->with('media-1', 'web')
            ->willReturn($this->jobFixture());
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);
        $this->assertSame(200, $response->statusCode);
    }

    /**
     * An empty explicit `?profile=` is treated as "not provided" and falls
     * through to the device-type mapping.
     */
    public function testStartEmptyProfileFallsThroughToDeviceType(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureHlsJob')
            ->with('media-1', 'mobile-high')
            ->willReturn($this->jobFixture());
        $controller = new TranscodeController($manager);

        $request = new Request();
        $request->query = ['profile' => ''];
        $request->headers = ['X-PHLIX-DEVICE-TYPE' => 'android'];

        $response = $controller->start($request, ['id' => 'media-1']);
        $this->assertSame(200, $response->statusCode);
    }

    /**
     * @return array{
     *     job_id: string, status: string, master_url: string, hls_url: string,
     *     reused: bool, subtitles: array<int, array<string, mixed>>
     * }
     */
    private function jobFixture(): array
    {
        return [
            'job_id' => 'job-7',
            'status' => 'running',
            'master_url' => '/hls/job-7/master.m3u8',
            'hls_url' => '/hls/job-7/master.m3u8',
            'reused' => false,
            'subtitles' => [],
        ];
    }

    /**
     * A multi-variant job → `start()` includes a `variants[]` array with each
     * variant's own media-playlist url signed exactly like master_url.
     */
    public function testStartIncludesSignedVariantsForMultiVariantJob(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureHlsJob')->willReturn($this->jobFixture());
        $manager->expects($this->once())
            ->method('getJobVariants')
            ->with('job-7')
            ->willReturn($this->variantsFixture());
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);
        $this->assertSame(200, $response->statusCode);
        /** @var array{variants: array<int, array{url: string, id: mixed, height: mixed, label: mixed}>} $body */
        $body = json_decode($response->body, true);

        $this->assertIsArray($body['variants']);
        $this->assertCount(2, $body['variants']);
        // Each variant url is signed against its OWN relative media-playlist path.
        $this->assertSignedUrlFor('/hls/job-7/media_v1080p.m3u8', $body['variants'][0]['url']);
        $this->assertSignedUrlFor('/hls/job-7/media_v720p.m3u8', $body['variants'][1]['url']);
        // Flat Rendition shape preserved untouched.
        $this->assertSame('1080p', $body['variants'][0]['id']);
        $this->assertSame(1080, $body['variants'][0]['height']);
        $this->assertSame('1080p', $body['variants'][0]['label']);
    }

    /**
     * A legacy job (`variants IS NULL`) → `start()` includes an explicit
     * `variants: null` (backward-compatible; clients check `!= null`).
     */
    public function testStartIncludesNullVariantsForLegacyJob(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureHlsJob')->willReturn($this->jobFixture());
        $manager->expects($this->once())
            ->method('getJobVariants')
            ->with('job-7')
            ->willReturn(null);
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);
        $this->assertSame(200, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('variants', $body);
        $this->assertNull($body['variants']);
        // Every pre-existing key is intact (backward compatibility).
        foreach (['job_id', 'master_url', 'hls_url', 'status', 'reused', 'subtitles'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
        // S11 regression guard: a 404'ing `dash_url` must never re-enter the
        // start() payload while real DASH (S56-S60) is unbuilt.
        $this->assertArrayNotHasKey('dash_url', $body);
    }

    public function testStatusIncludesSignedVariantsForMultiVariantJob(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobReadiness')->willReturn([
            'job_id' => 'job-7',
            'status' => 'completed',
            'segments' => 0,
            'playlist_ready' => true,
            'progress' => 100.0,
            'subtitles' => [],
        ]);
        $manager->expects($this->once())
            ->method('getJobVariants')
            ->with('job-7')
            ->willReturn($this->variantsFixture());
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => 'job-7']);
        $this->assertSame(200, $response->statusCode);
        /** @var array{variants: array<int, array{url: string}>} $body */
        $body = json_decode($response->body, true);
        $this->assertCount(2, $body['variants']);
        $this->assertSignedUrlFor('/hls/job-7/media_v1080p.m3u8', $body['variants'][0]['url']);
        $this->assertSignedUrlFor('/hls/job-7/media_v720p.m3u8', $body['variants'][1]['url']);
    }

    public function testStatusIncludesNullVariantsForLegacyJob(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobReadiness')->willReturn([
            'job_id' => 'job-7',
            'status' => 'completed',
            'segments' => 0,
            'playlist_ready' => true,
            'progress' => 100.0,
            'subtitles' => [],
        ]);
        $manager->method('getJobVariants')->with('job-7')->willReturn(null);
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => 'job-7']);
        $this->assertSame(200, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('variants', $body);
        $this->assertNull($body['variants']);
        // S11 regression guard: the status() payload must not advertise a
        // `dash_url` that always 404s (real DASH is S56-S60).
        $this->assertArrayNotHasKey('dash_url', $body);
    }

    /**
     * A getJobVariants() list as TranscodeManager would return it — flat
     * Rendition shapes with RELATIVE, UNSIGNED urls (the controller signs them).
     *
     * @return list<array<string, mixed>>
     */
    private function variantsFixture(): array
    {
        return [
            [
                'id' => '1080p', 'label' => '1080p', 'width' => 1920, 'height' => 1080,
                'bitrate' => 5480000, 'codecs' => 'avc1.640029,mp4a.40.2',
                'url' => '/hls/job-7/media_v1080p.m3u8',
                'is_original' => false, 'is_copy' => false, 'video_bitrate' => 5000000,
            ],
            [
                'id' => '720p', 'label' => '720p', 'width' => 1280, 'height' => 720,
                'bitrate' => 3124000, 'codecs' => 'avc1.640029,mp4a.40.2',
                'url' => '/hls/job-7/media_v720p.m3u8',
                'is_original' => false, 'is_copy' => false, 'video_bitrate' => 2800000,
            ],
        ];
    }

    public function testStartReturns400WhenMediaIdEmpty(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureHlsJob');
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => '']);
        $this->assertSame(400, $response->statusCode);
    }

    public function testStartReturns404WhenItemMissing(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureHlsJob')->willThrowException(new \InvalidArgumentException('Media item not found'));
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'missing']);
        $this->assertSame(404, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Media item not found', $body['error']);
    }

    public function testStartReturns503WhenConcurrencyExhausted(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureHlsJob')
            ->willThrowException(new \RuntimeException('Maximum concurrent transcodes (4) reached'));
        $controller = new TranscodeController($manager);

        $response = $controller->start(new Request(), ['id' => 'media-1']);
        $this->assertSame(503, $response->statusCode);
    }

    public function testStatusReturnsReadiness(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('getJobReadiness')
            ->with('job-7')
            ->willReturn([
                'job_id' => 'job-7',
                'status' => 'running',
                'segments' => 3,
                'playlist_ready' => true,
                'progress' => 3.0,
                'subtitles' => [
                    ['index' => 0, 'language' => 'eng', 'label' => 'English', 'default' => true,
                        'url' => '/hls/job-7/sub-0.vtt'],
                ],
            ]);
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => 'job-7']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{status: mixed, playlist_ready: mixed, master_url: string, subtitles: array<int, array{url: string}>} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('running', $body['status']);
        $this->assertTrue($body['playlist_ready']);
        $this->assertSignedUrlFor('/hls/job-7/master.m3u8', $body['master_url']);
        $this->assertSignedUrlFor('/hls/job-7/sub-0.vtt', $body['subtitles'][0]['url']);
    }

    /**
     * Asserts a URL is `$expectedPath` plus a valid `exp`/`sig` signature.
     */
    private function assertSignedUrlFor(string $expectedPath, string $url): void
    {
        $this->assertStringStartsWith($expectedPath . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $exp = $q['exp'] ?? '';
        $sig = $q['sig'] ?? '';
        $this->assertTrue(
            \Phlix\Auth\SignedUrl::fromEnv()->verify(
                $expectedPath,
                is_string($exp) ? $exp : '',
                is_string($sig) ? $sig : '',
            ),
            "{$url} must carry a valid signature for {$expectedPath}",
        );
    }

    public function testStatusReturns404WhenJobUnknown(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobReadiness')->willReturn([
            'job_id' => 'nope',
            'status' => 'not_found',
            'segments' => 0,
            'playlist_ready' => false,
            'progress' => 0.0,
        ]);
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => 'nope']);
        $this->assertSame(404, $response->statusCode);
    }

    public function testStatusReturns400WhenJobIdEmpty(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('getJobReadiness');
        $controller = new TranscodeController($manager);

        $response = $controller->status(new Request(), ['jobId' => '']);
        $this->assertSame(400, $response->statusCode);
    }
}
