<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\OpdsFeedBuilder;

/**
 * Unit tests for OpdsFeedBuilder.
 *
 * @covers \Phlix\Media\Metadata\OpdsFeedBuilder
 * @since 0.17.0
 */
class OpdsFeedBuilderTest extends TestCase
{
    private OpdsFeedBuilder $builder;
    private ItemRepository $itemRepo;

    protected function setUp(): void
    {
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $this->itemRepo = new ItemRepository($db);
        $this->builder = new OpdsFeedBuilder($this->itemRepo, 'http://localhost:8080');
    }

    /**
     * @test
     */
    public function testBuildRootFeedHasOpdsNamespace(): void
    {
        $xml = $this->builder->buildRootFeed();

        // Should be valid XML
        $this->assertNotEmpty($xml);

        // Should contain OPDS namespace
        $this->assertStringContainsString('xmlns:opds', $xml);
        $this->assertStringContainsString('opds-spec.org', $xml);

        // Should be an Atom feed
        $this->assertStringContainsString('<feed', $xml);
        $this->assertStringContainsString('xmlns', $xml);
    }

    /**
     * @test
     */
    public function testBuildNavigationFeedContainsLinks(): void
    {
        $libraries = [
            ['id' => 'lib-1', 'name' => 'My Books', 'type' => 'book'],
            ['id' => 'lib-2', 'name' => 'Other Books', 'type' => 'book'],
        ];

        $xml = $this->builder->buildNavigationFeed($libraries);

        // Should be valid XML
        $this->assertNotEmpty($xml);

        // Should contain library links
        $this->assertStringContainsString('My Books', $xml);
        $this->assertStringContainsString('Other Books', $xml);

        // Should be a navigation feed
        $this->assertStringContainsString('kind=navigation', $xml);
    }

    /**
     * @test
     */
    public function testBuildAcquisitionFeedContainsEntries(): void
    {
        $libraryId = 'test-lib-123';

        // Mock the item repository to return some books
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')
            ->willReturnCallback(function ($sql, $params) {
                if (strpos($sql, 'SELECT * FROM media_items WHERE library_id = ?') === 0) {
                    return [
                        [
                            'id' => 'book-1',
                            'library_id' => 'test-lib-123',
                            'name' => 'Test Book 1',
                            'type' => 'book',
                            'path' => '/books/test1.epub',
                            'metadata_json' => '{"title":"Test Book 1","author":"Author 1"}',
                        ],
                        [
                            'id' => 'book-2',
                            'library_id' => 'test-lib-123',
                            'name' => 'Test Book 2',
                            'type' => 'book',
                            'path' => '/books/test2.pdf',
                            'metadata_json' => '{"title":"Test Book 2","author":"Author 2"}',
                        ],
                    ];
                }
                return [];
            });

        $itemRepo = new ItemRepository($db);
        $builder = new OpdsFeedBuilder($itemRepo, 'http://localhost:8080');

        $xml = $builder->buildAcquisitionFeed($libraryId, 50, 0);

        // Should be valid XML
        $this->assertNotEmpty($xml);

        // Should contain entry elements
        $this->assertStringContainsString('<entry', $xml);

        // Should be an acquisition feed
        $this->assertStringContainsString('kind=acquisition', $xml);
    }

    /**
     * @test
     */
    public function testBuildEntryHasRequiredFields(): void
    {
        $book = [
            'id' => 'book-123',
            'name' => 'Test Book',
            'type' => 'book',
            'path' => '/books/test.epub',
            'metadata' => [
                'title' => 'Test Book',
                'author' => 'Test Author',
                'publisher' => 'Test Publisher',
                'isbn' => '1234567890',
                'language' => 'en',
                'description' => 'A test book description.',
            ],
        ];

        $dom = new \DOMDocument();
        $entry = $this->builder->buildEntry($book);

        // Import the entry into the DOM
        $dom->appendChild($dom->importNode($entry, true));
        $xml = $dom->saveXML();

        // Should contain Dublin Core fields
        $this->assertStringContainsString('dc:title', $xml);
        $this->assertStringContainsString('dc:creator', $xml);

        // Should contain OPDS link with acquisition relation
        $this->assertStringContainsString('link', $xml);
    }

    /**
     * @test
     */
    public function testBuildEntryHandlesMissingMetadata(): void
    {
        $book = [
            'id' => 'book-123',
            'name' => 'Test Book',
            'type' => 'book',
            'path' => '/books/test.epub',
            'metadata' => [],
        ];

        $dom = new \DOMDocument();
        $entry = $this->builder->buildEntry($book);

        // Should not throw, should handle gracefully
        $dom->appendChild($dom->importNode($entry, true));
        $xml = $dom->saveXML();

        // Should still have an entry
        $this->assertStringContainsString('<entry', $xml);

        // Should use name as fallback for title
        $this->assertStringContainsString('Test Book', $xml);
    }
}
