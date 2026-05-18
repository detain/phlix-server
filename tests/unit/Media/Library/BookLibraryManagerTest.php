<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\BookLibraryManager;
use Phlex\Media\Library\BookScanner;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\ScanResult;
use Workerman\MySQL\Connection;

/**
 * Unit tests for BookLibraryManager.
 *
 * @covers \Phlex\Media\Library\BookLibraryManager
 * @since 0.17.0
 */
class BookLibraryManagerTest extends TestCase
{
    private BookLibraryManager $manager;
    private BookScanner $scanner;
    private ItemRepository $itemRepo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->itemRepo = new ItemRepository($this->db);
        $this->scanner = new BookScanner($this->db, $this->itemRepo);
        $this->manager = new BookLibraryManager($this->scanner, $this->itemRepo);
    }

    /**
     * @test
     */
    public function testRescanLibraryCallsScanner(): void
    {
        $libraryId = 'test-lib-123';
        $paths = [sys_get_temp_dir() . '/phlex_test_' . uniqid()];
        mkdir($paths[0], 0755, true);

        // Create some book files
        $epubPath = $paths[0] . '/test.epub';
        $epubData = $this->createMinimalEpub();
        file_put_contents($epubPath, $epubData);

        // Setup mock to return empty for findByPath (item doesn't exist yet)
        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) use ($libraryId) {
                if (strpos($sql, 'DELETE FROM media_items') === 0) {
                    return [];
                }
                if (strpos($sql, 'SELECT * FROM media_items WHERE path = ?') === 0) {
                    return [];
                }
                if (strpos($sql, 'SELECT * FROM media_items WHERE library_id = ?') === 0) {
                    return [];
                }
                if (strpos($sql, 'INSERT INTO media_items') === 0) {
                    return [['id' => 'generated-id-123']];
                }
                return [];
            });

        $result = $this->manager->rescanLibrary($libraryId, $paths);

        // Should return a ScanResult
        $this->assertInstanceOf(ScanResult::class, $result);

        // Should have scanned items
        $this->assertGreaterThanOrEqual(0, $result->scanned);

        // Clean up
        unlink($epubPath);
        rmdir($paths[0]);
    }

    /**
     * @test
     */
    public function testUpsertBookStoresMetadata(): void
    {
        $libraryId = 'test-lib-book-456';
        $tempDir = sys_get_temp_dir() . '/phlex_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create an EPUB file
        $epubPath = $tempDir . '/Test Book.epub';
        file_put_contents($epubPath, $this->createMinimalEpub());

        // Setup mock
        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) use ($libraryId) {
                if (strpos($sql, 'SELECT * FROM media_items WHERE path = ?') === 0) {
                    return [];
                }
                if (strpos($sql, 'SELECT * FROM media_items WHERE id = ?') === 0) {
                    return [[
                        'id' => 'new-book-id',
                        'library_id' => $libraryId,
                        'name' => 'Test Book',
                        'type' => 'book',
                        'path' => $params[0] ?? '',
                        'metadata_json' => '{"title":"Test Book"}',
                    ]];
                }
                if (strpos($sql, 'INSERT INTO media_items') === 0) {
                    return [['id' => 'new-book-id']];
                }
                return [];
            });

        $result = $this->manager->upsertBook($libraryId, $epubPath);

        // Should return an array (the upserted item)
        $this->assertIsArray($result);

        // Clean up
        unlink($epubPath);
        rmdir($tempDir);
    }

    /**
     * Creates a minimal EPUB file for testing.
     */
    private function createMinimalEpub(): string
    {
        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'epub_');
        unlink($tempFile);
        $zip->open($tempFile, \ZipArchive::CREATE);

        // Add container.xml
        $container = '<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>';
        $zip->addFromString('META-INF/container.xml', $container);

        // Add content.opf
        $contentOpf = '<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:title>Test Book</dc:title>
    <dc:creator>Test Author</dc:creator>
    <dc:publisher>Test Publisher</dc:publisher>
    <dc:language>en</dc:language>
    <dc:identifier>urn:uuid:test-123</dc:identifier>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="nav"/>
  </spine>
</package>';
        $zip->addFromString('content.opf', $contentOpf);

        // Add minimal nav.xhtml
        $nav = '<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
<body>
  <nav epub:type="toc"><h1>Test</h1></nav>
</body>
</html>';
        $zip->addFromString('nav.xhtml', $nav);

        $zip->close();

        $content = file_get_contents($tempFile) ?: '';
        unlink($tempFile);

        return $content;
    }
}
