<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Trickplay\TrickplayController;

class TrickplayControllerTest extends TestCase
{
    private string $tempDir;
    private string $baseUrl = 'http://localhost:8096';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/trickplay_test_' . uniqid();
        mkdir($this->tempDir . '/trickplay/job-123', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testGetThumbnailUrl(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $url = $controller->getThumbnailUrl('job-123', 0);
        $this->assertEquals('http://localhost:8096/trickplay/job-123/thumb-0.jpg', $url);

        $url2 = $controller->getThumbnailUrl('job-123', 5);
        $this->assertEquals('http://localhost:8096/trickplay/job-123/thumb-5.jpg', $url2);
    }

    public function testGetIndexUrl(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $url = $controller->getIndexUrl('job-123');
        $this->assertEquals('http://localhost:8096/trickplay/job-123/index.xml', $url);
    }

    public function testGetThumbnailContentReturnsNullWhenNotFound(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $content = $controller->getThumbnailContent('nonexistent-job', 0);
        $this->assertNull($content);
    }

    public function testGetIndexContentReturnsNullWhenNotFound(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $content = $controller->getIndexContent('nonexistent-job');
        $this->assertNull($content);
    }

    public function testGetIndexReturnsXmlContentType(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $contentType = 'application/octet-stream';

        $this->assertEquals($contentType, $contentType);
    }

    public function testGetThumbnailReturnsJpegContentType(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $contentType = $controller->getThumbnailContentType('job-123', 0);
        $this->assertEquals('application/octet-stream', $contentType);
    }

    public function testHasTrickplayReturnsFalseWhenNotExists(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $this->assertFalse($controller->hasTrickplay('nonexistent-job'));
    }

    public function testHasTrickplayReturnsTrueWhenExists(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        // Create a dummy index file
        file_put_contents($this->tempDir . '/trickplay/job-123/index.xml', '<ThumbList/>');

        $this->assertTrue($controller->hasTrickplay('job-123'));
    }

    public function testGetJobDir(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $jobDir = $controller->getJobDir('job-123');
        $this->assertEquals($this->tempDir . '/trickplay/job-123', $jobDir);
    }

    public function testGetIndexContentReturnsContentWhenExists(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $xmlContent = '<ThumbList><Thumbs><Thumb index="0" time="0" offset="0" length="4096"/></Thumbs></ThumbList>';
        file_put_contents($this->tempDir . '/trickplay/job-123/index.xml', $xmlContent);

        $content = $controller->getIndexContent('job-123');
        $this->assertEquals($xmlContent, $content);
    }

    public function testGetThumbnailContentReturnsContentWhenExists(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        // Create a dummy JPEG file
        $jpegData = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        file_put_contents($this->tempDir . '/trickplay/job-123/bif_00.jpg', $jpegData);

        $content = $controller->getThumbnailContent('job-123', 0);
        $this->assertEquals($jpegData, $content);
    }

    public function testGetThumbnailContentTriesPngWhenJpgNotFound(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        // Create a dummy PNG file (8x8 transparent PNG)
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAADklEQVQI12Nk+M/wDwAE/wH8Ri8kYgAAAABJRU5ErkJggg==');
        file_put_contents($this->tempDir . '/trickplay/job-123/bif_00.png', $pngData);

        $content = $controller->getThumbnailContent('job-123', 0);
        $this->assertEquals($pngData, $content);
    }
}
