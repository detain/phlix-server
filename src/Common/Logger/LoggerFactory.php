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
