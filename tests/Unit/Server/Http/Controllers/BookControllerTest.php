<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Metadata\OpdsFeedBuilder;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Unit tests for BookController.
 *
 * @covers \Phlix\Server\Http\Controllers\BookController
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
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
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

        // Jail the file's directory so the LibraryRootGuard treats it as a
        // legitimate in-library path regardless of host HOME/tmp layout.
        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . $tempDir);
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $request = new Request();
            $params = ['id' => 'book-123'];
            $response = $this->controller->downloadBook($request, $params);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals(200, $response->statusCode);
            $contentDisposition = $response->headers['Content-Disposition'] ?? '';
            $this->assertStringContainsString('attachment', $contentDisposition);
        } finally {
            // Clean up
            unlink($bookPath);
            rmdir($tempDir);
            if ($prevRoots === false) {
                putenv('PHLIX_LIBRARY_ROOTS');
            } else {
                putenv('PHLIX_LIBRARY_ROOTS=' . $prevRoots);
            }
            \Phlix\Common\Fs\LibraryRootGuard::reset();
        }
    }

    /**
     * S11/C4 path jail: a poisoned book path pointing OUTSIDE the configured
     * library roots (e.g. /etc/passwd) must yield 404 on download — never read
     * the file and never disclose its existence.
     *
     * @test
     */
    public function testDownloadBookReturns404WhenPathEscapesLibraryRoots(): void
    {
        $this->itemRepo->method('findById')->willReturn([
            'id' => 'book-evil',
            'name' => 'Poisoned',
            'type' => 'book',
            // A real, readable file that is NOT under any library root.
            'path' => '/etc/passwd',
            'metadata' => [],
        ]);

        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . sys_get_temp_dir());
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $response = $this->controller->downloadBook(new Request(), ['id' => 'book-evil']);
            $this->assertEquals(404, $response->statusCode);
            $body = json_decode($response->body, true);
            $this->assertArrayHasKey('error', $body);
        } finally {
            if ($prevRoots === false) {
                putenv('PHLIX_LIBRARY_ROOTS');
            } else {
                putenv('PHLIX_LIBRARY_ROOTS=' . $prevRoots);
            }
            \Phlix\Common\Fs\LibraryRootGuard::reset();
        }
    }

    /**
     * S11/C4 path jail: a legitimate in-library cover_path serves the image.
     *
     * @test
     */
    public function testGetCoverServesLegitInLibraryCover(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_cover_' . uniqid();
        mkdir($tempDir, 0755, true);
        $coverPath = $tempDir . '/cover.png';
        file_put_contents($coverPath, 'PNGDATA');

        $this->itemRepo->method('findById')->willReturn([
            'id' => 'book-123',
            'name' => 'Test Book',
            'type' => 'book',
            'path' => $tempDir . '/test.epub',
            'metadata' => ['cover_path' => $coverPath],
        ]);

        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . $tempDir);
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $response = $this->controller->getCover(new Request(), ['id' => 'book-123']);
            $this->assertEquals(200, $response->statusCode);
            $this->assertEquals('image/png', $response->headers['Content-Type'] ?? '');
            $this->assertEquals('PNGDATA', $response->body);
        } finally {
            unlink($coverPath);
            rmdir($tempDir);
            if ($prevRoots === false) {
                putenv('PHLIX_LIBRARY_ROOTS');
            } else {
                putenv('PHLIX_LIBRARY_ROOTS=' . $prevRoots);
            }
            \Phlix\Common\Fs\LibraryRootGuard::reset();
        }
    }

    /**
     * S11/C4 path jail: a poisoned cover_path pointing OUTSIDE the configured
     * library roots (e.g. /etc/passwd) must yield 404 on getCover.
     *
     * @test
     */
    public function testGetCoverReturns404WhenCoverPathEscapesLibraryRoots(): void
    {
        $this->itemRepo->method('findById')->willReturn([
            'id' => 'book-evil',
            'name' => 'Poisoned',
            'type' => 'book',
            'path' => '/books/test.epub',
            'metadata' => ['cover_path' => '/etc/passwd'],
        ]);

        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . sys_get_temp_dir());
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $response = $this->controller->getCover(new Request(), ['id' => 'book-evil']);
            $this->assertEquals(404, $response->statusCode);
            $body = json_decode($response->body, true);
            $this->assertArrayHasKey('error', $body);
        } finally {
            if ($prevRoots === false) {
                putenv('PHLIX_LIBRARY_ROOTS');
            } else {
                putenv('PHLIX_LIBRARY_ROOTS=' . $prevRoots);
            }
            \Phlix\Common\Fs\LibraryRootGuard::reset();
        }
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

    /**
     * @test
     */
    public function testGetBookMintsVerifiableSignedUrls(): void
    {
        $this->itemRepo->method('findById')->willReturn([
            'id' => 'book-123',
            'name' => 'Test Book',
            'type' => 'book',
            'path' => '/books/test.epub',
            'metadata' => [],
        ]);

        $response = $this->controller->getBook(new Request(), ['id' => 'book-123']);
        $body = json_decode($response->body, true);

        $signer = \Phlix\Auth\SignedUrl::fromEnv();
        $expected = [
            'cover_url' => '/api/v1/books/book-123/cover',
            'read_url' => '/api/v1/books/book-123/read',
            'download_url' => '/api/v1/books/book-123/download',
        ];
        foreach ($expected as $field => $path) {
            $this->assertArrayHasKey($field, $body['book']);
            parse_str((string) parse_url((string) $body['book'][$field], PHP_URL_QUERY), $q);
            $this->assertTrue(
                $signer->verify($path, (string) ($q['exp'] ?? ''), (string) ($q['sig'] ?? '')),
                "{$field} must be a verifiable signed URL for {$path}",
            );
        }
    }
}
