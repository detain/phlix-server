<?php

declare(strict_types=1);

namespace Phlix\Admin;

/**
 * Result of a backup restore operation.
 *
 * @package Phlix\Admin
 */
class RestoreResult
{
    /**
     * @param bool $success Whether the restore succeeded
     * @param string $message Human-readable status message
     * @param string|null $error Error details if restore failed
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Create a successful restore result.
     */
    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    /**
     * Create a failed restore result.
     */
    public static function failure(string $message, ?string $error = null): self
    {
        return new self(false, $message, $error);
    }
}
