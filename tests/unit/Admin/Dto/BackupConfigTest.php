<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin\Dto;

use Phlix\Admin\Dto\BackupConfig;
use Phlix\Admin\Dto\S3Config;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Admin\Dto\BackupConfig
 * @covers \Phlix\Admin\Dto\S3Config
 */
final class BackupConfigTest extends TestCase
{
    public function testDefaultsAreSafeForUnconfiguredEnvironments(): void
    {
        $config = BackupConfig::defaults();

        $this->assertTrue($config->enabled);
        $this->assertSame('/var/phlix/backups', $config->localPath);
        $this->assertSame(5, $config->retentionCount);
        $this->assertSame(7, $config->autoBackupIntervalDays);
        $this->assertInstanceOf(S3Config::class, $config->s3);
        $this->assertFalse($config->s3->enabled);
    }

    public function testFromArrayHydratesScalars(): void
    {
        $config = BackupConfig::fromArray([
            'enabled' => false,
            'local_path' => '/tmp/backups',
            'retention_count' => 12,
            'auto_backup_interval_days' => 3,
            's3' => [
                'enabled' => true,
                'bucket' => 'phlix-backups',
                'region' => 'eu-west-1',
                'access_key' => 'AKIA...',
                'secret_key' => 'shh',
                'endpoint' => 'https://s3.example.com',
                'prefix' => 'backups/',
            ],
        ]);

        $this->assertFalse($config->enabled);
        $this->assertSame('/tmp/backups', $config->localPath);
        $this->assertSame(12, $config->retentionCount);
        $this->assertSame(3, $config->autoBackupIntervalDays);

        $this->assertTrue($config->s3->enabled);
        $this->assertSame('phlix-backups', $config->s3->bucket);
        $this->assertSame('eu-west-1', $config->s3->region);
        $this->assertTrue($config->s3->hasCredentials());
    }

    public function testFromArrayCoercesUnexpectedScalarTypes(): void
    {
        $config = BackupConfig::fromArray([
            'enabled' => 1,
            // `null` for local_path falls back to the documented default.
            'local_path' => null,
            'retention_count' => '15',
            'auto_backup_interval_days' => '0',
            's3' => 'not an array',
        ]);

        $this->assertTrue($config->enabled);
        $this->assertSame('/var/phlix/backups', $config->localPath);
        $this->assertSame(15, $config->retentionCount);
        $this->assertSame(0, $config->autoBackupIntervalDays);
        // Non-array `s3` falls back to defaults
        $this->assertFalse($config->s3->enabled);
        $this->assertSame('', $config->s3->bucket);
    }

    public function testFromArrayCoercesNonStringLocalPathToEmpty(): void
    {
        $config = BackupConfig::fromArray([
            'local_path' => 42,
        ]);

        // Non-null, wrong type collapses to empty string (caller decides
        // whether to mkdir from there).
        $this->assertSame('', $config->localPath);
    }

    public function testS3HasCredentialsRequiresEnabledFlag(): void
    {
        $s3 = S3Config::fromArray([
            'enabled' => false,
            'access_key' => 'AKIA...',
            'secret_key' => 'shh',
        ]);

        $this->assertFalse($s3->hasCredentials());
    }

    public function testS3HasCredentialsRequiresBothKeys(): void
    {
        $s3 = S3Config::fromArray([
            'enabled' => true,
            'access_key' => 'AKIA...',
            'secret_key' => '',
        ]);

        $this->assertFalse($s3->hasCredentials());
    }
}
