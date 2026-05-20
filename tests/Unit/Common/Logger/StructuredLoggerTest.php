<?php

namespace Phlix\Tests\Unit\Common\Logger;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Monolog\Level;

class StructuredLoggerTest extends TestCase
{
    private string $tempDir;
    private array $config;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phlix_test_logs_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->config = [
            'handlers' => [
                'file' => [
                    'type' => 'stream',
                    'path' => $this->tempDir . '/app.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        array_map('unlink', glob($this->tempDir . '/*'));
        rmdir($this->tempDir);
    }

    public function testLoggerCanBeCreated(): void
    {
        $logger = new StructuredLogger('test', $this->config);
        $this->assertInstanceOf(StructuredLogger::class, $logger);
    }

    public function testLoggerCanLogInfoMessage(): void
    {
        $logger = new StructuredLogger('test', $this->config);
        $logger->info('Test info message');
        
        $this->assertFileExists($this->config['handlers']['file']['path']);
    }

    public function testLoggerCanLogWithContext(): void
    {
        $logger = new StructuredLogger('test', $this->config);
        $logger->info('Test message with context', ['key' => 'value', 'number' => 42]);
        
        $this->assertFileExists($this->config['handlers']['file']['path']);
    }

    public function testLoggerCanLogErrors(): void
    {
        $logger = new StructuredLogger('test', $this->config);
        $logger->error('Test error message');
        
        $this->assertFileExists($this->config['handlers']['file']['path']);
    }

    public function testLogLevels(): void
    {
        $logger = new StructuredLogger('test', $this->config);
        
        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->notice('Notice message');
        $logger->warning('Warning message');
        $logger->error('Error message');
        $logger->critical('Critical message');
        
        $this->assertFileExists($this->config['handlers']['file']['path']);
    }
}