<?php

namespace Phlex\Tests\Unit\Common\Logger;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Common\Logger\StructuredLogger;

class AuditLoggerTest extends TestCase
{
    private string $tempDir;
    private StructuredLogger $logger;
    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phlex_test_audit_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $config = [
            'handlers' => [
                'audit' => [
                    'type' => 'stream',
                    'path' => $this->tempDir . '/audit.log',
                    'level' => 'info',
                ],
            ],
            'processors' => ['context' => true, 'request_id' => false, 'user_id' => false],
        ];
        
        $this->logger = new StructuredLogger('audit', $config);
        $this->auditLogger = new AuditLogger($this->logger);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
    }

    public function testCanLogLoginSuccess(): void
    {
        $this->auditLogger->logLogin('user-123', 'device-456', true);
        $this->assertFileExists($this->tempDir . '/audit.log');
    }

    public function testCanLogLoginFailure(): void
    {
        $this->auditLogger->logLogin('user-123', 'device-456', false, 'Invalid password');
        $this->assertFileExists($this->tempDir . '/audit.log');
    }

    public function testCanLogLogout(): void
    {
        $this->auditLogger->logLogout('user-123', 'session-789');
        $this->assertFileExists($this->tempDir . '/audit.log');
    }

    public function testCanLogPermissionDenied(): void
    {
        $this->auditLogger->logPermissionDenied('user-123', '/admin/settings', 'delete');
        $this->assertFileExists($this->tempDir . '/audit.log');
    }

    public function testLogPluginActionWritesStructuredEntry(): void
    {
        $this->auditLogger->logPluginAction(
            'user-42',
            'install',
            'detain/phlex-plugin-example',
            ['source' => 'https://example.test/plugin.zip']
        );

        $this->assertFileExists($this->tempDir . '/audit.log');
        $logContents = (string) file_get_contents($this->tempDir . '/audit.log');
        $this->assertStringContainsString('"event":"plugin_action"', $logContents);
        $this->assertStringContainsString('"action":"install"', $logContents);
        $this->assertStringContainsString('"plugin":"detain/phlex-plugin-example"', $logContents);
        $this->assertStringContainsString('"user_id":"user-42"', $logContents);
        $this->assertStringContainsString('"source":"https://example.test/plugin.zip"', $logContents);
        $this->assertStringContainsString('Plugin lifecycle action', $logContents);
    }

    public function testLogPluginActionNormalisesNullActorToSystem(): void
    {
        $this->auditLogger->logPluginAction(null, 'enable', 'plugin-x');

        $this->assertFileExists($this->tempDir . '/audit.log');
        $logContents = (string) file_get_contents($this->tempDir . '/audit.log');
        $this->assertStringContainsString('"user_id":"system"', $logContents);
        $this->assertStringContainsString('"action":"enable"', $logContents);
        $this->assertStringContainsString('"plugin":"plugin-x"', $logContents);
    }

    public function testLogPluginActionNormalisesEmptyActorToSystem(): void
    {
        $this->auditLogger->logPluginAction('', 'disable', 'plugin-y');

        $this->assertFileExists($this->tempDir . '/audit.log');
        $logContents = (string) file_get_contents($this->tempDir . '/audit.log');
        $this->assertStringContainsString('"user_id":"system"', $logContents);
        $this->assertStringContainsString('"action":"disable"', $logContents);
        $this->assertStringContainsString('"plugin":"plugin-y"', $logContents);
    }
}