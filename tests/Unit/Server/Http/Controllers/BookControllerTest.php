<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\MockObject\MockObject;
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
 * @since 0.17.0
 */
class BookControllerTest extends TestCase
{
    private BookController $controller;
    private ItemRepository&MockObject $itemRepo;
    private LibraryManager&MockObject $libraryManager;
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

        /** @var array{book: array<string, mixed>} $body */
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
            /** @var array<array-key, mixed> $body */
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
            /** @var array<array-key, mixed> $body */
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

        /** @var array<array-key, mixed> $body */
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
        /** @var array{book: array<string, string>} $body */
        $body = json_decode($response->body, true);

        $signer = \Phlix\Auth\SignedUrl::fromEnv();
        $expected = [
            'cover_url' => '/api/v1/books/book-123/cover',
            'read_url' => '/api/v1/books/book-123/read',
            'download_url' => '/api/v1/books/book-123/download',
        ];
        foreach ($expected as $field => $path) {
            $this->assertArrayHasKey($field, $body['book']);
            parse_str((string) parse_url($body['book'][$field], PHP_URL_QUERY), $q);
            $exp = $q['exp'] ?? '';
            $sig = $q['sig'] ?? '';
            $this->assertTrue(
                $signer->verify($path, is_string($exp) ? $exp : '', is_string($sig) ? $sig : ''),
                "{$field} must be a verifiable signed URL for {$path}",
            );
        }
    }

    /**
     * SH-012 (review server__fedev #4): the download filename rode unescaped
     * — a `"` in a book name broke out of the quoted-string (malformed
     * header) and non-ASCII names shipped as raw UTF-8 bytes browsers recover
     * with divergent (or abortive) filenames. The law now is RFC 6266/5987:
     * an ASCII fallback `filename` plus `filename*=UTF-8''<percent-encoded>`
     * as the authoritative slot.
     *
     * @test
     */
    public function testDownloadBookEmitsRfc6266QuotedFilenamePair(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $bookPath = $tempDir . '/file-on-disk.epub';
        file_put_contents($bookPath, 'minimal epub content');

        $name = 'He said "Goodbye" \\ / — Époche';
        $this->itemRepo->method('findById')->willReturn([
            'id' => 'book-6266',
            'name' => $name,
            'type' => 'book',
            'path' => $bookPath,
            'metadata' => [],
        ]);

        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . $tempDir);
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $response = $this->controller->downloadBook(new Request(), ['id' => 'book-6266']);
            $disposition = $response->headers['Content-Disposition'] ?? '';

            // The authoritative slot: UTF-8'' + RFC 5987 attr-chars only.
            $this->assertStringContainsString(
                "; filename*=UTF-8''" . rawurlencode($name . '.epub'),
                $disposition
            );

            // The ASCII fallback: quoted-string body, no raw quote/backslash,
            // no non-ASCII, no control bytes, no path separators.
            self::assertSame(1, preg_match('/filename="([^"]*)"/', $disposition, $fallback));
            $ascii = $fallback[1];
            // printable ASCII minus " (0x22), / (0x2F) and \ (0x5C):
            $this->assertMatchesRegularExpression('/^[\x20-\x21\x23-\x2E\x30-\x5B\x5D-\x7E]*$/', $ascii);
            $this->assertStringEndsWith('.epub', $ascii);

            // Header framing: no raw CR/LF anywhere in the value.
            $this->assertStringNotContainsString("\r", $disposition);
            $this->assertStringNotContainsString("\n", $disposition);
        } finally {
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
     * A CRLF-bearing book name must not be able to split the header — the
     * fallback slot replaces the control bytes and the ext slot percent-
     * encodes them. This is the same injection class the newsletter fix
     * guards; both slots stay on one line.
     *
     * @test
     */
    public function testDownloadBookFilenameCannotBreakHeaderFraming(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlix_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $bookPath = $tempDir . '/safe.epub';
        file_put_contents($bookPath, 'x');

        $this->itemRepo->method('findById')->willReturn([
            'id' => 'book-crlf',
            'name' => "Good\r\nX-Evil: yes",
            'type' => 'book',
            'path' => $bookPath,
            'metadata' => [],
        ]);

        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . $tempDir);
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $response = $this->controller->downloadBook(new Request(), ['id' => 'book-crlf']);
            $disposition = $response->headers['Content-Disposition'] ?? '';

            $this->assertStringNotContainsString("\r", $disposition);
            $this->assertStringNotContainsString("\n", $disposition);
            $this->assertStringContainsString("UTF-8''Good%0D%0AX-Evil%3A%20yes.epub", $disposition);
        } finally {
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
}
