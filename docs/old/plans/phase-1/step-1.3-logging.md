# Step 1.3: Logging Infrastructure

**Phase:** 1 - Core Media Server Foundation  
**Plan File:** step-1.3-logging.md  
**Objective:** Set up structured logging with Monolog, log rotation, and audit logging

---

## Overview

This step implements structured logging infrastructure using Monolog with rotating file handlers, context processors, and log levels. Logging is critical for debugging and audit trails.

**Prerequisites:** Step 1.2 must be completed first.

---

## Tasks

### 1.3.1 Create Structured Logger Class

Create `src/Common/Logger/StructuredLogger.php`:
```php
<?php

namespace Phlex\Common\Logger;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\ContextProcessor;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Level;

class StructuredLogger
{
    private Logger $logger;
    private string $channel;
    private array $config;

    public function __construct(string $channel, array $config)
    {
        $this->channel = $channel;
        $this->config = $config;
        $this->logger = new Logger($channel);
        
        $this->setupHandlers();
        $this->setupProcessors();
    }

    private function setupHandlers(): void
    {
        foreach ($this->config['handlers'] as $name => $handlerConfig) {
            $handler = $this->createHandler($handlerConfig);
            $level = $this->mapLevel($handlerConfig['level'] ?? 'debug');
            $handler->setLevel($level);
            $this->logger->pushHandler($handler);
        }
    }

    private function createHandler(array $config): \Monolog\Handler\HandlerInterface
    {
        $type = $config['type'] ?? 'rotating_file';
        
        switch ($type) {
            case 'rotating_file':
                return new RotatingFileHandler(
                    $config['path'],
                    $config['max_files'] ?? 30,
                    $this->mapLevel($config['level'] ?? 'debug')
                );
            
            case 'stream':
                return new StreamHandler(
                    $config['path'],
                    $this->mapLevel($config['level'] ?? 'debug')
                );
            
            case 'error':
                return new RotatingFileHandler(
                    $config['path'],
                    $config['max_files'] ?? 30,
                    Level::Error
                );
            
            case 'audit':
                return new RotatingFileHandler(
                    $config['path'],
                    $config['max_files'] ?? 90,
                    Level::Info
                );
            
            default:
                return new StreamHandler('php://stdout', Level::Debug);
        }
    }

    private function setupProcessors(): void
    {
        $this->logger->pushProcessor(new PsrLogMessageProcessor());
        
        if ($this->config['processors']['context'] ?? false) {
            $this->logger->pushProcessor(new ContextProcessor());
        }
        
        if ($this->config['processors']['request_id'] ?? false) {
            $this->logger->pushProcessor(new class {
                public function __invoke(array $record): array
                {
                    $record['extra']['request_id'] = $this->getRequestId();
                    return $record;
                }
                
                private function getRequestId(): string
                {
                    return $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req-');
                }
            });
        }
        
        if ($this->config['processors']['user_id'] ?? false) {
            $this->logger->pushProcessor(new class {
                public function __invoke(array $record): array
                {
                    $record['extra']['user_id'] = $this->getUserId();
                    return $record;
                }
                
                private function getUserId(): ?string
                {
                    return $_SESSION['user_id'] ?? null;
                }
            });
        }
    }

    private function mapLevel(string $level): Level
    {
        return match(strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning', 'warn' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    public function log(Level $level, string $message, array $context = []): void
    {
        $context['channel'] = $this->channel;
        $this->logger->log($level, $message, $context);
    }

    public function withContext(array $context): Logger
    {
        return $this->logger->withContext($context);
    }
}
```

### 1.3.2 Create Logger Factory

Create `src/Common/Logger/LoggerFactory.php`:
```php
<?php

namespace Phlex\Common\Logger;

class LoggerFactory
{
    private static array $loggers = [];
    private static string $configPath = '';

    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
    }

    public static function get(string $channel): StructuredLogger
    {
        if (!isset(self::$loggers[$channel])) {
            $config = include self::$configPath;
            self::$loggers[$channel] = new StructuredLogger($channel, $config);
        }
        return self::$loggers[$channel];
    }

    public static function reset(): void
    {
        self::$loggers = [];
    }
}
```

### 1.3.3 Create Audit Logger

Create `src/Common/Logger/AuditLogger.php`:
```php
<?php

namespace Phlex\Common\Logger;

/**
 * Specialized logger for security and audit events.
 * All authentication events, authorization failures, and sensitive operations.
 */
class AuditLogger
{
    private StructuredLogger $logger;

    public function __construct(StructuredLogger $logger)
    {
        $this->logger = $logger;
    }

    public function logLogin(string $userId, string $deviceId, bool $success, ?string $reason = null): void
    {
        $this->logger->info('User login attempt', [
            'event' => 'login',
            'user_id' => $userId,
            'device_id' => $deviceId,
            'success' => $success,
            'reason' => $reason,
        ]);
    }

    public function logLogout(string $userId, string $sessionId): void
    {
        $this->logger->info('User logout', [
            'event' => 'logout',
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);
    }

    public function logFailedAuth(string $reason, array $context = []): void
    {
        $this->logger->warning('Authentication failure', array_merge([
            'event' => 'auth_failure',
            'reason' => $reason,
        ], $context));
    }

    public function logPermissionDenied(string $userId, string $resource, string $action): void
    {
        $this->logger->warning('Permission denied', [
            'event' => 'permission_denied',
            'user_id' => $userId,
            'resource' => $resource,
            'action' => $action,
        ]);
    }

    public function logApiKeyCreated(string $userId, string $keyId, string $keyName): void
    {
        $this->logger->info('API key created', [
            'event' => 'api_key_created',
            'user_id' => $userId,
            'key_id' => $keyId,
            'key_name' => $keyName,
        ]);
    }

    public function logApiKeyRevoked(string $userId, string $keyId): void
    {
        $this->logger->info('API key revoked', [
            'event' => 'api_key_revoked',
            'user_id' => $userId,
            'key_id' => $keyId,
        ]);
    }

    public function logDataExport(string $userId, string $dataType, int $recordCount): void
    {
        $this->logger->info('Data export', [
            'event' => 'data_export',
            'user_id' => $userId,
            'data_type' => $dataType,
            'record_count' => $recordCount,
        ]);
    }
}
```

### 1.3.4 Create Log Channel Enum/Traits

Create `src/Common/Logger/LogChannels.php`:
```php
<?php

namespace Phlex\Common\Logger;

/**
 * Log channel constants for consistent logger naming.
 */
final class LogChannels
{
    public const APPLICATION = 'application';
    public const HTTP = 'http';
    public const WEBSOCKET = 'websocket';
    public const DATABASE = 'database';
    public const MEDIA = 'media';
    public const STREAMING = 'streaming';
    public const TRANSCODING = 'transcoding';
    public const AUTH = 'auth';
    public const SESSION = 'session';
    public const AUDIT = 'audit';
    public const DLNA = 'dlna';
    public const LIVETV = 'livetv';
    
    private function __construct()
    {
        // Prevent instantiation
    }
}

/**
 * Trait for classes that need logging capability.
 */
trait HasLogger
{
    private ?StructuredLogger $logger = null;

    protected function setLogger(StructuredLogger $logger): void
    {
        $this->logger = $logger;
    }

    protected function getLogger(): StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::APPLICATION);
        }
        return $this->logger;
    }
}
```

### 1.3.5 Create Unit Tests

Create `tests/unit/Common/Logger/StructuredLoggerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Common\Logger;

use PHPUnit\Framework\TestCase;
use Phlex\Common\Logger\StructuredLogger;
use Monolog\Level;

class StructuredLoggerTest extends TestCase
{
    private string $tempDir;
    private array $config;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phlex_test_logs_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->config = [
            'handlers' => [
                'file' => [
                    'type' => 'rotating_file',
                    'path' => $this->tempDir . '/app.log',
                    'max_files' => 3,
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
```

Create `tests/unit/Common/Logger/AuditLoggerTest.php`:
```php
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
                    'type' => 'rotating_file',
                    'path' => $this->tempDir . '/audit.log',
                    'max_files' => 3,
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
}
```

---

## Verification

After completing all tasks:

1. Run the unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Common/Logger/ --testdox
```

2. Verify log files are created:
```bash
# Check if the structured logger writes correctly
ls -la /tmp/phlex_test_logs_*/ 2>/dev/null || echo "Tests will create their own temp directories"
```

3. Verify the logger classes exist:
```bash
ls -la /home/sites/phlex/src/Common/Logger/
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-1.3-logging
git add .
git commit -m "Step 1.3: Implement structured logging with Monolog"
unset GITHUB_TOKEN
gh pr create --title "Step 1.3: Logging Infrastructure" --body "Implements structured logging with Monolog including rotating file handlers, audit logger, and unit tests."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 1.4: HTTP Server Foundation** (`plans/phase-1/step-1.4-http-server.md`).
