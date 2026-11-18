<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Trickplay artefacts are streamed with validators, and conditional GET is honoured.
 *
 * The bodies are never buffered: each 200 carries the file in `filePath` and an
 * empty body, and Workerman streams it. Each 304 carries the validators and no
 * file at all.
 */
final class TrickplayControllerConditionalGetTest extends TestCase
{
    private const MTIME = 1700000000;

    private string $tempDir;

    private string $jobDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/trickplay_cond_' . uniqid('', true);
        $this->jobDir = $this->tempDir . '/trickplay/job-123';
        mkdir($this->jobDir, 0755, true);

        $this->writeArtefact('sprite.jpg', "\xFF\xD8\xFF\xE0sprite\xFF\xD9");
        $this->writeArtefact('timeline.json', '[{"time":1,"x":2,"y":2}]');
        $this->writeArtefact('thumbs.bif', $this->sampleBif());
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
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** Writes an artefact and pins its mtime, so the validators are known. */
    private function writeArtefact(string $name, string $bytes): void
    {
        $path = $this->jobDir . '/' . $name;
        file_put_contents($path, $bytes);
        touch($path, self::MTIME);
    }

    private function sampleBif(): string
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

        return BifWriter::build([$jpeg, $jpeg], 10000);
    }

    private function controller(): TrickplayController
    {
        return new TrickplayController($this->tempDir, '');
    }

    /** @param array<string, string> $headers */
    private function request(array $headers = []): Request
    {
        $request = new Request();
        $request->headers = $headers;

        return $request;
    }

    /**
     * The validator the controller must mint for a file: md5 of inode, size, mtime
     * and ctime, read from the same stat() the controller uses.
     */
    private function etagFor(string $name): string
    {
        $path = $this->jobDir . '/' . $name;
        clearstatcache(true, $path);
        $stat = stat($path);
        $this->assertIsArray($stat);

        return '"' . md5($stat['ino'] . ':' . $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ctime']) . '"';
    }

    private function lastModified(): string
    {
        return gmdate('D, d M Y H:i:s', self::MTIME) . ' GMT';
    }

    /**
     * Each handler under test, with the file it serves and its content type.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function artefacts(): array
    {
        return [
            'bif' => ['getBif', 'thumbs.bif', 'application/octet-stream'],
            'sprite' => ['getSprite', 'sprite.jpg', 'image/jpeg'],
            'timeline' => ['getTimeline', 'timeline.json', 'application/json'],
        ];
    }

    /**
     * @dataProvider artefacts
     */
    public function testEachArtefactIsStreamedFromDiskNotBuffered(string $handler, string $file, string $type): void
    {
        $response = $this->controller()->{$handler}($this->request(), ['jobId' => 'job-123']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(realpath($this->jobDir . '/' . $file), $response->filePath);
        $this->assertSame('', $response->body, 'The body must stay empty: the file is streamed, not read into memory.');
        $this->assertSame($type, $response->headers['Content-Type'] ?? null);
    }

    /**
     * @dataProvider artefacts
     */
    public function testEachArtefactCarriesTheValidatorsAndTheCacheLifetime(string $handler, string $file): void
    {
        $response = $this->controller()->{$handler}($this->request(), ['jobId' => 'job-123']);

        $this->assertSame($this->etagFor($file), $response->headers['ETag'] ?? null);
        $this->assertSame($this->lastModified(), $response->headers['Last-Modified'] ?? null);
        $this->assertSame('public, max-age=86400', $response->headers['Cache-Control'] ?? null);
    }

    /**
     * @dataProvider artefacts
     */
    public function testAMatchingIfNoneMatchIsAnsweredWith304AndNoFile(string $handler, string $file): void
    {
        $response = $this->controller()->{$handler}(
            $this->request(['If-None-Match' => $this->etagFor($file)]),
            ['jobId' => 'job-123']
        );

        $this->assertSame(304, $response->statusCode);
        $this->assertNull($response->filePath, 'A 304 must not carry the file.');
        $this->assertSame('', $response->body);
        $this->assertSame($this->etagFor($file), $response->headers['ETag'] ?? null);
        $this->assertSame($this->lastModified(), $response->headers['Last-Modified'] ?? null);
        $this->assertSame('public, max-age=86400', $response->headers['Cache-Control'] ?? null);
    }

    public function testIfNoneMatchMatchesWeakTagsListsAndWildcard(): void
    {
        $controller = $this->controller();
        $etag = $this->etagFor('thumbs.bif');

        $weak = $controller->getBif($this->request(['If-None-Match' => 'W/' . $etag]), ['jobId' => 'job-123']);
        $this->assertSame(304, $weak->statusCode, 'GET uses the weak comparison.');

        $listed = $controller->getBif(
            $this->request(['If-None-Match' => '"nope", ' . $etag]),
            ['jobId' => 'job-123']
        );
        $this->assertSame(304, $listed->statusCode, 'Any listed tag may match.');

        $wildcard = $controller->getBif($this->request(['If-None-Match' => '*']), ['jobId' => 'job-123']);
        $this->assertSame(304, $wildcard->statusCode);
    }

    public function testAStaleTagIsServedAs200(): void
    {
        $response = $this->controller()->getBif(
            $this->request(['If-None-Match' => '"0-0"']),
            ['jobId' => 'job-123']
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertNotNull($response->filePath);
    }

    public function testIfNoneMatchOverridesIfModifiedSince(): void
    {
        // RFC 9110 §13.1.3: If-Modified-Since is ignored when If-None-Match is present,
        // so an unmatched tag must win even when the date would otherwise say 304.
        $response = $this->controller()->getBif(
            $this->request([
                'If-None-Match' => '"0-0"',
                'If-Modified-Since' => $this->lastModified(),
            ]),
            ['jobId' => 'job-123']
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testIfModifiedSinceAtOrAfterTheMtimeIsAnsweredWith304(): void
    {
        $response = $this->controller()->getTimeline(
            $this->request(['If-Modified-Since' => $this->lastModified()]),
            ['jobId' => 'job-123']
        );

        $this->assertSame(304, $response->statusCode);
    }

    public function testIfModifiedSinceBeforeTheMtimeIsAnsweredWith200(): void
    {
        $earlier = gmdate('D, d M Y H:i:s', self::MTIME - 3600) . ' GMT';

        $response = $this->controller()->getTimeline(
            $this->request(['If-Modified-Since' => $earlier]),
            ['jobId' => 'job-123']
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testAnUnparseableIfModifiedSinceIsIgnored(): void
    {
        $response = $this->controller()->getTimeline(
            $this->request(['If-Modified-Since' => 'not a date']),
            ['jobId' => 'job-123']
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testARewrittenArtefactInvalidatesItsOldTag(): void
    {
        $oldEtag = $this->etagFor('sprite.jpg');
        // A producer that re-runs replaces the file, so its size and mtime change.
        $this->writeArtefact('sprite.jpg', "\xFF\xD8\xFF\xE0a longer sprite sheet\xFF\xD9");

        $response = $this->controller()->getSprite(
            $this->request(['If-None-Match' => $oldEtag]),
            ['jobId' => 'job-123']
        );

        $this->assertSame(200, $response->statusCode, 'The old tag must not survive a rewrite.');
        $this->assertNotSame($oldEtag, $response->headers['ETag'] ?? null);
    }

    public function testASameSizeRewriteInTheSameSecondChangesTheTagWhenItIsRenamedIntoPlace(): void
    {
        // The producer publishes by renaming a verified temporary over the final
        // name. A same-size replacement with the same mtime (the same second) used
        // to keep the old size-mtime tag, so a client's cached copy would 304 forever.
        // The new inode changes the tag.
        $oldEtag = $this->etagFor('sprite.jpg');
        $size = (int) filesize($this->jobDir . '/sprite.jpg');
        $temp = $this->jobDir . '/.tmp-sprite.jpg';
        file_put_contents($temp, "\xFF\xD8" . str_repeat('Z', $size - 4) . "\xFF\xD9");
        touch($temp, self::MTIME);
        $this->assertTrue(rename($temp, $this->jobDir . '/sprite.jpg'));
        clearstatcache();
        $this->assertSame($size, (int) filesize($this->jobDir . '/sprite.jpg'));

        $response = $this->controller()->getSprite(
            $this->request(['If-None-Match' => $oldEtag]),
            ['jobId' => 'job-123']
        );

        $this->assertSame(200, $response->statusCode, 'Same size and mtime, new inode: the old tag must not match.');
        $this->assertNotSame($oldEtag, $response->headers['ETag'] ?? null);
    }

    public function testAMissingArtefactIsA404WithTheSameEnvelope(): void
    {
        $response = $this->controller()->getBif($this->request(), ['jobId' => 'no-such-job']);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame(
            ['error' => 'Not Found', 'message' => 'Preview data not found'],
            json_decode($response->body, true)
        );
        $this->assertNull($response->filePath);
    }

    public function testAnUnsafeJobIdIsA404NotAFileRead(): void
    {
        $response = $this->controller()->getTimeline($this->request(), ['jobId' => '../job-123']);

        $this->assertSame(404, $response->statusCode);
        $this->assertNull($response->filePath);
    }

    public function testTheSpriteContentTypeFollowsTheFileServed(): void
    {
        $controller = $this->controller();
        $this->assertSame(
            'image/jpeg',
            $controller->getSprite($this->request(), ['jobId' => 'job-123'])->headers['Content-Type'] ?? null
        );

        // Only a PNG sheet present: served as PNG, not mislabelled JPEG.
        unlink($this->jobDir . '/sprite.jpg');
        file_put_contents($this->jobDir . '/sprite.png', "\x89PNG\r\n\x1a\n");
        $png = $controller->getSprite($this->request(), ['jobId' => 'job-123']);

        $this->assertSame(200, $png->statusCode);
        $this->assertSame('image/png', $png->headers['Content-Type'] ?? null);
    }

    public function testMaterializedResponseCarriesTheWholeFileAsItsBody(): void
    {
        $bif = (string) file_get_contents($this->jobDir . '/thumbs.bif');

        $response = $this->controller()->getBif($this->request(), ['jobId' => 'job-123'])
            ->materializeFileWindow();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($bif, $response->body);
        $this->assertSame((string) strlen($bif), $response->headers['Content-Length'] ?? null);
    }
}
