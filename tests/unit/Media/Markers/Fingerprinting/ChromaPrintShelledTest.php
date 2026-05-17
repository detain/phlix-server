<?php

namespace Phlex\Tests\Unit\Media\Markers\Fingerprinting;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintFingerprintFailedException;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintShelled;

class ChromaPrintShelledTest extends TestCase
{
    private string $tempFpcalc;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/chromaprint_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->tempFpcalc = $this->tempDir . '/fpcalc';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFpcalc)) {
            unlink($this->tempFpcalc);
        }
        // Remove any test files in tempDir
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testFingerprintParsesFpcalcOutput(): void
    {
        $script = <<<'BASH'
#!/bin/bash
echo "FINGERPRINT=ABC123DEF456"
BASH;
        file_put_contents($this->tempFpcalc, $script);
        chmod($this->tempFpcalc, 0755);

        $tempFile = $this->tempDir . '/test.mkv';
        touch($tempFile);

        $chromaprint = new ChromaPrintShelled($this->tempFpcalc);
        $result = $chromaprint->fingerprint($tempFile);

        $this->assertIsString($result);
        $this->assertEquals('ABC123DEF456', $result);
    }

    public function testIsAvailableChecksBinaryExists(): void
    {
        $chromaprint = new ChromaPrintShelled('/nonexistent/fpcalc');
        $result = $chromaprint->isAvailable();

        $this->assertIsBool($result);
        $this->assertFalse($result);
    }

    public function testIsAvailableReturnsTrueForExecutableBinary(): void
    {
        $script = <<<'BASH'
#!/bin/bash
echo "Usage: fpcalc [options] file"
exit 0
BASH;
        file_put_contents($this->tempFpcalc, $script);
        chmod($this->tempFpcalc, 0755);

        $chromaprint = new ChromaPrintShelled($this->tempFpcalc);
        $result = $chromaprint->isAvailable();

        $this->assertTrue($result);
    }

    public function testFingerprintThrowsWhenFileNotFound(): void
    {
        $script = <<<'BASH'
#!/bin/bash
echo "FINGERPRINT=ABC123"
BASH;
        file_put_contents($this->tempFpcalc, $script);
        chmod($this->tempFpcalc, 0755);

        $chromaprint = new ChromaPrintShelled($this->tempFpcalc);

        $this->expectException(ChromaPrintFingerprintFailedException::class);
        $chromaprint->fingerprint('/nonexistent/file.mkv');
    }

    public function testFingerprintThrowsOnNonZeroExitCode(): void
    {
        $script = <<<'BASH'
#!/bin/bash
echo "ERROR: Invalid file" >&2
exit 1
BASH;
        file_put_contents($this->tempFpcalc, $script);
        chmod($this->tempFpcalc, 0755);

        $tempFile = $this->tempDir . '/test.mkv';
        touch($tempFile);

        $chromaprint = new ChromaPrintShelled($this->tempFpcalc);

        $this->expectException(ChromaPrintFingerprintFailedException::class);
        $chromaprint->fingerprint($tempFile);
    }

    public function testFingerprintThrowsWhenNoFingerprintLine(): void
    {
        $script = <<<'BASH'
#!/bin/bash
echo "DURATION=120"
BASH;
        file_put_contents($this->tempFpcalc, $script);
        chmod($this->tempFpcalc, 0755);

        $tempFile = $this->tempDir . '/test.mkv';
        touch($tempFile);

        $chromaprint = new ChromaPrintShelled($this->tempFpcalc);

        $this->expectException(ChromaPrintFingerprintFailedException::class);
        $chromaprint->fingerprint($tempFile);
    }

    public function testIsAvailableReturnsFalseForNonExecutable(): void
    {
        $script = <<<'BASH'
#!/bin/bash
echo "Usage"
BASH;
        file_put_contents($this->tempFpcalc, $script);
        chmod($this->tempFpcalc, 0644);

        $chromaprint = new ChromaPrintShelled($this->tempFpcalc);
        $result = $chromaprint->isAvailable();

        $this->assertFalse($result);
    }
}
