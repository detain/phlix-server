<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Request;

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

    /** A two-frame BIF archive, built by the production writer. */
    private function sampleBif(): string
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

        return BifWriter::build([$jpeg, $jpeg], 10000);
    }

    public function testGetBifUrl(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $this->assertSame(
            'http://localhost:8096/trickplay/job-123/thumbs.bif',
            $controller->getBifUrl('job-123')
        );
    }

    public function testGetBifPathIsInsideTheJobDirectory(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $this->assertSame(
            $this->tempDir . '/trickplay/job-123/thumbs.bif',
            $controller->getBifPath('job-123')
        );
    }

    public function testResolveBifPathIsNullWhenNotFound(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $this->assertNull($controller->resolveBifPath('nonexistent-job'));
    }

    public function testResolveBifPathPointsAtTheRawArchiveBytes(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);
        $bif = $this->sampleBif();
        file_put_contents($this->tempDir . '/trickplay/job-123/thumbs.bif', $bif);

        $path = $controller->resolveBifPath('job-123');

        $this->assertSame(realpath($this->tempDir . '/trickplay/job-123/thumbs.bif'), $path);
        $content = file_get_contents((string) $path);
        $this->assertSame($bif, $content);
        // Pin the identity of what is served, not just its length: a handler
        // that pointed at some other file of the same size would pass a strlen
        // check. The magic is the cheapest unambiguous discriminator.
        $this->assertSame(BifWriter::MAGIC, substr((string) $content, 0, 8));
    }

    public function testHasBifIsFalseWhenAbsentAndTrueWhenPresent(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $this->assertFalse($controller->hasBif('job-123'));

        file_put_contents($this->tempDir . '/trickplay/job-123/thumbs.bif', $this->sampleBif());

        $this->assertTrue($controller->hasBif('job-123'));
    }

    public function testHasBifIsFalseForAnEmptyFile(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);
        file_put_contents($this->tempDir . '/trickplay/job-123/thumbs.bif', '');

        // A zero-byte artefact is what a crashed or half-run producer leaves
        // behind. Reporting it as present would advertise a URL that serves
        // nothing usable.
        $this->assertFalse($controller->hasBif('job-123'));
    }

    public function testHasBifRejectsPathTraversalJobIds(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $this->assertFalse($controller->hasBif('../../etc'));
        $this->assertNull($controller->resolveBifPath('../../etc'));
    }

    public function testAJobLinkedToASiblingThatOnlySharesTheRootsPrefixIsRefused(): void
    {
        // "<root>-sibling" begins with the trickplay root's string, so a real path
        // inside it passes a bare prefix check. The separator makes it outside.
        $sibling = $this->tempDir . '-sibling';
        mkdir($sibling, 0755, true);
        file_put_contents($sibling . '/thumbs.bif', $this->sampleBif());
        $link = $this->tempDir . '/trickplay/job-link';
        symlink($sibling, $link);

        try {
            $controller = new TrickplayController($this->tempDir, $this->baseUrl);

            $this->assertNull($controller->resolveBifPath('job-link'));
            $this->assertFalse($controller->hasBif('job-link'));
            $response = $controller->getBif(new Request(), ['jobId' => 'job-link']);
            $this->assertSame(404, $response->statusCode);
        } finally {
            unlink($link);
            $this->removeDirectory($sibling);
        }
    }

    public function testGetBifHandlerServesTheArchive(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);
        $bif = $this->sampleBif();
        file_put_contents($this->tempDir . '/trickplay/job-123/thumbs.bif', $bif);

        // The archive is streamed from disk, so the buffered body is empty until
        // the window is materialized (which is what a test needs to inspect it).
        $response = $controller->getBif(new Request(), [
            'jobId' => 'job-123',
        ])->materializeFileWindow();

        $this->assertSame(200, $response->statusCode);
        $this->assertSame($bif, $response->body);
        $this->assertSame((string) strlen($bif), $response->headers['Content-Length'] ?? null);
    }

    public function testGetBifHandlerReturns404WhenTheArchiveIsMissing(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $response = $controller->getBif(new Request(), [
            'jobId' => 'job-123',
        ]);

        $this->assertSame(404, $response->statusCode);
    }

    public function testGetJobDir(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $jobDir = $controller->getJobDir('job-123');
        $this->assertEquals($this->tempDir . '/trickplay/job-123', $jobDir);
    }

    public function testResolveSpritePathFindsTheSprite(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $jpegData = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        file_put_contents($this->tempDir . '/trickplay/job-123/sprite.jpg', $jpegData);

        $this->assertSame(
            realpath($this->tempDir . '/trickplay/job-123/sprite.jpg'),
            $controller->resolveSpritePath('job-123')
        );
    }

    public function testResolveSpritePathFallsBackToAPngSheet(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);
        file_put_contents($this->tempDir . '/trickplay/job-123/sprite.png', 'png');

        $this->assertSame(
            realpath($this->tempDir . '/trickplay/job-123/sprite.png'),
            $controller->resolveSpritePath('job-123')
        );
    }

    public function testResolveTimelinePathFindsTheTimeline(): void
    {
        $controller = new TrickplayController($this->tempDir, $this->baseUrl);

        $json = '[{"time":1,"x":2,"y":2}]';
        file_put_contents($this->tempDir . '/trickplay/job-123/timeline.json', $json);

        $this->assertSame(
            realpath($this->tempDir . '/trickplay/job-123/timeline.json'),
            $controller->resolveTimelinePath('job-123')
        );
        $this->assertNull($controller->resolveTimelinePath('nonexistent-job'));
    }

    public function testDeletedHandlersAreGoneRatherThanLeftAsDeadRoutesTargets(): void
    {
        // S275 deleted getIndex()/getThumbnail() along with their routes, because
        // only the (also deleted) TrickplayGenerator wrote index.xml/bif_NN.jpg.
        // Asserting their ABSENCE keeps a later re-add from quietly restoring a
        // route over a file nothing produces.
        $this->assertFalse(method_exists(TrickplayController::class, 'getIndex'));
        $this->assertFalse(method_exists(TrickplayController::class, 'getThumbnail'));
        $this->assertFalse(method_exists(TrickplayController::class, 'getIndexContent'));
        $this->assertFalse(method_exists(TrickplayController::class, 'getThumbnailContent'));
    }
}
