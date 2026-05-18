<?php

/**
 * Backup configuration.
 *
 * Controls local backup storage, retention policy, automatic backup scheduling,
 * and optional S3-compatible cloud storage integration.
 */

return [
    /**
     * Enable or disable the backup system.
     */
    'enabled' => true,

    /**
     * Local directory where backup archives are stored.
     */
    'local_path' => '/var/phlex/backups',

    /**
     * Maximum number of backups to retain.
     * Oldest backups beyond this count are automatically deleted by cleanupOldBackups().
     */
    'retention_count' => 5,

    /**
     * Automatic backup interval in days.
     * Set to 0 to disable automatic scheduled backups.
     */
    'auto_backup_interval_days' => 7,

    /**
     * S3-compatible object storage configuration.
     * Leave all S3 values empty/null to use local storage only.
     */
    's3' => [
        /**
         * Enable S3 storage for backups.
         */
        'enabled' => false,

        /**
         * S3 bucket name.
         */
        'bucket' => '',

        /**
         * AWS region (e.g., 'us-east-1').
         */
        'region' => 'us-east-1',

        /**
         * AWS access key ID.
         */
        'access_key' => '',

        /**
         * AWS secret access key.
         */
        'secret_key' => '',

        /**
         * S3 endpoint URL.
         * Leave empty for AWS S3, set for MinIO/Backblaze/etc.
         * Example: 'http://minio:9000'
         */
        'endpoint' => '',

        /**
         * Path prefix for backups within the bucket.
         */
        'prefix' => 'backups/',
    ],
];
