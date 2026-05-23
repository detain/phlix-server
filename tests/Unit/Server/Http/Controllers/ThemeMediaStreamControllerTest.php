<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Controllers\ThemeMediaStreamController;
use Phlix\Server\Http\Request;
use Phlix\Theming\ThemeAudio;
use Phlix\Theming\ThemeMedia;
use Phlix\Theming\ThemeMediaRepository;
use Phlix\Theming\ThemeVideo;

/**
 * Unit tests for {@see ThemeMediaStreamController}.
 *
 * Covers the two handler methods now wired in Application::loadLibraryRoutes():
 *   GET /stream/theme-media/{libraryId}/audio -> streamAudio
 *   GET /stream/theme-media/{libraryId}/video -> streamVideo
 *
 * Uses createMock() for dependencies following the project's existing
 * controller-test conventions.
 */
class ThemeMediaStreamControllerTest extends TestCase
{
    /**
     * Happy path: streamAudio() returns 200 with audio content when theme audio exists.
     */
    public function testStreamAudioReturns200WithAudioContent(): void
    {
        // Create a temp file to serve
        $tempFile = tempnam(sys_get_temp_dir(), 'theme_audio_');
        file_put_contents($tempFile, 'fake mp3 audio content');

        try {
            $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
            $themeMediaRepository->expects($this->once())
                ->method('findByLibraryId')
                ->with('lib-1')
                ->willReturn(new ThemeMedia(
                    libraryId: 'lib-1',
                    audio: new ThemeAudio($tempFile, '/stream/theme-media/lib-1/audio', 120, 'mp3'),
                    video: null,
                    scannedAt: new \DateTimeImmutable()
                ));

            $controller = new ThemeMediaStreamController($themeMediaRepository);

            $request = new Request();

            $response = $controller->streamAudio($request, ['libraryId' => 'lib-1']);

            $this->assertSame(200, $response->statusCode);
            $this->assertSame('audio/mpeg', $response->headers['Content-Type']);
            $this->assertSame('fake mp3 audio content', $response->body);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Negative: streamAudio() returns 400 when library ID is empty.
     */
    public function testStreamAudioReturns400WhenLibraryIdEmpty(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->never())->method('findByLibraryId');

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamAudio($request, ['libraryId' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library ID is required', $body['error']);
    }

    /**
     * Negative: streamAudio() returns 404 when no theme media found for library.
     */
    public function testStreamAudioReturns404WhenNoThemeMediaFound(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(null);

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamAudio($request, ['libraryId' => 'lib-1']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Theme audio not found', $body['error']);
    }

    /**
     * Negative: streamAudio() returns 404 when theme audio is null.
     */
    public function testStreamAudioReturns404WhenAudioIsNull(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(new ThemeMedia(
                libraryId: 'lib-1',
                audio: null,
                video: new ThemeVideo('/path/to/backdrop.mp4', '/stream/theme-media/lib-1/video', 300, 1920, 1080, 'mp4'),
                scannedAt: new \DateTimeImmutable()
            ));

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamAudio($request, ['libraryId' => 'lib-1']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Theme audio not found', $body['error']);
    }

    /**
     * Negative: streamAudio() returns 404 when audio file does not exist on disk.
     */
    public function testStreamAudioReturns404WhenFileNotOnDisk(): void
    {
        $nonExistentFile = '/nonexistent/path/to/theme.mp3';

        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(new ThemeMedia(
                libraryId: 'lib-1',
                audio: new ThemeAudio($nonExistentFile, '/stream/theme-media/lib-1/audio', 120, 'mp3'),
                video: null,
                scannedAt: new \DateTimeImmutable()
            ));

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamAudio($request, ['libraryId' => 'lib-1']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Theme audio file not found on disk', $body['error']);
    }

    /**
     * Happy path: streamVideo() returns 200 with video content when theme video exists.
     */
    public function testStreamVideoReturns200WithVideoContent(): void
    {
        // Create a temp file to serve
        $tempFile = tempnam(sys_get_temp_dir(), 'theme_video_');
        file_put_contents($tempFile, 'fake mp4 video content');

        try {
            $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
            $themeMediaRepository->expects($this->once())
                ->method('findByLibraryId')
                ->with('lib-1')
                ->willReturn(new ThemeMedia(
                    libraryId: 'lib-1',
                    audio: null,
                    video: new ThemeVideo($tempFile, '/stream/theme-media/lib-1/video', 300, 1920, 1080, 'mp4'),
                    scannedAt: new \DateTimeImmutable()
                ));

            $controller = new ThemeMediaStreamController($themeMediaRepository);

            $request = new Request();

            $response = $controller->streamVideo($request, ['libraryId' => 'lib-1']);

            $this->assertSame(200, $response->statusCode);
            $this->assertSame('video/mp4', $response->headers['Content-Type']);
            $this->assertSame('fake mp4 video content', $response->body);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Negative: streamVideo() returns 400 when library ID is empty.
     */
    public function testStreamVideoReturns400WhenLibraryIdEmpty(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->never())->method('findByLibraryId');

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamVideo($request, ['libraryId' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Library ID is required', $body['error']);
    }

    /**
     * Negative: streamVideo() returns 404 when no theme media found for library.
     */
    public function testStreamVideoReturns404WhenNoThemeMediaFound(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(null);

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamVideo($request, ['libraryId' => 'lib-1']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Theme video not found', $body['error']);
    }

    /**
     * Negative: streamVideo() returns 404 when theme video is null.
     */
    public function testStreamVideoReturns404WhenVideoIsNull(): void
    {
        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(new ThemeMedia(
                libraryId: 'lib-1',
                audio: new ThemeAudio('/path/to/theme.mp3', '/stream/theme-media/lib-1/audio', 120, 'mp3'),
                video: null,
                scannedAt: new \DateTimeImmutable()
            ));

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamVideo($request, ['libraryId' => 'lib-1']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Theme video not found', $body['error']);
    }

    /**
     * Negative: streamVideo() returns 404 when video file does not exist on disk.
     */
    public function testStreamVideoReturns404WhenFileNotOnDisk(): void
    {
        $nonExistentFile = '/nonexistent/path/to/backdrop.mp4';

        $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
        $themeMediaRepository->expects($this->once())
            ->method('findByLibraryId')
            ->with('lib-1')
            ->willReturn(new ThemeMedia(
                libraryId: 'lib-1',
                audio: null,
                video: new ThemeVideo($nonExistentFile, '/stream/theme-media/lib-1/video', 300, 1920, 1080, 'mp4'),
                scannedAt: new \DateTimeImmutable()
            ));

        $controller = new ThemeMediaStreamController($themeMediaRepository);

        $request = new Request();

        $response = $controller->streamVideo($request, ['libraryId' => 'lib-1']);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Theme video file not found on disk', $body['error']);
    }

    /**
     * Verifies correct content types for different audio formats.
     *
     * @dataProvider audioFormatProvider
     */
    public function testStreamAudioReturnsCorrectContentType(string $format, string $expectedContentType): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'theme_audio_');
        file_put_contents($tempFile, 'fake audio content');

        try {
            $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
            $themeMediaRepository->method('findByLibraryId')
                ->willReturn(new ThemeMedia(
                    libraryId: 'lib-1',
                    audio: new ThemeAudio($tempFile, '/stream/theme-media/lib-1/audio', 120, $format),
                    video: null,
                    scannedAt: new \DateTimeImmutable()
                ));

            $controller = new ThemeMediaStreamController($themeMediaRepository);
            $request = new Request();
            $response = $controller->streamAudio($request, ['libraryId' => 'lib-1']);

            $this->assertSame($expectedContentType, $response->headers['Content-Type']);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Verifies correct content types for different video formats.
     *
     * @dataProvider videoFormatProvider
     */
    public function testStreamVideoReturnsCorrectContentType(string $format, string $expectedContentType): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'theme_video_');
        file_put_contents($tempFile, 'fake video content');

        try {
            $themeMediaRepository = $this->createMock(ThemeMediaRepository::class);
            $themeMediaRepository->method('findByLibraryId')
                ->willReturn(new ThemeMedia(
                    libraryId: 'lib-1',
                    audio: null,
                    video: new ThemeVideo($tempFile, '/stream/theme-media/lib-1/video', 300, 1920, 1080, $format),
                    scannedAt: new \DateTimeImmutable()
                ));

            $controller = new ThemeMediaStreamController($themeMediaRepository);
            $request = new Request();
            $response = $controller->streamVideo($request, ['libraryId' => 'lib-1']);

            $this->assertSame($expectedContentType, $response->headers['Content-Type']);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Data provider for audio format to content type mapping.
     */
    public static function audioFormatProvider(): array
    {
        return [
            'mp3' => ['mp3', 'audio/mpeg'],
            'ogg' => ['ogg', 'audio/ogg'],
            'aac' => ['aac', 'audio/aac'],
            'wav' => ['wav', 'audio/wav'],
            'flac' => ['flac', 'audio/flac'],
            'unknown' => ['xyz', 'application/octet-stream'],
        ];
    }

    /**
     * Data provider for video format to content type mapping.
     */
    public static function videoFormatProvider(): array
    {
        return [
            'mp4' => ['mp4', 'video/mp4'],
            'webm' => ['webm', 'video/webm'],
            'mkv' => ['mkv', 'video/x-matroska'],
            'avi' => ['avi', 'video/x-msvideo'],
            'mov' => ['mov', 'video/quicktime'],
            'unknown' => ['xyz', 'application/octet-stream'],
        ];
    }
}
