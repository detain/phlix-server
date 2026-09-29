<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Tuners\Iptv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Tuners\Iptv\M3UEntry;
use Phlix\LiveTv\Tuners\Iptv\M3UPlaylistOversizedException;
use Phlix\LiveTv\Tuners\Iptv\M3UParser;

class M3UParserTest extends TestCase
{
    private M3UParser $parser;

    protected function setUp(): void
    {
        $this->parser = new M3UParser();
    }

    public function testParseBasicM3U(): void
    {
        $content = <<<M3U
#EXTM3U
#EXTINF:-1,Channel One
http://example.com/stream1.m3u8
#EXTINF:-1,Channel Two
http://example.com/stream2.m3u8
M3U;

        $entries = $this->parser->parse($content);

        $this->assertCount(2, $entries);
        $this->assertInstanceOf(M3UEntry::class, $entries[0]);
        $this->assertInstanceOf(M3UEntry::class, $entries[1]);
        $this->assertEquals('Channel One', $entries[0]->name);
        $this->assertEquals('http://example.com/stream1.m3u8', $entries[0]->url);
        $this->assertEquals('Channel Two', $entries[1]->name);
        $this->assertEquals('http://example.com/stream2.m3u8', $entries[1]->url);
    }

    public function testParseExtendedM3uWithAllTags(): void
    {
        $content = <<<M3U
#EXTM3U
#EXTINF:-1 tvg-id="123" tvg-name="BBC One" tvg-chno="1" group-title="News",BBC One HD
http://example.com/bbc.m3u8
#EXTINF:-1 tvg-id="456" tvg-name="CNN" tvg-chno="5" group-title="News",CNN International
http://example.com/cnn.m3u8
M3U;

        $entries = $this->parser->parse($content);

        $this->assertCount(2, $entries);

        // First entry
        $this->assertEquals('BBC One HD', $entries[0]->name);
        $this->assertEquals(123, $entries[0]->tvgId);
        $this->assertEquals(1, $entries[0]->tvgChno);
        $this->assertEquals('News', $entries[0]->group);
        $this->assertEquals('http://example.com/bbc.m3u8', $entries[0]->url);
        $this->assertFalse($entries[0]->isRadio);

        // Second entry
        $this->assertEquals('CNN International', $entries[1]->name);
        $this->assertEquals(456, $entries[1]->tvgId);
        $this->assertEquals(5, $entries[1]->tvgChno);
        $this->assertEquals('News', $entries[1]->group);
        $this->assertEquals('http://example.com/cnn.m3u8', $entries[1]->url);
    }

    public function testParseRadioChannel(): void
    {
        $content = <<<M3U
#EXTM3U
#EXTINF:-1 radio="1" tvg-id="789" tvg-name="Radio FM" tvg-chno="100",Radio FM
http://example.com/radio.m3u8
M3U;

        $entries = $this->parser->parse($content);

        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]->isRadio);
        $this->assertEquals('Radio FM', $entries[0]->name);
        $this->assertEquals(789, $entries[0]->tvgId);
        $this->assertEquals(100, $entries[0]->tvgChno);
    }

    public function testParseUrlFetchesAndParses(): void
    {
        // This test would require a mock HTTP server
        // We verify the method exists and throws RuntimeException on failure
        $parser = new M3UParser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch M3U playlist');

        $parser->parseUrl('https://example.com/test.m3u8');
    }

    public function testParseHandlesEmptyContent(): void
    {
        $entries = $this->parser->parse('');
        $this->assertCount(0, $entries);
        $this->assertEmpty($entries);
    }

    public function testParseSkipsInvalidLines(): void
    {
        $content = <<<M3U
#EXTM3U
#INVALID_LINE
#EXTINF:-1,Valid Channel
http://example.com/stream.m3u8
M3U;

        $entries = $this->parser->parse($content);
        $this->assertCount(1, $entries);
        $this->assertEquals('Valid Channel', $entries[0]->name);
    }

    public function testParseWithLogoUrl(): void
    {
        $content = <<<M3U
#EXTM3U
#EXTINF:-1 tvg-logo="https://example.com/logo.png" tvg-id="1",Channel With Logo
http://example.com/stream.m3u8
M3U;

        $entries = $this->parser->parse($content);
        $this->assertCount(1, $entries);
        $this->assertEquals('https://example.com/logo.png', $entries[0]->logo);
    }

    public function testM3UEntryToArray(): void
    {
        $entry = new M3UEntry(
            url: 'http://example.com/stream.m3u8',
            name: 'Test Channel',
            tvgId: 1,
            tvgChno: 5,
            group: 'Entertainment',
            logo: 'http://example.com/logo.png',
            isRadio: false
        );

        $array = $entry->toArray();

        $this->assertEquals('http://example.com/stream.m3u8', $array['url']);
        $this->assertEquals('Test Channel', $array['name']);
        $this->assertEquals(1, $array['tvg_id']);
        $this->assertEquals(5, $array['tvg_chno']);
        $this->assertEquals('Entertainment', $array['group']);
        $this->assertEquals('http://example.com/logo.png', $array['logo']);
        $this->assertFalse($array['is_radio']);
    }

    public function testM3UEntryGetNameWithFallback(): void
    {
        $entry = new M3UEntry(url: 'http://example.com/stream.m3u8');
        $this->assertEquals('Unknown Channel', $entry->getName());

        $namedEntry = new M3UEntry(url: 'http://example.com/stream.m3u8', name: 'My Channel');
        $this->assertEquals('My Channel', $namedEntry->getName());
    }

    public function testM3UEntryGetChannelNumber(): void
    {
        $entry = new M3UEntry(url: 'http://example.com/stream.m3u8');
        $this->assertEquals(0, $entry->getChannelNumber());

        $entryWithChno = new M3UEntry(url: 'http://example.com/stream.m3u8', tvgChno: 10);
        $this->assertEquals(10, $entryWithChno->getChannelNumber());
    }

    // ---- F-1: playlist content is untrusted (stream URL jail) ----

    public function testParseDropsFileSchemeEntry(): void
    {
        $content = <<<M3U
#EXTM3U
#EXTINF:-1,Exfil Channel
file:///etc/passwd
#EXTINF:-1,Good Channel
http://iptv.example.com/live.m3u8
M3U;

        $entries = $this->parser->parse($content);

        $this->assertCount(1, $entries);
        $this->assertSame('Good Channel', $entries[0]->getName());
    }

    public function testParseDropsFileSchemeEntryInSingleLineFormat(): void
    {
        $content = "#EXTM3U\nfile:///etc/shadow\nhttp://iptv.example.com/ok.m3u8\n";

        $entries = $this->parser->parse($content);

        $this->assertCount(1, $entries);
        $this->assertSame('http://iptv.example.com/ok.m3u8', $entries[0]->url);
    }

    public function testParseDropsMetadataIpLiteralEntry(): void
    {
        $content = <<<M3U
#EXTM3U
#EXTINF:-1,SSRF Channel
http://169.254.169.254/latest/meta-data/
#EXTINF:-1,Loopback Channel
http://127.0.0.1:9999/admin
#EXTINF:-1,LAN Channel (allowed)
http://192.168.1.50:8000/live/1.ts
M3U;

        $entries = $this->parser->parse($content);

        $this->assertCount(1, $entries);
        $this->assertSame('LAN Channel (allowed)', $entries[0]->getName());
    }

    public function testParseUrlRefusesNonHttpPlaylistBeforeFetching(): void
    {
        $fetched = [];
        $parser = new M3UParser(
            bodyFetcher: static function (string $url, int $t, int $m) use (&$fetched): string {
                $fetched[] = $url;
                return "#EXTM3U\n";
            }
        );

        try {
            $parser->parseUrl('file:///etc/passwd');
            $this->fail('Expected a stream-policy refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is not allowed', $e->getMessage());
        }

        $this->assertSame([], $fetched, 'Refused playlist URL must never be dialed');
    }

    // ---- F-4: bounded playlist download ----

    public function testParseUrlThrowsOversizedWhenBodyExceedsCap(): void
    {
        $parser = new M3UParser(
            maxBytes: 1024,
            bodyFetcher: static fn (string $url, int $t, int $m): string => str_repeat('x', $m + 1)
        );

        $this->expectException(M3UPlaylistOversizedException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size of 1024 bytes');

        $parser->parseUrl('http://evil.example.com/huge.m3u8');
    }

    public function testParseUrlAcceptsBodyExactlyAtCapAndParses(): void
    {
        $body = "#EXTM3U\n#EXTINF:-1,OK\nhttp://iptv.example.com/a.ts\n";
        $parser = new M3UParser(
            maxBytes: strlen($body),
            bodyFetcher: static fn (string $url, int $t, int $m): string => $body
        );

        $entries = $parser->parseUrl('http://iptv.example.com/playlist.m3u8');

        $this->assertCount(1, $entries);
        $this->assertSame('OK', $entries[0]->getName());
    }

    public function testFetcherReceivesTheConfiguredCap(): void
    {
        $seenMax = null;
        $parser = new M3UParser(
            maxBytes: 4096,
            bodyFetcher: static function (string $url, int $t, int $m) use (&$seenMax): string {
                $seenMax = $m;
                return "#EXTM3U\n";
            }
        );

        $parser->parseUrl('http://iptv.example.com/p.m3u8');

        $this->assertSame(4096, $seenMax);
    }
}
