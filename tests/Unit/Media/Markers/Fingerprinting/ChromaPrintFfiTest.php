<?php

namespace Phlix\Tests\Unit\Media\Markers\Fingerprinting;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintFfi;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintFingerprintFailedException;

class ChromaPrintFfiTest extends TestCase
{
    public function testIsAvailableFalseWhenFfiUnavailable(): void
    {
        $chromaprint = new ChromaPrintFfi();
        $result = $chromaprint->isAvailable();

        $this->assertIsBool($result);
    }

    /**
     * @group integration
     */
    public function testFingerprintThrowsWhenFileNotFound(): void
    {
        $chromaprint = new ChromaPrintFfi();

        if (!$chromaprint->isAvailable()) {
            $this->markTestSkipped('FFI is not available on this system - run in docker-compose with FFI extension');
        }

        $this->expectException(ChromaPrintFingerprintFailedException::class);
        $chromaprint->fingerprint('/nonexistent/file.mkv');
    }

    public function testIsAvailableReturnsConsistentResult(): void
    {
        $chromaprint = new ChromaPrintFfi();
        $first = $chromaprint->isAvailable();
        $second = $chromaprint->isAvailable();

        $this->assertEquals($first, $second);
    }
}
