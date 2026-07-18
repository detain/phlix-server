<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming;

use Phlix\Media\Streaming\ClientCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * SV-3.3: exercises the client capability value object used by the transcode
 * entry and the playback-info direct-play verdict. Covers JSON parsing
 * (null/empty/invalid → empty), case-normalisation, the explicit-declaration
 * override, the widely-supported default-allow list, the default-deny for
 * unknown codecs (e.g. E-AC-3) and the hasExplicitCapabilities() flag.
 */
class ClientCapabilitiesTest extends TestCase
{
    public function testFromJsonNullYieldsEmptyInstance(): void
    {
        $caps = ClientCapabilities::fromJson(null);

        $this->assertFalse($caps->hasExplicitCapabilities());
        $this->assertSame([], $caps->getSupportedCodecs());
    }

    public function testFromJsonEmptyStringYieldsEmptyInstance(): void
    {
        $caps = ClientCapabilities::fromJson('');

        $this->assertFalse($caps->hasExplicitCapabilities());
        $this->assertSame([], $caps->getSupportedCodecs());
    }

    public function testFromJsonInvalidJsonYieldsEmptyInstance(): void
    {
        $caps = ClientCapabilities::fromJson('{not-json');

        $this->assertFalse($caps->hasExplicitCapabilities());
        $this->assertSame([], $caps->getSupportedCodecs());
    }

    public function testFromJsonNonObjectJsonYieldsEmptyInstance(): void
    {
        // A JSON scalar/array is not a codec map → treated as no declaration.
        $caps = ClientCapabilities::fromJson('"eac3"');

        $this->assertFalse($caps->hasExplicitCapabilities());
    }

    public function testEmptyFactoryHasNoExplicitCapabilities(): void
    {
        $caps = ClientCapabilities::empty();

        $this->assertFalse($caps->hasExplicitCapabilities());
        $this->assertSame([], $caps->getSupportedCodecs());
    }

    public function testFromJsonNormalisesCodecKeysToLowercaseAndBool(): void
    {
        $caps = ClientCapabilities::fromJson('{"EAC3": 0, "Opus": 1}');

        $this->assertTrue($caps->hasExplicitCapabilities());
        $this->assertSame(['eac3' => false, 'opus' => true], $caps->getSupportedCodecs());
    }

    public function testSupportsCodecHonoursExplicitTrue(): void
    {
        $caps = ClientCapabilities::fromJson('{"eac3": true}');

        // Case-insensitive lookup against the normalised map.
        $this->assertTrue($caps->supportsCodec('eac3'));
        $this->assertTrue($caps->supportsCodec('EAC3'));
    }

    public function testSupportsCodecHonoursExplicitFalse(): void
    {
        $caps = ClientCapabilities::fromJson('{"eac3": false}');

        $this->assertFalse($caps->supportsCodec('eac3'));
    }

    public function testExplicitDeclarationOverridesWidelySupportedDefault(): void
    {
        // aac would default-allow, but an explicit false must win.
        $caps = ClientCapabilities::fromJson('{"aac": false}');

        $this->assertFalse($caps->supportsCodec('aac'));
    }

    public function testUndeclaredWidelySupportedCodecsDefaultAllow(): void
    {
        // Some other codec was declared, but these well-known ones weren't;
        // they must still default to allowed to avoid needless transcodes.
        $caps = ClientCapabilities::fromJson('{"eac3": false}');

        foreach (['aac', 'mp3', 'opus', 'flac', 'ac3', 'vorbis', 'pcm'] as $codec) {
            $this->assertTrue($caps->supportsCodec($codec), "expected default-allow for {$codec}");
        }
    }

    public function testUndeclaredUnknownCodecDefaultsToDeny(): void
    {
        // eac3 is not in the widely-supported list, so when it isn't declared
        // the server must assume the client can't decode it.
        $caps = ClientCapabilities::fromJson('{"aac": true}');

        $this->assertFalse($caps->supportsCodec('eac3'));
    }

    public function testHasExplicitCapabilitiesTrueWhenAnyDeclared(): void
    {
        $this->assertTrue(ClientCapabilities::fromJson('{"aac": true}')->hasExplicitCapabilities());
    }
}
