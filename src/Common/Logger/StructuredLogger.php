<?php

/**
 * Phlix media server component: Logger.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Phlix wrapper around Monolog 3 that exposes a PSR-3 surface while
 * also accepting Monolog {@see Level} objects directly.
 *
 * Config shape (`array<string, mixed>`):
 * ```
 * [
 *     'handlers' => [
 *         '<name>' => [
 *             'type' => 'rotating_file'|'stream'|'error'|'audit',
 *             'path' => string,
 *             'level' => string, // optional, defaults to 'debug'
 *             'max_files' => int, // optional
 *             'channels' => string[], // optional; channels this handler
 *                                     // serves. ABSENT/empty = all channels.
 *             'env' => string, // optional; name of an env var that must be
 *                              // truthy for the handler to attach.
 *         ],
 *         ...
 *     ],
 *     'processors' => [
 *         'request_id' => bool,
 *         'user_id'    => bool,
 *     ],
 * ]
 * ```
 *
 * Handler routing: a handler attaches to this logger only when its
 * `channels` gate admits {@see $channel} AND its `env` gate is satisfied.
 * This keeps subsystem logs (events.log, plugins.log) scoped to their own
 * channels instead of receiving every application record, and avoids the
 * write-amplification of pushing all handlers onto every channel.
 *
 * @package Phlix\Common\Logger
 */
class StructuredLogger implements LoggerInterface
{
    private Logger $logger;
    private string $channel;
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config Logger config — see class docblock for shape.
     */
    public function __construct(string $channel, array $config)
    {
        $this->channel = $channel;
        $this->config = $config;
        $this->logger = new Logger($channel);
        // Monolog's infinite-loop guard tracks recursion depth per PHP Fiber, but
        // Swoole coroutines are NOT Fibers — so it falls back to a single shared
        // counter on this (container-singleton) logger. Under SWOOLE_HOOK_ALL a
        // handler's file write yields the coroutine mid-addRecord, letting a
        // concurrent coroutine re-enter the same logger instance; the shared
        // counter then reaches 3 and Monolog falsely "detects an infinite logging
        // loop" and DROPS the record. No handler or processor here emits its own
        // log, so there is no real cycle to detect — disable the guard.
        $this->logger->useLoggingLoopDetection(false);

        $this->setupHandlers();
        $this->setupProcessors();
    }

    private function setupHandlers(): void
    {
        $handlers = $this->config['handlers'] ?? [];
        if (!is_array($handlers)) {
            return;
        }

        foreach ($handlers as $handlerConfig) {
            if (!is_array($handlerConfig)) {
                continue;
            }
            /** @var array<string, mixed> $handlerConfig */
            if (!$this->handlerAppliesToChannel($handlerConfig)) {
                continue;
            }
            if (!$this->handlerEnvEnabled($handlerConfig)) {
                continue;
            }
            $handler = $this->createHandler($handlerConfig);
            // The handler's level is already set via its constructor by
            // createHandler(); Monolog's HandlerInterface does not expose
            // setLevel() so we cannot adjust it here generically.
            //
            // Wrap every routed handler in a WhatFailureGroupHandler so an
            // exception thrown during the inner handler's write can NEVER
            // propagate out of the logger. This defuses a PHP 8.5 + Swoole
            // coroutine hazard: Monolog's StreamHandler installs a temporary
            // set_error_handler() around its fwrite() and THROWS
            // "Writing to the log file failed: <msg>" if ANY error is captured
            // during that window. set_error_handler() is process-global, so an
            // E_DEPRECATED emitted by a DIFFERENT coroutine that happens to run
            // while our write is in flight (e.g. workerman/mysql's PDO connect,
            // or any deprecated no-op) is captured here and turns a SUCCESSFUL
            // write into a spurious throw -> unhandled worker exception -> 500.
            // The fwrite has already landed, so swallowing the throw loses no
            // record. Routing is unaffected: the channel/env gates above decide
            // WHICH handlers attach, and the wrapper delegates isHandling()
            // (hence per-handler level filtering, e.g. error.log's Error gate)
            // to its single inner handler transparently.
            $this->logger->pushHandler(new WhatFailureGroupHandler([$handler]));
        }
    }

    /**
     * Whether a handler should attach to this logger's channel.
     *
     * A handler with no `channels` key — or a non-array / empty one —
     * applies to ALL channels, preserving the historical behavior for the
     * general application log (app.log) and the error-aggregation log
     * (error.log). When `channels` is a non-empty list, the handler
     * attaches only when this logger's own channel is a member.
     *
     * @param array<string, mixed> $config
     */
    private function handlerAppliesToChannel(array $config): bool
    {
        $channels = $config['channels'] ?? null;
        if (!is_array($channels) || $channels === []) {
            return true;
        }
        return in_array($this->channel, $channels, true);
    }

    /**
     * Whether a handler's `env` gate (if any) is satisfied.
     *
     * A handler with no `env` key always attaches. When `env` names an
     * environment variable, the handler attaches only when that variable is
     * set to a truthy value. Truthiness is interpreted identically to
     * {@see \Phlix\Common\Events\EventDispatcherFactory::debugEnabled()}
     * (`1`/`true`/`yes`/`on`, case-insensitive) so both subsystems agree on
     * the same PHLIX_DEBUG_EVENTS switch.
     *
     * @param array<string, mixed> $config
     */
    private function handlerEnvEnabled(array $config): bool
    {
        $envVar = $config['env'] ?? null;
        if (!is_string($envVar) || $envVar === '') {
            return true;
        }
        $value = getenv($envVar);
        if ($value === false) {
            return false;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createHandler(array $config): HandlerInterface
    {
        $type = self::stringOption($config, 'type', 'rotating_file');
        $path = self::stringOption($config, 'path', 'php://stdout');
        $level = $this->mapLevel(self::stringOption($config, 'level', 'debug'));

        switch ($type) {
            case 'rotating_file':
                return new RotatingFileHandler(
                    $path,
                    self::intOption($config, 'max_files', 30),
                    $level
                );

            case 'stream':
                return new StreamHandler(
                    $path,
                    $level
                );

            case 'error':
                return new RotatingFileHandler(
                    $path,
                    self::intOption($config, 'max_files', 30),
                    Level::Error
                );

            case 'audit':
                return new RotatingFileHandler(
                    $path,
                    self::intOption($config, 'max_files', 90),
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

        $processors = $this->config['processors'] ?? [];
        if (!is_array($processors)) {
            return;
        }

        if (!empty($processors['request_id'])) {
            $this->logger->pushProcessor(static function (LogRecord $record): LogRecord {
                $extra = $record->extra;
                $extra['request_id'] = self::resolveRequestId();
                return $record->with(extra: $extra);
            });
        }

        if (!empty($processors['user_id'])) {
            $this->logger->pushProcessor(static function (LogRecord $record): LogRecord {
                $extra = $record->extra;
                $extra['user_id'] = self::resolveUserId();
                return $record->with(extra: $extra);
            });
        }
    }

    private static function resolveRequestId(): string
    {
        $headerValue = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        if (is_string($headerValue) && $headerValue !== '') {
            return $headerValue;
        }
        return uniqid('req-');
    }

    private static function resolveUserId(): ?string
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $value = $_SESSION['user_id'];
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
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
     * @param array<array-key, mixed> $context Widened from array<string,mixed> to
     *        match Psr\Log\LoggerInterface::log() exactly — an override may not
     *        narrow a parameter type (LSP), and a caller holding the interface is
     *        free to pass an integer-keyed array.
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $context['channel'] = $this->channel;
        $resolvedLevel = $this->resolveLevel($level);
        $this->logger->log($resolvedLevel, (string) $message, $context);
    }

    /**
     * Normalize a mixed level argument into a Monolog {@see Level}.
     */
    private function resolveLevel(mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }
        if (is_string($level)) {
            return $this->mapLevel($level);
        }
        if (is_int($level)) {
            return $this->mapLevel(self::levelNameFromInt($level));
        }
        if ($level instanceof Stringable) {
            return $this->mapLevel((string) $level);
        }
        return Level::Info;
    }

    private static function levelNameFromInt(int $level): string
    {
        return match ($level) {
            Level::Emergency->value => 'emergency',
            Level::Alert->value     => 'alert',
            Level::Critical->value  => 'critical',
            Level::Error->value     => 'error',
            Level::Warning->value   => 'warning',
            Level::Notice->value    => 'notice',
            Level::Info->value      => 'info',
            Level::Debug->value     => 'debug',
            default                 => 'info',
        };
    }

    /**
     * Read a string option from an untyped config sub-array, falling back
     * to a default when missing or of the wrong type.
     *
     * @param array<string, mixed> $config
     */
    private static function stringOption(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * Read an int option from an untyped config sub-array, falling back to
     * a default when missing or of the wrong type.
     *
     * @param array<string, mixed> $config
     */
    private static function intOption(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }
}
