<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal\Controllers;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Media\Metadata\ExifProvider;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\PhotoPageController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PhotoPageController}.
 *
 * @covers \Phlix\Server\WebPortal\Controllers\PhotoPageController
 */
final class PhotoPageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_album_returns_400_without_library_id(): void
    {
        $controller = $this->controller($this->noSmartyDir());
        $response = $controller->album($this->makeRequest(), ['id' => 'whatever']);

        $this->assertSame(400, $response->statusCode);
    }

    public function test_slideshow_returns_400_without_library_id(): void
    {
        $controller = $this->controller($this->noSmartyDir());
        $response = $controller->slideshow($this->makeRequest(), []);

        $this->assertSame(400, $response->statusCode);
    }

    public function test_photo_returns_404_when_not_a_photo(): void
    {
        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('x1')->andReturn(['id' => 'x1', 'type' => 'movie']);
        $controller = $this->controller($this->noSmartyDir(), $itemRepo);

        $response = $controller->photo($this->makeRequest(), ['id' => 'x1']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * @group integration
     */
    public function test_albums_renders_grid(): void
    {
        $this->skipWithoutSmarty();

        $photoManager = Mockery::mock(PhotoLibraryManager::class);
        $photoManager->shouldReceive('getPhotosGroupedByDate')->with('lib1')
            ->andReturn(['2020-01-01' => [$this->photoItem()]]);
        $library = Mockery::mock(LibraryManager::class);
        $library->shouldReceive('getAllLibraries')->andReturn([['id' => 'lib1', 'type' => 'photo']]);

        $controller = new PhotoPageController(
            Mockery::mock(ItemRepository::class),
            $photoManager,
            Mockery::mock(ExifProvider::class),
            $library,
            $this->realTemplateDir(),
        );

        $response = $controller->albums($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('2020-01-01', $response->body);
    }

    /**
     * @group integration
     */
    public function test_album_renders_when_found(): void
    {
        $this->skipWithoutSmarty();

        $photoManager = Mockery::mock(PhotoLibraryManager::class);
        $photoManager->shouldReceive('getPhotosGroupedByDate')->with('lib1')
            ->andReturn(['2020-01-01' => [$this->photoItem()]]);

        $controller = new PhotoPageController(
            Mockery::mock(ItemRepository::class),
            $photoManager,
            Mockery::mock(ExifProvider::class),
            Mockery::mock(LibraryManager::class),
            $this->realTemplateDir(),
        );

        $albumId = md5('2020-01-01');
        $response = $controller->album($this->makeRequest(['library_id' => 'lib1']), ['id' => $albumId]);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('2020-01-01', $response->body);
    }

    /**
     * @group integration
     */
    public function test_photo_renders_with_exif(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('p1')->andReturn($this->photoItem());
        $exif = Mockery::mock(ExifProvider::class);
        $exif->shouldReceive('getPhotoMetadata')->with('p1')->andReturn([
            'camera_model' => 'Canon EOS',
            'iso' => 200,
            'width' => 4000,
            'height' => 3000,
        ]);

        $controller = new PhotoPageController(
            $itemRepo,
            Mockery::mock(PhotoLibraryManager::class),
            $exif,
            Mockery::mock(LibraryManager::class),
            $this->realTemplateDir(),
        );

        $response = $controller->photo($this->makeRequest(['library_id' => 'lib1', 'album_id' => 'al1']), ['id' => 'p1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Canon EOS', $response->body);
        $this->assertStringContainsString('Beach.jpg', $response->body);
    }

    /**
     * @group integration
     */
    public function test_slideshow_renders(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('getByLibrary')->with('lib1', Mockery::any(), Mockery::any())
            ->andReturn([$this->photoItem()]);

        $controller = new PhotoPageController(
            $itemRepo,
            Mockery::mock(PhotoLibraryManager::class),
            Mockery::mock(ExifProvider::class),
            Mockery::mock(LibraryManager::class),
            $this->realTemplateDir(),
        );

        $response = $controller->slideshow($this->makeRequest(['library_id' => 'lib1']), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('slideshow-page', $response->body);
    }

    private function controller(string $templateDir, ?ItemRepository $itemRepo = null): PhotoPageController
    {
        return new PhotoPageController(
            $itemRepo ?? Mockery::mock(ItemRepository::class),
            Mockery::mock(PhotoLibraryManager::class),
            Mockery::mock(ExifProvider::class),
            Mockery::mock(LibraryManager::class),
            $templateDir,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function photoItem(): array
    {
        return [
            'id' => 'p1',
            'type' => 'photo',
            'name' => 'Beach.jpg',
            'path' => '/photos/beach.jpg',
            'library_id' => 'lib1',
            'metadata' => [
                'date_taken_unix' => 1577836800,
                'camera_model' => 'Canon EOS',
            ],
        ];
    }

    /**
     * @param array<string,string> $query
     */
    private function makeRequest(array $query = []): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/photo/albums';
        $request->headers = [];
        $request->query = $query;
        $request->body = [];
        $request->files = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        return $request;
    }

    private function realTemplateDir(): string
    {
        return dirname(__DIR__, 5) . '/public/templates';
    }

    private function noSmartyDir(): string
    {
        return sys_get_temp_dir() . '/phlix_photo_no_smarty_' . uniqid('', true);
    }

    private function skipWithoutSmarty(): void
    {
        if (!class_exists('Smarty')) {
            $this->markTestSkipped('Smarty runtime class not available; skipping render test.');
        }
    }
}
