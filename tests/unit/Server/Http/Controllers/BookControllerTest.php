<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Metadata\OpdsFeedBuilder;
use Phlex\Server\Http\Controllers\BookController;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;

/**
 * Unit tests for BookController.
 *
 * @covers \Phlex\Server\Http\Controllers\BookController
 * @since 0.17.0
 */
class BookControllerTest extends TestCase
{
    private BookController $controller;
    private ItemRepository $itemRepo;
    private LibraryManager $libraryManager;
    private OpdsFeedBuilder $opdsBuilder;

    protected function setUp(): void
    {
        $this->itemRepo = $this->createMock(ItemRepository::class);
        $this->libraryManager = $this->createMock(LibraryManager::class);
        $this->opdsBuilder = new OpdsFeedBuilder($this->itemRepo, 'http://localhost:8080');
        $this->controller = new BookController(
            $this->itemRepo,
            $this->libraryManager,
            $this->opdsBuilder
        );
    }

    /**
     * @test
     */
    public function testOpdsRootReturnsOpdsXml(): void
    {
        $request = new Request();
        $response = $this->controller->opdsRoot($request);

        $this->assertInstanceOf(Response::class, $response);
        $contentType = $response->headers['Content-Type'] ?? '';
        $this->assertStringContainsString('application/atom+xml', $contentType);
        $this->assertStringContainsString('opds-catalog', $contentType);
    }

    /**
     * @test
     */
    public function testOpdsLibrariesReturnsNavigationFeed(): void
    {
        // Setup mock to return book libraries
        $this->libraryManager->method('getAllLibraries')
            ->willReturn([
                ['id' => 'lib-book-1', 'name' => 'My Books', 'type' => 'book'],
            ]);

        $request = new Request();
        $response = $this->controller->opdsLibraries($request);

        $this->assertInstanceOf(Response::class, $response);
        $contentType = $response->headers['Content-Type'] ?? '';
        $this->assertStringContainsString('application/atom+xml', $contentType);
        $this->assertStringContainsString('kind=navigation', $contentType);
    }

    /**
     * @test
     */
    public function testOpdsLibraryBooksReturnsAcquisitionFeed(): void
    {
        // Setup mock to return a book library
        $this->libraryManager->method('getLibrary')
            ->willReturn(['id' => 'lib-book-1', 'name' => 'My Books', 'type' => 'book']);

        // Setup mock to return some books
        $this->itemRepo->method('getByLibrary')
            ->willReturn([
                [
                    'id' => 'book-1',
                    'library_id' => 'lib-book-1',
                    'name' => 'Test Book',
                    'type' => 'book',
                    'path' => '/books/test.epub',
                    'metadata' => ['title' => 'Test Book'],
                ],
            ]);

        $request = new Request();
        $params = ['id' => 'lib-book-1'];
        $response = $this->controller->opdsLibraryBooks($request, $params);

        $this->assertInstanceOf(Response::class, $response);
        $contentType = $response->headers['Content-Type'] ?? '';
        $this->assertStringContainsString('application/atom+xml', $contentType);
        $this->assertStringContainsString('kind=acquisition', $contentType);
    }

    /**
     * @test
     */
    public function testGetBookReturnsJson(): void
    {
        $book = [
            'id' => 'book-123',
            'name' => 'Test Book',
            'type' => 'book',
            'path' => '/books/test.epub',
            'metadata' => ['title' => 'Test Book'],
        ];

        $this->itemRepo->method('findById')
            ->willReturn($book);

        $request = new Request();
        $params = ['id' => 'book-123'];
        $response = $this->controller->getBook($request, $params);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('book', $body);
        $this->assertEquals('Test Book', $body['book']['name']);
    }

    /**
     * @test
     */
    public function testGetBookReturns404ForNonExistent(): void
    {
        $this->itemRepo->method('findById')
            ->willReturn(null);

        $request = new Request();
        $params = ['id' => 'non-existent'];
        $response = $this->controller->getBook($request, $params);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(404, $response->statusCode);
    }

    /**
     * @test
     */
    public function testDownloadBookReturnsFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlex_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $bookPath = $tempDir . '/test.epub';
        file_put_contents($bookPath, 'minimal epub content');

        $book = [
            'id' => 'book-123',
            'name' => 'Test Book',
            'type' => 'book',
            'path' => $bookPath,
            'metadata' => [],
        ];

        $this->itemRepo->method('findById')
            ->willReturn($book);

        $request = new Request();
        $params = ['id' => 'book-123'];
        $response = $this->controller->downloadBook($request, $params);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode);
        $contentDisposition = $response->headers['Content-Disposition'] ?? '';
        $this->assertStringContainsString('attachment', $contentDisposition);

        // Clean up
        unlink($bookPath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testListBooksReturnsJson(): void
    {
        $books = [
            [
                'id' => 'book-1',
                'name' => 'Test Book 1',
                'type' => 'book',
                'path' => '/books/test1.epub',
                'metadata' => [],
            ],
            [
                'id' => 'book-2',
                'name' => 'Test Book 2',
                'type' => 'book',
                'path' => '/books/test2.pdf',
                'metadata' => [],
            ],
        ];

        $this->itemRepo->method('getByLibrary')
            ->willReturn($books);

        $this->itemRepo->method('searchFuzzy')
            ->willReturn($books);

        $request = new Request();
        $response = $this->controller->listBooks($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('books', $body);
    }

    /**
     * @test
     */
    public function testReadBookReturnsReaderStub(): void
    {
        $book = [
            'id' => 'book-123',
            'name' => 'Test Book',
            'type' => 'book',
            'path' => '/books/test.epub',
            'metadata' => ['title' => 'Test Book'],
        ];

        $this->itemRepo->method('findById')
            ->willReturn($book);

        $request = new Request();
        $params = ['id' => 'book-123'];

        // Should return JSON with book info for client-side rendering
        $response = $this->controller->readBook($request, $params);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->statusCode);
    }
}
