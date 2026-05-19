<?php

declare(strict_types=1);

namespace Phlex\Admin\Dto;

/**
 * Typed view of the single MySQL connection block from
 * `config/database.php` that the backup subsystem uses to invoke
 * `mysqldump` / `mysql` CLI commands.
 *
 * @package Phlex\Admin\Dto
 * @since 0.20.0
 */
final class DbConnectionConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $database,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            host: self::asString($raw['host'] ?? '127.0.0.1'),
            port: self::asInt($raw['port'] ?? 3306),
            username: self::asString($raw['username'] ?? 'root'),
            password: self::asString($raw['password'] ?? ''),
            database: self::asString($raw['database'] ?? 'phlex'),
        );
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function asInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return 0;
    }
}
