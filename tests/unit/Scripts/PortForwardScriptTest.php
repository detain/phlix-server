<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class PortForwardScriptTest extends TestCase
{
    public function testScriptFileExists(): void
    {
        $scriptPath = __DIR__ . '/../../../scripts/port-forward.php';
        $this->assertFileExists($scriptPath);
    }

    public function testScriptIsValidPhp(): void
    {
        $scriptPath = __DIR__ . '/../../../scripts/port-forward.php';
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($scriptPath) . ' 2>&1', $output, $exitCode);
        $this->assertStringContainsString('No syntax errors', implode("\n", $output));
    }

    public function testScriptRespondsToHelpCommand(): void
    {
        $scriptPath = __DIR__ . '/../../../scripts/port-forward.php';
        $output = [];
        $exitCode = 0;
        exec('php ' . $scriptPath . ' help 2>&1', $output, $exitCode);
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('Usage:', $outputStr);
        $this->assertStringContainsString('status', $outputStr);
        $this->assertStringContainsString('enable', $outputStr);
        $this->assertStringContainsString('disable', $outputStr);
        $this->assertStringContainsString('info', $outputStr);
    }

    public function testScriptRespondsToStatusCommand(): void
    {
        $scriptPath = __DIR__ . '/../../../scripts/port-forward.php';
        $output = [];
        $exitCode = 0;
        exec('php ' . $scriptPath . ' status 2>&1', $output, $exitCode);
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('Port Forwarding Status', $outputStr);
        $this->assertStringContainsString('Enabled:', $outputStr);
    }

    public function testScriptRespondsToInfoCommand(): void
    {
        $scriptPath = __DIR__ . '/../../../scripts/port-forward.php';
        $output = [];
        $exitCode = 0;
        exec('php ' . $scriptPath . ' info 2>&1', $output, $exitCode);
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('Network Information', $outputStr);
    }
}
