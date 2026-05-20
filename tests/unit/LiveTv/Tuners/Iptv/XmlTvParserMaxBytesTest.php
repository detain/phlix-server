<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Tuners\Iptv;

use Phlix\LiveTv\Tuners\Iptv\XmlTvOversizedException;
use Phlix\LiveTv\Tuners\Iptv\XmlTvParser;
use PHPUnit\Framework\TestCase;

/**
 * Bounds the XMLTV remote fetch so a malicious endpoint cannot OOM the
 * worker by serving an arbitrarily large response.
 *
 * Uses a stream wrapper to provide deterministic byte streams without
 * requiring outbound HTTP.
 *
 * See post-O.7 wave 1 security audit, finding I.2.
 */
final class XmlTvParserMaxBytesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (in_array('phlixtest', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('phlixtest');
        }
        stream_wrapper_register('phlixtest', PhlixTestStreamWrapper::class);
    }

    public static function tearDownAfterClass(): void
    {
        if (in_array('phlixtest', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('phlixtest');
        }
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        PhlixTestStreamWrapper::$payload = '';
        parent::tearDown();
    }

    public function test_payload_under_limit_parses_normally(): void
    {
        PhlixTestStreamWrapper::$payload = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme start="20260101120000 +0000" stop="20260101130000 +0000" channel="cnn">
    <title>Lunchtime News</title>
  </programme>
</tv>
XML;

        $parser = new XmlTvParser(logger: null, maxBytes: 1024, maxRedirects: 0);

        $programmes = $parser->parseUrl('phlixtest://example.com/epg.xml');

        self::assertCount(1, $programmes);
        self::assertSame('cnn', $programmes[0]->channelId);
        self::assertSame('Lunchtime News', $programmes[0]->title);
    }

    public function test_payload_over_limit_throws(): void
    {
        // Cap the parser at 1 KiB; serve 2 KiB.
        PhlixTestStreamWrapper::$payload = str_repeat('A', 2048);

        $parser = new XmlTvParser(logger: null, maxBytes: 1024, maxRedirects: 0);

        $this->expectException(XmlTvOversizedException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');

        $parser->parseUrl('phlixtest://example.com/huge.xml');
    }

    public function test_default_max_bytes_constant_is_64_mib(): void
    {
        self::assertSame(64 * 1024 * 1024, XmlTvParser::DEFAULT_MAX_BYTES);
    }
}

/**
 * Minimal in-memory stream wrapper used by the test above.
 *
 * fopen("phlixtest://...") returns a handle that streams
 * {@see self::$payload}. fread/stream_get_contents read from it
 * sequentially.
 *
 * @internal Test fixture only.
 */
final class PhlixTestStreamWrapper
{
    public static string $payload = '';
    private int $position = 0;

    /** @var resource|null PHP populates this when the wrapper is opened via fopen. */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$payload, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$payload);
    }

    public function stream_stat(): array
    {
        return [
            'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1,
            'uid' => 0, 'gid' => 0, 'rdev' => 0,
            'size' => strlen(self::$payload),
            'atime' => 0, 'mtime' => 0, 'ctime' => 0,
            'blksize' => -1, 'blocks' => -1,
        ];
    }

    public function url_stat(string $path, int $flags): array
    {
        return $this->stream_stat();
    }

    public function stream_close(): void
    {
        $this->position = 0;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }
}
