<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles;

use Phlix\Media\Subtitles\EmbeddedSubtitleCache;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmbeddedSubtitleCache (minors M-SERVER-54(a)): the persisted
 * WebVTT derivations of embedded subtitle tracks — layout, fingerprint-keyed
 * naming (invalidation idiom), traversal jail, best-effort atomic write.
 */
final class EmbeddedSubtitleCacheTest extends TestCase
{
    private string $baseDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/phlix_subcache_' . uniqid('', true);
        mkdir($this->baseDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            @system('rm -rf ' . escapeshellarg($this->baseDir));
        }
        parent::tearDown();
    }

    public function testPathForIsTheFingerprintKeyedPerItemLayout(): void
    {
        $cache = new EmbeddedSubtitleCache($this->baseDir);

        $path = $cache->pathFor('item-54', 2, 1730000000, 4096);

        // <base>/<itemId>/embedded-<index>-<mtime>-<size>.vtt — the source
        // fingerprint lives in the NAME, so a replaced media file can never
        // alias the previous generation's cached VTT.
        $this->assertSame($this->baseDir . '/item-54/embedded-2-1730000000-4096.vtt', $path);
    }

    public function testPathForSanitisesTheItemComponentAgainstTraversal(): void
    {
        $cache = new EmbeddedSubtitleCache($this->baseDir);

        $path = $cache->pathFor('../../etc/passwd', 0, 1, 1);

        // basename + allowlist reduce the crafted id to ONE safe component; the
        // path can never leave the base directory.
        $this->assertStringStartsWith($this->baseDir . '/', $path);
        $dirComponent = basename(dirname($path));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $dirComponent);
        $this->assertNotSame('..', $dirComponent);
    }

    public function testReadWriteRoundTripsUnderTheBase(): void
    {
        $cache = new EmbeddedSubtitleCache($this->baseDir);
        $path = $cache->pathFor('item-54', 0, 1730000001, 2048);

        $this->assertTrue($cache->write($path, "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nCached"));
        $content = $cache->read($path);

        $this->assertNotNull($content);
        $this->assertStringStartsWith('WEBVTT', (string) $content);
        // The atomic idiom leaves no scratch files behind.
        $this->assertSame([], glob(dirname($path) . '/*.tmp.*'));
    }

    public function testReadRefusesPathsOutsideTheJail(): void
    {
        $cache = new EmbeddedSubtitleCache($this->baseDir);

        $outside = tempnam(sys_get_temp_dir(), 'phlix-jail-');
        $this->assertIsString($outside);
        file_put_contents($outside, 'WEBVTT secret-outside');

        try {
            $this->assertNull($cache->read($outside));
            $this->assertNull($cache->read(''));
            $this->assertNull($cache->read($this->baseDir . '/missing/embedded-0-1-1.vtt'));

            // Shared-PREFIX sibling: a raw string that merely starts with the
            // base must still be refused — the jail compares at DIRECTORY
            // granularity, not by bare prefix.
            $sibling = $this->baseDir . '_sibling';
            mkdir($sibling, 0o775, true);
            file_put_contents($sibling . '/embedded-0-1-1.vtt', 'WEBVTT sibling');
            $this->assertNull($cache->read($sibling . '/embedded-0-1-1.vtt'));
        } finally {
            @unlink($outside);
            @system('rm -rf ' . escapeshellarg($this->baseDir . '_sibling'));
        }
    }

    public function testWriteFailureIsFalseNotThrown(): void
    {
        $cache = new EmbeddedSubtitleCache($this->baseDir);

        // A path whose parent is an EXISTING FILE can never be created — the
        // best-effort contract is a false return, never an exception that
        // would turn a playable subtitle into a 500.
        $blocker = $this->baseDir . '/blocker';
        file_put_contents($blocker, 'not a directory');

        $this->assertFalse($cache->write($blocker . '/embedded-0-1-1.vtt', 'WEBVTT'));
    }
}
