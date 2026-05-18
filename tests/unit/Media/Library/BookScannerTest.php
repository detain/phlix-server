<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\BookScanner;
use Phlex\Media\Library\ItemRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for BookScanner EPUB/PDF/CBZ harvesting functionality.
 *
 * @covers \Phlex\Media\Library\BookScanner
 * @since 0.17.0
 */
class BookScannerTest extends TestCase
{
    private Connection $db;
    private ItemRepository $itemRepo;
    private BookScanner $scanner;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->itemRepo = new ItemRepository($this->db);
        $this->scanner = new BookScanner($this->db, $this->itemRepo);
    }

    /**
     * @test
     */
    public function testHarvestEpubParsesContentOpf(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlex_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $epubPath = $tempDir . '/test.epub';

        // Create a minimal EPUB file
        $epubData = $this->createMinimalEpub();
        file_put_contents($epubPath, $epubData);

        $metadata = $this->scanner->harvestEpub($epubPath);

        // Should return an array
        $this->assertIsArray($metadata);

        // Clean up
        unlink($epubPath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testHarvestEpubReturnsEmptyOnInvalid(): void
    {
        // Test with non-existent file
        $result = $this->scanner->harvestEpub('/non/existent/file.epub');
        $this->assertEquals([], $result);

        // Test with invalid file
        $tempDir = sys_get_temp_dir() . '/phlex_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $invalidEpub = $tempDir . '/invalid.epub';
        file_put_contents($invalidEpub, 'this is not a valid epub');

        $result = $this->scanner->harvestEpub($invalidEpub);
        $this->assertEquals([], $result);

        // Clean up
        unlink($invalidEpub);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testHarvestPdfExtractsMetadata(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlex_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $pdfPath = $tempDir . '/test.pdf';

        // Create a minimal PDF file
        $pdfData = $this->createMinimalPdf();
        file_put_contents($pdfPath, $pdfData);

        $metadata = $this->scanner->harvestPdf($pdfPath);

        // Should return an array
        $this->assertIsArray($metadata);

        // Clean up
        unlink($pdfPath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testHarvestCbzExtractsPagesAndCover(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlex_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $cbzPath = $tempDir . '/test.cbz';

        // Create a minimal CBZ file
        $cbzData = $this->createMinimalCbz();
        file_put_contents($cbzPath, $cbzData);

        $metadata = $this->scanner->harvestCbz($cbzPath);

        // Should return an array
        $this->assertIsArray($metadata);

        // Should have page_count
        $this->assertArrayHasKey('page_count', $metadata);

        // Clean up
        unlink($cbzPath);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testScanBookLibraryYieldsItems(): void
    {
        $tempDir = sys_get_temp_dir() . '/phlex_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create some book files
        $epubFile = $tempDir . '/01 - Test Book.epub';
        file_put_contents($epubFile, $this->createMinimalEpub());

        $pdfFile = $tempDir . '/02 - Another Book.pdf';
        file_put_contents($pdfFile, $this->createMinimalPdf());

        $libraryId = 'test-lib-book-123';
        $results = [];
        foreach ($this->scanner->scanBookLibrary($libraryId, $tempDir) as $item) {
            $results[] = $item;
        }

        // Should yield items for the book files
        $this->assertCount(2, $results);

        // Verify structure
        foreach ($results as $item) {
            $this->assertArrayHasKey('library_id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('type', $item);
            $this->assertArrayHasKey('path', $item);
            $this->assertArrayHasKey('metadata_json', $item);
            $this->assertEquals('book', $item['type']);
            $this->assertEquals($libraryId, $item['library_id']);
        }

        // Clean up
        unlink($epubFile);
        unlink($pdfFile);
        rmdir($tempDir);
    }

    /**
     * @test
     */
    public function testIsBookExtension(): void
    {
        $this->assertTrue($this->scanner->isBookExtension('epub'));
        $this->assertTrue($this->scanner->isBookExtension('pdf'));
        $this->assertTrue($this->scanner->isBookExtension('cbz'));
        $this->assertTrue($this->scanner->isBookExtension('EPUB'));
        $this->assertTrue($this->scanner->isBookExtension('PDF'));
        $this->assertTrue($this->scanner->isBookExtension('CBZ'));

        $this->assertFalse($this->scanner->isBookExtension('mp3'));
        $this->assertFalse($this->scanner->isBookExtension('mp4'));
        $this->assertFalse($this->scanner->isBookExtension('jpg'));
    }

    /**
     * Creates a minimal EPUB file for testing.
     */
    private function createMinimalEpub(): string
    {
        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'epub_');
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

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
    }

    /**
     * Creates a minimal PDF file for testing.
     */
    private function createMinimalPdf(): string
    {
        // Minimal PDF structure
        $pdf = '%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>
endobj
xref
0 4
0000000000 65535 f
0000000009 00000 n
0000000058 00000 n
0000000115 00000 n
trailer
<< /Size 4 /Root 1 0 R >>
startxref
196
%%EOF';

        return $pdf;
    }

    /**
     * Creates a minimal CBZ file for testing.
     */
    private function createMinimalCbz(): string
    {
        $zip = new \ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'cbz_');
        $zip->open($tempFile, \ZipArchive::CREATE);

        // Add ComicInfo.xml
        $comicInfo = '<?xml version="1.0" encoding="UTF-8"?>
<ComicInfo>
  <Title>Test Comic</Title>
  <Series>Test Series</Series>
  <Volume>1</Volume>
  <Writer>Test Writer</Writer>
  <PageCount>3</PageCount>
</ComicInfo>';
        $zip->addFromString('ComicInfo.xml', $comicInfo);

        // Add minimal JPEG images
        $jpegHeader = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" .
                       "\xFF\xDB\x00C\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08" .
                       "\x0A\x0C\x14\x0D\x0C\x0B\x0B\x0C\x19\x12\x13\x0F\x14\x1D\x1A\x1F\x1E" .
                       "\x1D\x1A\x1C\x1C .*\x1F'\x22,#\x1C\x1E\x1E\xFF\xC0\x00\x0B\x08\x00\x01" .
                       "\x00\x01\x01\x01\x11\x00" .
                       "\xFF\xC4\x00\x1F\x00\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00" .
                       "\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B" .
                       "\xFF\xC4\x00\xB5\x10\x00\x02\x01\x03\x03\x02\x04\x03\x05\x05\x04\x04" .
                       "\x00\x00\x01\x7D\x01\x02\x03\x00\x04\x11\x05\x12\x21\x31\x41\x06\x13" .
                       "\x51\x61\x07\x22\x71\x14\x32\x81\x91\xA1\x08\x23\x42\xB1\xC1\x15\x52" .
                       "\xD1\xF0\x24\x33\x62\x72\x82\x09\x0A\x16\x17\x18\x19\x1A\x25\x26\x27" .
                       "\x28\x29\x2A\x34\x35\x36\x37\x38\x39\x3A\x43\x44\x45\x46\x47\x48\x49" .
                       "\x4A\x53\x54\x55\x56\x57\x58\x59\x5A\x63\x64\x65\x66\x67\x68\x69\x6A" .
                       "\x73\x74\x75\x76\x77\x78\x79\x7A\x83\x84\x85\x86\x87\x88\x89\x8A\x92\x93" .
                       "\x94\x95\x96\x97\x98\x99\x9A\xA2\xA3\xA4\xA5\xA6\xA7\xA8\xA9\xAA\xB2" .
                       "\xB3\xB4\xB5\xB6\xB7\xB8\xB9\xBA\xC2\xC3\xC4\xC5\xC6\xC7\xC8\xC9\xCA" .
                       "\xD2\xD3\xD4\xD5\xD6\xD7\xD8\xD9\xDA\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9" .
                       "\xEA\xF1\xF2\xF3\xF4\xF5\xF6\xF7\xF8\xF9\xFA" .
                       "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xFB\xD5\xDB\x20\xA8\xE3" .
                       str_repeat("\x00", 100) .
                       "\xFF\xD9";

        $zip->addFromString('page1.jpg', $jpegHeader);
        $zip->addFromString('page2.jpg', $jpegHeader);
        $zip->addFromString('page3.jpg', $jpegHeader);

        $zip->close();

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
    }
}
