<?php

declare(strict_types=1);

namespace Phlix\Admin\Dto;

/**
 * Typed value object for the backup subsystem configuration.
 *
 * Replaces the legacy `array<string, mixed> $config` shape that
 * {@see \Phlix\Admin\BackupManager} used internally. Strong typing here
 * means every consumer can use a property access (e.g. `$config->localPath`)
 * instead of `(string) ($this->config['local_path'] ?? '...')`.
 *
 * @package Phlix\Admin\Dto
 * @since 0.20.0
 */
final class BackupConfig
{
    /**
     * @param bool     $enabled                 Master switch for the backup system.
     * @param string   $localPath               Directory where local archives are stored.
     * @param int      $retentionCount          Maximum number of archives to retain locally.
     * @param int      $autoBackupIntervalDays  Days between automatic backups (0 disables).
     * @param S3Config $s3                      S3 destination configuration (always set,
     *                                          may have `enabled=false`).
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $localPath,
        public readonly int $retentionCount,
        public readonly int $autoBackupIntervalDays,
        public readonly S3Config $s3,
    ) {
    }

    /**
     * Hydrate a {@see BackupConfig} from an untyped config array (the
     * return value of `config/backup.php`).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $s3Raw = $raw['s3'] ?? [];
        if (!is_array($s3Raw)) {
            $s3Raw = [];
        }
        /** @var array<string, mixed> $s3Raw */

        return new self(
            enabled: self::asBool($raw['enabled'] ?? true),
            localPath: self::asString($raw['local_path'] ?? '/var/phlix/backups'),
            retentionCount: self::asInt($raw['retention_count'] ?? 5),
            autoBackupIntervalDays: self::asInt($raw['auto_backup_interval_days'] ?? 7),
            s3: S3Config::fromArray($s3Raw),
        );
    }

    /**
     * Build the default {@see BackupConfig} used when no config file is
     * present on disk.
     */
    public static function defaults(): self
    {
        return self::fromArray([]);
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

    private static function asBool(mixed $value): bool
    {
        return is_bool($value) ? $value : (bool) $value;
    }
}
