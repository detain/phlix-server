<?php

namespace Phlix\Tests\Unit\Media\Markers\Fingerprinting;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Markers\Fingerprinting\ChromaPrint;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintFactory;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintFfi;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintFingerprintFailedException;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintInterface;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintNotAvailableException;
use Phlix\Media\Markers\Fingerprinting\ChromaPrintShelled;
use Psr\Log\NullLogger;

class ChromaPrintTest extends TestCase
{
    public function testFingerprintReturnsString(): void
    {
        $mockImpl = $this->createMock(ChromaPrintInterface::class);
        $mockImpl->method('fingerprint')->willReturn('test-fingerprint-data');
        $mockImpl->method('isAvailable')->willReturn(true);

        $chromaprint = new ChromaPrintTestable('/usr/local/bin/fpcalc', new NullLogger(), $mockImpl);
        $result = $chromaprint->fingerprint('/path/to/file.mkv');

        $this->assertIsString($result);
        $this->assertEquals('test-fingerprint-data', $result);
    }

    public function testIsAvailableReturnsBool(): void
    {
        $mockImpl = $this->createMock(ChromaPrintInterface::class);
        $mockImpl->method('isAvailable')->willReturn(true);

        $chromaprint = new ChromaPrintTestable('/usr/local/bin/fpcalc', new NullLogger(), $mockImpl);
        $result = $chromaprint->isAvailable();

        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    public function testFingerprintThrowsOnFailure(): void
    {
        $mockImpl = $this->createMock(ChromaPrintInterface::class);
        $mockImpl->method('isAvailable')->willReturn(true);
        $mockImpl->method('fingerprint')
            ->willThrowException(new ChromaPrintFingerprintFailedException('Test failure'));

        $chromaprint = new ChromaPrintTestable('/usr/local/bin/fpcalc', new NullLogger(), $mockImpl);

        $this->expectException(ChromaPrintFingerprintFailedException::class);
        $chromaprint->fingerprint('/path/to/file.mkv');
    }

    public function testIsAvailableReturnsFalseWhenNotAvailable(): void
    {
        $mockImpl = $this->createMock(ChromaPrintInterface::class);
        $mockImpl->method('isAvailable')->willReturn(false);

        $chromaprint = new ChromaPrintTestable('/usr/local/bin/fpcalc', new NullLogger(), $mockImpl);
        $result = $chromaprint->isAvailable();

        $this->assertFalse($result);
    }

    public function testFingerprintThrowsNotAvailableWhenImplUnavailable(): void
    {
        $mockImpl = $this->createMock(ChromaPrintInterface::class);
        $mockImpl->method('isAvailable')->willReturn(false);

        $chromaprint = new ChromaPrintTestable('/usr/local/bin/fpcalc', new NullLogger(), $mockImpl);

        $this->expectException(ChromaPrintNotAvailableException::class);
        $chromaprint->fingerprint('/path/to/file.mkv');
    }
}

class ChromaPrintTestable extends ChromaPrint
{
    private ChromaPrintInterface $testImpl;

    public function __construct(
        string $fpcalcPath,
        \Psr\Log\LoggerInterface $logger,
        ChromaPrintInterface $impl
    ) {
        parent::__construct($fpcalcPath, $logger);
        $this->testImpl = $impl;
    }

    public function fingerprint(string $path): string
    {
        if (!$this->isAvailable()) {
            throw new ChromaPrintNotAvailableException(
                'ChromaPrint is not available on this system.'
            );
        }

        return $this->testImpl->fingerprint($path);
    }

    public function isAvailable(): bool
    {
        return $this->testImpl->isAvailable();
    }
}
