<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal\Controllers;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\AudiobookPageController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AudiobookPageController}.
 *
 * @covers \Phlix\Server\WebPortal\Controllers\AudiobookPageController
 */
final class AudiobookPageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_detail_returns_404_when_missing(): void
    {
        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('nope')->andReturn(null);
        $library = Mockery::mock(LibraryManager::class);

        $controller = new AudiobookPageController($itemRepo, $library, $this->noSmartyDir());
        $response = $controller->detail($this->makeRequest(), ['id' => 'nope']);

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
            ->andReturn([$this->audiobookItem()]);
        $library = Mockery::mock(LibraryManager::class);
        $library->shouldReceive('getAllLibraries')->andReturn([['id' => 'lib1', 'type' => 'audiobook']]);

        $controller = new AudiobookPageController($itemRepo, $library, $this->realTemplateDir());
        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('The Long Story', $response->body);
    }

    /**
     * @group integration
     */
    public function test_detail_renders_with_chapters(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('a1')->andReturn($this->audiobookItem());
        $library = Mockery::mock(LibraryManager::class);

        $controller = new AudiobookPageController($itemRepo, $library, $this->realTemplateDir());
        $response = $controller->detail($this->makeRequest(), ['id' => 'a1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('The Long Story', $response->body);
        $this->assertStringContainsString('Chapter One', $response->body);
    }

    /**
     * Detail must not error when the item has no chapters key in metadata
     * (the template calls count() and iterates over it).
     *
     * @group integration
     */
    public function test_detail_renders_without_chapters_key(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('a2')->andReturn([
            'id' => 'a2',
            'type' => 'audiobook',
            'name' => 'No Chapters',
            'metadata' => ['author' => 'Anon'],
        ]);
        $library = Mockery::mock(LibraryManager::class);

        $controller = new AudiobookPageController($itemRepo, $library, $this->realTemplateDir());
        $response = $controller->detail($this->makeRequest(), ['id' => 'a2']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('No Chapters', $response->body);
    }

    /**
     * @group integration
     */
    public function test_player_renders(): void
    {
        $this->skipWithoutSmarty();

        $itemRepo = Mockery::mock(ItemRepository::class);
        $itemRepo->shouldReceive('findById')->with('a1')->andReturn($this->audiobookItem());
        $library = Mockery::mock(LibraryManager::class);

        $controller = new AudiobookPageController($itemRepo, $library, $this->realTemplateDir());
        $response = $controller->player($this->makeRequest(), ['id' => 'a1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('audiobook-player', $response->body);
    }

    /**
     * @return array<string,mixed>
     */
    private function audiobookItem(): array
    {
        return [
            'id' => 'a1',
            'type' => 'audiobook',
            'name' => 'The Long Story',
            'metadata' => [
                'author' => 'Tom Teller',
                'duration_ms' => 3600000,
                'language' => 'en',
                'chapters' => [
                    ['title' => 'Chapter One', 'start_ms' => 0, 'end_ms' => 1800000, 'duration_ms' => 1800000],
                    ['title' => 'Chapter Two', 'start_ms' => 1800000, 'end_ms' => 3600000, 'duration_ms' => 1800000],
                ],
            ],
        ];
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/audiobooks';
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
        return sys_get_temp_dir() . '/phlix_audiobook_no_smarty_' . uniqid('', true);
    }

    private function skipWithoutSmarty(): void
    {
        if (!class_exists('Smarty')) {
            $this->markTestSkipped('Smarty runtime class not available; skipping render test.');
        }
    }
}
