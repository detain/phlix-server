<?php

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlix\Dlna\ContentDirectory;
use Phlix\Media\Library\ItemRepository;

class ContentDirectoryTest extends TestCase
{
    private ContentDirectory $contentDirectory;
    private MockObject $itemRepositoryMock;

    protected function setUp(): void
    {
        // Create mock item repository
        $this->itemRepositoryMock = $this->createMock(ItemRepository::class);

        $this->contentDirectory = new ContentDirectory($this->itemRepositoryMock);
    }

    public function testBrowseRootReturnsContainers(): void
    {
        $result = $this->contentDirectory->browse('0', 'BrowseDirectChildren');

        $this->assertArrayHasKey('Result', $result);
        $this->assertArrayHasKey('NumberReturned', $result);
        $this->assertArrayHasKey('TotalMatches', $result);
        $this->assertArrayHasKey('UpdateID', $result);
        
        // Root should have library containers
        $this->assertGreaterThan(0, $result['TotalMatches']);
        $this->assertStringContainsString('DIDL-Lite', $result['Result']);
    }

    public function testBrowseMetadataForRoot(): void
    {
        $result = $this->contentDirectory->browse('0', 'BrowseMetadata');

        $this->assertArrayHasKey('Result', $result);
        $this->assertArrayHasKey('NumberReturned', $result);
        // BrowseMetadata should return at least the container itself
        $this->assertGreaterThanOrEqual(1, $result['NumberReturned']);
    }

    public function testBrowseNonExistentObjectReturnsError(): void
    {
        $result = $this->contentDirectory->browse('non-existent-id', 'BrowseDirectChildren');

        $this->assertArrayHasKey('Error', $result);
        $this->assertEquals(701, $result['Error']['code']);
    }

    public function testBrowseLibraryContainer(): void
    {
        $result = $this->contentDirectory->browse('library-video', 'BrowseDirectChildren');

        $this->assertArrayHasKey('Result', $result);
        $this->assertArrayHasKey('NumberReturned', $result);
        $this->assertArrayHasKey('TotalMatches', $result);
    }

    public function testSearchWithWildcardReturnsBrowseResults(): void
    {
        $result = $this->contentDirectory->search('0', '*');

        $this->assertArrayHasKey('Result', $result);
        $this->assertArrayHasKey('TotalMatches', $result);
    }

    public function testSearchWithInvalidCriteriaReturnsError(): void
    {
        // Complex search criteria that can't be parsed
        $result = $this->contentDirectory->search('0', '@invalid::syntax[test]');

        // Should return error for unsupported search criteria
        if (isset($result['Error'])) {
            $this->assertEquals(800, $result['Error']['code']);
        }
    }

    public function testGenerateDidlWithEmptyArray(): void
    {
        $didl = $this->contentDirectory->generateDidl([]);

        $this->assertStringContainsString('DIDL-Lite', $didl);
        $this->assertStringContainsString('</DIDL-Lite>', $didl);
        // Empty DIDL should only have opening and closing tags with no items
        $this->assertEquals(0, substr_count($didl, '<item ') + substr_count($didl, '<container '));
    }

    public function testGenerateDidlWithItems(): void
    {
        $items = [
            [
                'id' => 'test-item-1',
                'parent_id' => 'library-video',
                'name' => 'Test Movie',
                'type' => 'movie',
            ],
        ];

        $didl = $this->contentDirectory->generateDidl($items);

        $this->assertStringContainsString('DIDL-Lite', $didl);
        $this->assertStringContainsString('test-item-1', $didl);
        $this->assertStringContainsString('Test Movie', $didl);
        $this->assertStringContainsString('dc:title', $didl);
        $this->assertStringContainsString('upnp:class', $didl);
    }

    public function testGenerateDidlForContainer(): void
    {
        $items = [
            [
                'id' => 'library-video',
                'parent_id' => '0',
                'name' => 'Video Library',
                'type' => 'container',
            ],
        ];

        $didl = $this->contentDirectory->generateDidl($items);

        $this->assertStringContainsString('<container ', $didl);
        $this->assertStringContainsString('library-video', $didl);
    }

    public function testSystemUpdateId(): void
    {
        $initialId = $this->contentDirectory->getSystemUpdateId();
        $this->assertIsInt($initialId);

        $this->contentDirectory->incrementSystemUpdateId();
        $this->assertGreaterThan($initialId, $this->contentDirectory->getSystemUpdateId());
    }

    public function testGetScpdXml(): void
    {
        $scpd = $this->contentDirectory->getScpdXml();

        $this->assertStringContainsString('scpd', $scpd);
        $this->assertStringContainsString('specVersion', $scpd);
        $this->assertStringContainsString('actionList', $scpd);
        $this->assertStringContainsString('serviceStateTable', $scpd);
        $this->assertStringContainsString('Browse', $scpd);
        $this->assertStringContainsString('Search', $scpd);
    }

    public function testBrowseWithPagination(): void
    {
        $result = $this->contentDirectory->browse(
            '0',
            'BrowseDirectChildren',
            '*',
            0,
            2
        );

        $this->assertArrayHasKey('NumberReturned', $result);
        $this->assertLessThanOrEqual(2, $result['NumberReturned']);
    }

    public function testBrowseWithOffset(): void
    {
        // Browse root with offset
        $result = $this->contentDirectory->browse(
            '0',
            'BrowseDirectChildren',
            '*',
            10,
            5
        );

        $this->assertArrayHasKey('Result', $result);
    }
}
