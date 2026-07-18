<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Server\Http\Controllers\ArtworkController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ArtworkController} covering the Phase C title-logo path:
 * `?size=logo` must serve the cached `logo.png` with an `image/png` content-type
 * (never `image/jpeg`) while poster variants stay JPEG.
 */
final class ArtworkControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/artwork-ctrl-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/item-a', 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['logo.png', 'w185.jpg'] as $f) {
            @unlink($this->tmpDir . '/item-a/' . $f);
        }
        @rmdir($this->tmpDir . '/item-a');
        @rmdir($this->tmpDir);
    }

    private function authedRequest(string $size): Request
    {
        $request = new Request();
        $request->userId = 'user-1'; // authorises without a signed URL
        $request->query = ['size' => $size];
        return $request;
    }

    public function testServesLogoAsImagePng(): void
    {
        file_put_contents($this->tmpDir . '/item-a/logo.png', 'PNGBYTES');

        $controller = new ArtworkController(new ArtworkStorage($this->tmpDir));
        $response = $controller->serve($this->authedRequest('logo'), ['itemId' => 'item-a']);

        self::assertSame(200, $response->statusCode);
        self::assertSame('image/png', $response->headers['Content-Type'] ?? null);
        self::assertSame($this->tmpDir . '/item-a/logo.png', $response->filePath);
    }

    public function testServesPosterVariantAsImageJpeg(): void
    {
        file_put_contents($this->tmpDir . '/item-a/w185.jpg', 'JPEGBYTES');

        $controller = new ArtworkController(new ArtworkStorage($this->tmpDir));
        $response = $controller->serve($this->authedRequest('w185'), ['itemId' => 'item-a']);

        self::assertSame(200, $response->statusCode);
        self::assertSame('image/jpeg', $response->headers['Content-Type'] ?? null);
    }

    public function testMissingLogoReturns404(): void
    {
        $controller = new ArtworkController(new ArtworkStorage($this->tmpDir));
        $response = $controller->serve($this->authedRequest('logo'), ['itemId' => 'item-a']);

        self::assertSame(404, $response->statusCode);
    }
}
