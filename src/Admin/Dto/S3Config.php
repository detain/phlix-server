<?php

declare(strict_types=1);

namespace Phlix\Admin\Dto;

/**
 * Typed value object for S3 backup destination configuration.
 *
 * Hydrated from the `s3` section of `config/backup.php` (see
 * {@see \Phlix\Admin\BackupManager}). Keeping a strongly-typed shape here
 * lets PHPStan reason about every access without per-call `is_string()`
 * narrowing or `(string)` casts on `mixed`.
 *
 * @package Phlix\Admin\Dto
 * @since 0.20.0
 */
final class S3Config
{
    /**
     * @param bool   $enabled    Whether the S3 destination is active.
     * @param string $bucket     Target bucket name.
     * @param string $region     AWS-style region (e.g. `us-east-1`).
     * @param string $accessKey  Access key ID.
     * @param string $secretKey  Secret access key.
     * @param string $endpoint   Custom endpoint URL (empty for AWS S3).
     * @param string $prefix     Path prefix within the bucket
     *                            (e.g. `backups/`).
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $bucket,
        public readonly string $region,
        public readonly string $accessKey,
        public readonly string $secretKey,
        public readonly string $endpoint,
        public readonly string $prefix,
    ) {
    }

    /**
     * Hydrate an {@see S3Config} from an untyped config sub-array.
     *
     * Each field is coerced through an `is_*` guard so the resulting
     * value object exposes only the documented scalar types.
     *
     * @param array<string, mixed> $raw The `s3` sub-array from
     *                                   `config/backup.php`.
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: self::asBool($raw['enabled'] ?? false),
            bucket: self::asString($raw['bucket'] ?? ''),
            region: self::asString($raw['region'] ?? 'us-east-1'),
            accessKey: self::asString($raw['access_key'] ?? ''),
            secretKey: self::asString($raw['secret_key'] ?? ''),
            endpoint: self::asString($raw['endpoint'] ?? ''),
            prefix: self::asString($raw['prefix'] ?? 'backups/'),
        );
    }

    /**
     * Whether the credentials needed to authenticate against S3 are present.
     */
    public function hasCredentials(): bool
    {
        return $this->enabled && $this->accessKey !== '' && $this->secretKey !== '';
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function asBool(mixed $value): bool
    {
        return is_bool($value) ? $value : (bool) $value;
    }
}
