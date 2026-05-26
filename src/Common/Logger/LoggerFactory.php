<?php

declare(strict_types=1);

namespace Phlix\Common\Logger;

class LoggerFactory
{
    /** @var array<string, StructuredLogger> */
    private static array $loggers = [];
    private static string $configPath = '';

    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
    }

    public static function get(string $channel): StructuredLogger
    {
        if (!isset(self::$loggers[$channel])) {
            $config = self::loadConfig();
            self::$loggers[$channel] = new StructuredLogger($channel, $config);
        }
        return self::$loggers[$channel];
    }

    public static function reset(): void
    {
        self::$loggers = [];
        // Drop the path too — otherwise a test that already deleted its temp
        // logger.php leaves a stale pointer that emits an `include` warning the
        // next time get() is called from another fixture's tear-down.
        self::$configPath = '';
    }

    /**
     * Load the config file referenced by {@see self::init()}. Returns an empty
     * array (silently) when the path is unset or no longer exists — many tests
     * spin up a temp config and tear it down between cases, so swallowing the
     * missing-file warning here is a feature, not a bug.
     *
     * @return array<string, mixed>
     */
    private static function loadConfig(): array
    {
        if (self::$configPath === '' || !is_file(self::$configPath)) {
            return [];
        }

        /** @psalm-suppress UnresolvableInclude self::$configPath is set at runtime */
        $config = include self::$configPath;

        return is_array($config) ? $config : [];
    }
}
