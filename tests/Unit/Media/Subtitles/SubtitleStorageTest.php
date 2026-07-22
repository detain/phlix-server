<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles;

use Phlix\Media\Subtitles\SubtitleStorage;
use Phlix\Shared\Subtitle\SubtitleFile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Subtitles\SubtitleStorage
 */
final class SubtitleStorageTest extends TestCase
{
    private string $baseDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/phlix_substore_' . uniqid('', true);
        mkdir($this->baseDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            @system('rm -rf ' . escapeshellarg($this->baseDir));
        }
        parent::tearDown();
    }

    private function file(string $lang, string $content, string $format = 'srt', bool $hi = false): SubtitleFile
    {
        return new SubtitleFile(
            language: $lang,
            format: $format,
            content: $content,
            provider: 'opensubtitles',
            suggestedFilename: 'x.' . $format,
            hearingImpaired: $hi,
        );
    }

    public function testStoreWritesConvertedVttUnderInjectedBaseDirAndReadRoundTrips(): void
    {
        $storage = new SubtitleStorage($this->baseDir);

        $srt = "1\n00:00:01,000 --> 00:00:02,000\nHola\n";
        $path = $storage->store('item-1', $this->file('es', $srt));

        // Written under <base>/<itemId>/<lang>.vtt (NEVER /var).
        $this->assertSame(
            $this->baseDir . '/item-1/es.vtt',
            $path,
        );
        $this->assertFileExists($path);

        $content = $storage->read($path);
        $this->assertNotNull($content);
        $this->assertStringStartsWith('WEBVTT', (string) $content);
        // SubRip comma separator converted on store.
        $this->assertStringContainsString('00:00:01.000 --> 00:00:02.000', (string) $content);
    }

    public function testHearingImpairedGetsDistinctFileName(): void
    {
        $storage = new SubtitleStorage($this->baseDir);

        $plain = $storage->store('item-1', $this->file('en', "WEBVTT\n\nx\n", 'vtt', false));
        $hi = $storage->store('item-1', $this->file('en', "WEBVTT\n\ny\n", 'vtt', true));

        $this->assertStringEndsWith('/en.vtt', $plain);
        $this->assertStringEndsWith('/en.hi.vtt', $hi);
        $this->assertNotSame($plain, $hi);
    }

    /**
     * Defense-in-depth: a traversal-shaped language component (`.`/`..`) must
     * fall back to `und` rather than produce a relative-path filename segment.
     * Regression for the review finding that `safeComponent()` accepted `.`/`..`.
     */
    public function testTraversalLanguageComponentFallsBackAndStaysInRoot(): void
    {
        $storage = new SubtitleStorage($this->baseDir);

        $path = $storage->store('item-1', $this->file('..', "WEBVTT\n\nz\n", 'vtt'));

        $this->assertSame($this->baseDir . '/item-1/und.vtt', $path);
        $this->assertFileExists($path);
        // The file is confined under <base>/item-1, never an escaped segment.
        $real = realpath($path);
        $this->assertIsString($real);
        $this->assertStringStartsWith(realpath($this->baseDir) . '/item-1/', (string) $real);
    }

    public function testReadRefusesPathOutsideTheBaseDir(): void
    {
        $storage = new SubtitleStorage($this->baseDir);

        // A real file that lives OUTSIDE the subtitle root must not be served.
        $outside = tempnam(sys_get_temp_dir(), 'phlix-outside-');
        $this->assertIsString($outside);
        file_put_contents((string) $outside, 'secret');

        $this->assertNull($storage->read((string) $outside), 'path traversal jail must refuse out-of-root reads');

        @unlink((string) $outside);
    }

    public function testReadReturnsNullForMissingFile(): void
    {
        $storage = new SubtitleStorage($this->baseDir);
        $this->assertNull($storage->read($this->baseDir . '/item-1/none.vtt'));
    }
}
