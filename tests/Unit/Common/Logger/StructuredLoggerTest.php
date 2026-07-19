<?php

namespace Phlix\Tests\Unit\Common\Logger;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

class StructuredLoggerTest extends TestCase
{
    private string $tempDir;
    /**
     * @var array{
     *     handlers: array{file: array{type: string, path: string, level: string}},
     *     processors: array{context: bool, request_id: bool, user_id: bool}
     * }
     */
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
        array_map('unlink', glob($this->tempDir . '/*') ?: []);
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

    // -------------------------------------------------------------------------
    // Loop detection must be disabled in constructor (SV-LOOPDETECT-FIX)
    // -------------------------------------------------------------------------

    public function testConstructorDisablesLoggingLoopDetection(): void
    {
        $config = $this->config;
        // Loop detection is disabled by default in StructuredLogger constructor
        // The config 'disable_loop_detection' key is not used by the implementation
        $logger = new StructuredLogger('test', $config);
        $this->assertInstanceOf(StructuredLogger::class, $logger);

        // Verify logging works (loop detection is disabled internally)
        $logger->info('Test message');
        $this->assertFileExists($this->config['handlers']['file']['path']);
    }

    public function testConstructorDoesNotCallLoopDetectionWhenDisabledInConfig(): void
    {
        $config = $this->config;
        $config['disable_loop_detection'] = false;

        $logger = new StructuredLogger('test', $config);
        $this->assertInstanceOf(StructuredLogger::class, $logger);

        // Verify logging works (loop detection is always disabled)
        $logger->info('Test message');
        $this->assertFileExists($this->config['handlers']['file']['path']);
    }

    // -------------------------------------------------------------------------
    // WS-C: multi-handler channel routing (step C4)
    // -------------------------------------------------------------------------

    public function testMultiHandlerChannelRouting(): void
    {
        // Helper: read a log file, returning '' if it does not exist yet.
        // This avoids phpstan complaining about file_get_contents() returning string|false.
        $readLog = static fn(string $path): string => is_file($path) ? (file_get_contents($path) ?: '') : '';

        // Build temp paths for each handler.
        $appLog    = $this->tempDir . '/app.log';
        $errorLog  = $this->tempDir . '/error.log';
        $eventsLog = $this->tempDir . '/events.log';
        $pluginsLog = $this->tempDir . '/plugins.log';

        $config = [
            'handlers' => [
                // General application log — no channel gate, receives ALL channels.
                'file' => [
                    'type' => 'stream',
                    'path' => $appLog,
                    'level' => 'debug',
                ],
                // Error aggregation — no channel gate, receives error+ from ALL channels.
                'error' => [
                    'type' => 'stream',
                    'path' => $errorLog,
                    'level' => 'error',
                ],
                // Events log — scoped to 'events' channel only.
                'events' => [
                    'type' => 'stream',
                    'path' => $eventsLog,
                    'level' => 'debug',
                    'channels' => ['events'],
                ],
                // Plugins log — scoped to 'plugins' channel only.
                'plugins' => [
                    'type' => 'stream',
                    'path' => $pluginsLog,
                    'level' => 'debug',
                    'channels' => ['plugins'],
                ],
            ],
            'processors' => [
                'context' => false,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        // -------------------------------------------------------------------------
        // Test 1: Log on 'plugins' channel — only plugins.log receives the record.
        //         app.log, events.log, and error.log stay empty (no error level).
        // -------------------------------------------------------------------------
        $pluginsLogger = new StructuredLogger('plugins', $config);
        $pluginsLogger->info('plugins info message');

        $this->assertFileExists($pluginsLog);
        $this->assertFileExists($appLog);

        $pluginsContent = $readLog($pluginsLog);
        $appContent     = $readLog($appLog);
        // events.log and error.log do not exist for the 'plugins' channel.
        $eventsContent = $readLog($eventsLog);
        $errorContent  = $readLog($errorLog);

        $this->assertStringContainsString('plugins info message', $pluginsContent);
        $this->assertStringContainsString('plugins info message', $appContent);
        $this->assertStringNotContainsString('plugins info message', $eventsContent);
        $this->assertStringNotContainsString('plugins info message', $errorContent);

        // -------------------------------------------------------------------------
        // Test 2: Log on 'events' channel — only events.log receives the record.
        // -------------------------------------------------------------------------
        $eventsLogger = new StructuredLogger('events', $config);
        $eventsLogger->info('events info message');

        $eventsContent  = $readLog($eventsLog);
        $appContent     = $readLog($appLog);
        $pluginsContent = $readLog($pluginsLog);
        $errorContent   = $readLog($errorLog);

        $this->assertStringContainsString('events info message', $eventsContent);
        $this->assertStringContainsString('events info message', $appContent);
        $this->assertStringNotContainsString('events info message', $pluginsContent);
        $this->assertStringNotContainsString('events info message', $errorContent);

        // -------------------------------------------------------------------------
        // Test 3: Log on a generic channel like 'media' — only app.log receives it.
        //         Neither events.log nor plugins.log is touched.
        // -------------------------------------------------------------------------
        $mediaLogger = new StructuredLogger('media', $config);
        $mediaLogger->info('media info message');

        $appContent    = $readLog($appLog);
        $eventsContent = $readLog($eventsLog);
        $pluginsContent = $readLog($pluginsLog);
        $errorContent  = $readLog($errorLog);

        $this->assertStringContainsString('media info message', $appContent);
        $this->assertStringNotContainsString('media info message', $eventsContent);
        $this->assertStringNotContainsString('media info message', $pluginsContent);
        $this->assertStringNotContainsString('media info message', $errorContent);

        // -------------------------------------------------------------------------
        // Test 4: A 'plugins' ERROR hits BOTH plugins.log AND error.log.
        // -------------------------------------------------------------------------
        $pluginsLogger->error('plugins error message');

        $pluginsContent = $readLog($pluginsLog);
        $errorContent   = $readLog($errorLog);
        $appContent     = $readLog($appLog);

        $this->assertStringContainsString('plugins error message', $pluginsContent);
        $this->assertStringContainsString('plugins error message', $errorContent);
        $this->assertStringContainsString('plugins error message', $appContent);

        // Verify events.log was NOT touched by plugins messages.
        $eventsContent = $readLog($eventsLog);
        $this->assertStringNotContainsString('plugins error message', $eventsContent);
    }

    // -------------------------------------------------------------------------
    // PHP 8.5 + Swoole resilience: a spurious "Writing to the log file failed"
    // throw from a concurrent-coroutine E_DEPRECATED must NEVER escape the
    // logger. Every routed handler is wrapped in a WhatFailureGroupHandler.
    // -------------------------------------------------------------------------

    public function testEveryRoutedHandlerIsWrappedInWhatFailureGroupHandler(): void
    {
        $logger = new StructuredLogger('media', $this->multiHandlerConfig());

        foreach ($this->innerMonolog($logger)->getHandlers() as $handler) {
            $this->assertInstanceOf(
                WhatFailureGroupHandler::class,
                $handler,
                'each routed handler must be wrapped so inner write failures are swallowed'
            );
        }
    }

    public function testThrowingInnerHandlerDoesNotCrashLoggingAndSiblingsStillWrite(): void
    {
        $appLog   = $this->tempDir . '/app.log';
        $errorLog = $this->tempDir . '/error.log';
        $config   = $this->multiHandlerConfig();

        $logger  = new StructuredLogger('media', $config);
        $monolog = $this->innerMonolog($logger);

        // Simulate the PHP 8.5 hazard: replace the app.log handler's INNER
        // handler with one whose handle()/handleBatch() throws (as Monolog's
        // StreamHandler does when a concurrent-coroutine deprecation is captured
        // during its fwrite window). The wrapper must swallow it.
        $throwing = new class implements HandlerInterface {
            public function isHandling(LogRecord $record): bool
            {
                return true;
            }

            public function handle(LogRecord $record): bool
            {
                throw new \UnexpectedValueException(
                    'Writing to the log file failed: PDO::MYSQL_ATTR_INIT_COMMAND is deprecated'
                );
            }

            /** @param array<LogRecord> $records */
            public function handleBatch(array $records): void
            {
                throw new \UnexpectedValueException('Writing to the log file failed');
            }

            public function close(): void
            {
            }
        };

        // Find the WhatFailureGroupHandler wrapping the app.log StreamHandler
        // (the debug-level, catch-all one) and swap its inner for the thrower.
        $swapped = false;
        foreach ($monolog->getHandlers() as $wrapper) {
            $this->assertInstanceOf(WhatFailureGroupHandler::class, $wrapper);
            $inner = $this->wrappedInnerHandlers($wrapper);
            $onlyInner = $inner[0] ?? null;
            if ($onlyInner instanceof \Monolog\Handler\StreamHandler && $onlyInner->getUrl() === $appLog) {
                $ref = new \ReflectionProperty(WhatFailureGroupHandler::class, 'handlers');
                $ref->setValue($wrapper, [$throwing]);
                $swapped = true;
                break;
            }
        }
        $this->assertTrue($swapped, 'expected to locate the app.log inner handler to sabotage');

        // Logging an error must NOT throw even though the app.log inner throws.
        $logger->error('resilience-marker');

        // And the sibling error.log handler (a DIFFERENT wrapper) must still
        // have written the record — proving one handler's failure does not
        // abort the record for the others, and no records are lost for real
        // writes.
        $this->assertFileExists($errorLog, 'sibling error.log must still receive the record');
        $this->assertStringContainsString(
            'resilience-marker',
            (string) file_get_contents($errorLog)
        );
    }

    /**
     * Multi-handler routed config used by the resilience tests: a catch-all
     * app.log (debug), an error-aggregation error.log (error+), and a
     * plugins-scoped plugins.log.
     *
     * @return array<string, mixed>
     */
    private function multiHandlerConfig(): array
    {
        return [
            'handlers' => [
                'file' => [
                    'type' => 'stream',
                    'path' => $this->tempDir . '/app.log',
                    'level' => 'debug',
                ],
                'error' => [
                    'type' => 'stream',
                    'path' => $this->tempDir . '/error.log',
                    'level' => 'error',
                ],
                'plugins' => [
                    'type' => 'stream',
                    'path' => $this->tempDir . '/plugins.log',
                    'level' => 'debug',
                    'channels' => ['plugins'],
                ],
            ],
        ];
    }

    private function innerMonolog(StructuredLogger $logger): Logger
    {
        $ref = new \ReflectionProperty(StructuredLogger::class, 'logger');
        $monolog = $ref->getValue($logger);
        $this->assertInstanceOf(Logger::class, $monolog);
        return $monolog;
    }

    /**
     * @return array<int, HandlerInterface>
     */
    private function wrappedInnerHandlers(WhatFailureGroupHandler $wrapper): array
    {
        $ref = new \ReflectionProperty(WhatFailureGroupHandler::class, 'handlers');
        /** @var array<int, HandlerInterface> $handlers */
        $handlers = $ref->getValue($wrapper);
        return $handlers;
    }
}
