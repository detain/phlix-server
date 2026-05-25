<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal\Controllers;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\BookPageController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see BookPageController}.
 *
 * @covers \Phlix\Server\WebPortal\Controllers\BookPageController
 */
final class BookPageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_detail_returns_404_when_missing(): void
    {
        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('nope')->andReturn(null);
        $library = Mockery::mock(LibraryManager::class);

        $controller = new BookPageController($itemRepo, $library, $this->noSmartyDir());
        $response = $controller->detail($this->makeRequest(), ['id' => 'nope']);

        $this->assertSame(404, $response->statusCode);
    }

    public function test_detail_returns_404_when_not_a_book(): void
    {
        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('m1')->andReturn(['id' => 'm1', 'type' => 'movie']);
        $library = Mockery::mock(LibraryManager::class);

        $controller = new BookPageController($itemRepo, $library, $this->noSmartyDir());
        $response = $controller->detail($this->makeRequest(), ['id' => 'm1']);

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * @group integration
     */
    public function test_index_renders_grid(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('getByLibrary')->with('lib1', Mockery::any(), Mockery::any())
            ->andReturn([$this->bookItem()]);
        $library = Mockery::mock(LibraryManager::class);
        $library->shouldReceive('getAllLibraries')->andReturn([['id' => 'lib1', 'type' => 'book']]);

        $controller = new BookPageController($itemRepo, $library, $this->realTemplateDir());
        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Pro PHP', $response->body);
    }

    /**
     * @group integration
     */
    public function test_detail_renders_book(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('b1')->andReturn($this->bookItem());
        $library = Mockery::mock(LibraryManager::class);

        $controller = new BookPageController($itemRepo, $library, $this->realTemplateDir());
        $response = $controller->detail($this->makeRequest(), ['id' => 'b1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Pro PHP', $response->body);
        $this->assertStringContainsString('/books/b1/read', $response->body);
    }

    /**
     * @group integration
     */
    public function test_reader_renders(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('b1')->andReturn($this->bookItem());
        $library = Mockery::mock(LibraryManager::class);

        $controller = new BookPageController($itemRepo, $library, $this->realTemplateDir());
        $response = $controller->reader($this->makeRequest(), ['id' => 'b1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('reader-page', $response->body);
    }

    /**
     * @return array<string,mixed>
     */
    private function bookItem(): array
    {
        return [
            'id' => 'b1',
            'type' => 'book',
            'name' => 'Pro PHP',
            'metadata' => [
                'author' => 'Jane Dev',
                'page_count' => 320,
                'description' => 'All about PHP.',
            ],
        ];
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/books';
        $request->headers = [];
        $request->query = [];
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
        return sys_get_temp_dir() . '/phlix_book_no_smarty_' . uniqid('', true);
    }

    private function skipWithoutSmarty(): void
    {
        if (!class_exists('Smarty')) {
            $this->markTestSkipped('Smarty runtime class not available; skipping render test.');
        }
    }
}
