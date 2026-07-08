<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal\Controllers;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\Expectation;
use Mockery\MockInterface;
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
        /** @var ItemRepository&MockInterface $itemRepo */
        $itemRepo = Mockery::mock(ItemRepository::class);
        /** @var Expectation $findById */
        $findById = $itemRepo->shouldReceive('findById');
        $findById->with('x1')->andReturn(['id' => 'x1', 'type' => 'movie']);
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

        /** @var PhotoLibraryManager&MockInterface $photoManager */
        $photoManager = Mockery::mock(PhotoLibraryManager::class);
        /** @var Expectation $grouped */
        $grouped = $photoManager->shouldReceive('getPhotosGroupedByDate');
        $grouped->with('lib1')
            ->andReturn(['2020-01-01' => [$this->photoItem()]]);
        /** @var LibraryManager&MockInterface $library */
        $library = Mockery::mock(LibraryManager::class);
        /** @var Expectation $getAllLibraries */
        $getAllLibraries = $library->shouldReceive('getAllLibraries');
        $getAllLibraries->andReturn([['id' => 'lib1', 'type' => 'photo']]);

        /** @var ItemRepository&MockInterface $itemRepo */
        $itemRepo = Mockery::mock(ItemRepository::class);
        /** @var ExifProvider&MockInterface $exif */
        $exif = Mockery::mock(ExifProvider::class);
        $controller = new PhotoPageController(
            $itemRepo,
            $photoManager,
            $exif,
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

        /** @var PhotoLibraryManager&MockInterface $photoManager */
        $photoManager = Mockery::mock(PhotoLibraryManager::class);
        /** @var Expectation $grouped */
        $grouped = $photoManager->shouldReceive('getPhotosGroupedByDate');
        $grouped->with('lib1')
            ->andReturn(['2020-01-01' => [$this->photoItem()]]);

        /** @var ItemRepository&MockInterface $itemRepo */
        $itemRepo = Mockery::mock(ItemRepository::class);
        /** @var ExifProvider&MockInterface $exif */
        $exif = Mockery::mock(ExifProvider::class);
        /** @var LibraryManager&MockInterface $library */
        $library = Mockery::mock(LibraryManager::class);
        $controller = new PhotoPageController(
            $itemRepo,
            $photoManager,
            $exif,
            $library,
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

        /** @var ItemRepository&MockInterface $itemRepo */
        $itemRepo = Mockery::mock(ItemRepository::class);
        /** @var Expectation $findById */
        $findById = $itemRepo->shouldReceive('findById');
        $findById->with('p1')->andReturn($this->photoItem());
        /** @var ExifProvider&MockInterface $exif */
        $exif = Mockery::mock(ExifProvider::class);
        /** @var Expectation $photoMeta */
        $photoMeta = $exif->shouldReceive('getPhotoMetadata');
        $photoMeta->with('p1')->andReturn([
            'camera_model' => 'Canon EOS',
            'iso' => 200,
            'width' => 4000,
            'height' => 3000,
        ]);

        /** @var PhotoLibraryManager&MockInterface $photoManager */
        $photoManager = Mockery::mock(PhotoLibraryManager::class);
        /** @var LibraryManager&MockInterface $library */
        $library = Mockery::mock(LibraryManager::class);
        $controller = new PhotoPageController(
            $itemRepo,
            $photoManager,
            $exif,
            $library,
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

        /** @var ItemRepository&MockInterface $itemRepo */
        $itemRepo = Mockery::mock(ItemRepository::class);
        /** @var Expectation $getByLibrary */
        $getByLibrary = $itemRepo->shouldReceive('getByLibrary');
        $getByLibrary->with('lib1', Mockery::any(), Mockery::any())
            ->andReturn([$this->photoItem()]);

        /** @var PhotoLibraryManager&MockInterface $photoManager */
        $photoManager = Mockery::mock(PhotoLibraryManager::class);
        /** @var ExifProvider&MockInterface $exif */
        $exif = Mockery::mock(ExifProvider::class);
        /** @var LibraryManager&MockInterface $library */
        $library = Mockery::mock(LibraryManager::class);
        $controller = new PhotoPageController(
            $itemRepo,
            $photoManager,
            $exif,
            $library,
            $this->realTemplateDir(),
        );

        $response = $controller->slideshow($this->makeRequest(['library_id' => 'lib1']), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('slideshow-page', $response->body);
    }

    private function controller(string $templateDir, ?ItemRepository $itemRepo = null): PhotoPageController
    {
        if ($itemRepo === null) {
            /** @var ItemRepository&MockInterface $itemRepo */
            $itemRepo = Mockery::mock(ItemRepository::class);
        }
        /** @var PhotoLibraryManager&MockInterface $photoManager */
        $photoManager = Mockery::mock(PhotoLibraryManager::class);
        /** @var ExifProvider&MockInterface $exif */
        $exif = Mockery::mock(ExifProvider::class);
        /** @var LibraryManager&MockInterface $library */
        $library = Mockery::mock(LibraryManager::class);

        return new PhotoPageController(
            $itemRepo,
            $photoManager,
            $exif,
            $library,
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
