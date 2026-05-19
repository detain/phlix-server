<?php

namespace Phlex\Common\Logger;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Stringable;

class StructuredLogger implements LoggerInterface
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
        foreach ($this->config['handlers'] ?? [] as $name => $handlerConfig) {
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

        // Note: In Monolog 3, context is handled natively by PsrLogMessageProcessor
        // and the Logger's log() method. The 'context' processor config is kept for
        // backwards compatibility but no longer adds a separate processor.

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
        return match (strtolower($level)) {
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

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    /**
     * PSR-3 compatible log entrypoint. `$level` may be a Monolog Level enum,
     * a PSR-3 level string (e.g. "info", "warning"), or an int (PSR-3 numeric
     * level). All are normalized to a Monolog Level.
     *
     * @param mixed              $level
     * @param string|Stringable  $message
     * @param array<string,mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $context['channel'] = $this->channel;
        $resolvedLevel = $level instanceof Level
            ? $level
            : $this->mapLevel(is_string($level) ? $level : (string) $level);
        $this->logger->log($resolvedLevel, (string) $message, $context);
    }

    public function withContext(array $context): Logger
    {
        return $this->logger->withContext($context);
    }
}
