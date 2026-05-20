<?php

declare(strict_types=1);

namespace Phlix\Common\Events;

use Monolog\Level;
use Phlix\Common\Logger\StructuredLogger;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Adapts a Phlix {@see StructuredLogger} into a PSR-3
 * {@see \Psr\Log\LoggerInterface}.
 *
 * The Phlix StructuredLogger wraps Monolog but does not directly
 * implement PSR-3 (its `log()` method takes a Monolog {@see Level}
 * rather than the PSR-3 string level). Tukio's
 * {@see \Crell\Tukio\DebugEventDispatcher} requires PSR-3, so this
 * adapter bridges the two.
 *
 * @internal Used only by {@see EventDispatcherFactory} to surface the
 *           events log channel as a PSR-3 logger.
 *
 * @package Phlix\Common\Events
 * @since 0.10.0
 */
final class StructuredLoggerPsrAdapter extends AbstractLogger
{
    /**
     * @param StructuredLogger $delegate The underlying Phlix structured
     *                                   logger that records will be
     *                                   forwarded to.
     */
    public function __construct(private readonly StructuredLogger $delegate)
    {
    }

    /**
     * Forward a PSR-3 log record onto the wrapped StructuredLogger.
     *
     * @param mixed              $level   PSR-3 log level (string constant
     *                                    from {@see LogLevel}, or its
     *                                    integer / Monolog equivalent).
     * @param string|Stringable  $message Log message, possibly with
     *                                    placeholders.
     * @param array<string, mixed> $context Structured context for the record.
     *
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $monologLevel = self::resolveLevel($level);
        $this->delegate->log($monologLevel, (string)$message, $context);
    }

    /**
     * Translate a PSR-3 string level into a Monolog {@see Level}.
     *
     * @param mixed $level PSR-3 level (string), Monolog Level, or
     *                     numeric level value.
     *
     * @return Level Equivalent Monolog level. Unknown values fall back to
     *               {@see Level::Debug}.
     */
    private static function resolveLevel(mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        if (is_int($level)) {
            return match ($level) {
                Level::Emergency->value => Level::Emergency,
                Level::Alert->value     => Level::Alert,
                Level::Critical->value  => Level::Critical,
                Level::Error->value     => Level::Error,
                Level::Warning->value   => Level::Warning,
                Level::Notice->value    => Level::Notice,
                Level::Info->value      => Level::Info,
                default                 => Level::Debug,
            };
        }

        $name = is_string($level) ? strtolower($level) : LogLevel::DEBUG;

        return match ($name) {
            LogLevel::EMERGENCY => Level::Emergency,
            LogLevel::ALERT     => Level::Alert,
            LogLevel::CRITICAL  => Level::Critical,
            LogLevel::ERROR     => Level::Error,
            LogLevel::WARNING   => Level::Warning,
            LogLevel::NOTICE    => Level::Notice,
            LogLevel::INFO      => Level::Info,
            default             => Level::Debug,
        };
    }
}
