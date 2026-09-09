<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

use RuntimeException;

/**
 * Thrown by {@see SidecarWriter} when its pre-flight fails: the sidecar target
 * directory is missing or not writable (S88, ruling R2).
 *
 * The S87 interface contract requires `write()` to THROW on failure — the
 * {@see MetadataWriteWorker} catches Throwable per writer and logs a warning,
 * so this exception IS the operator-visible status: its message carries the
 * item id, the exact target directory, and the reason (missing vs. not
 * writable, with the systemd ReadWritePaths hint for the known prior failure
 * mode — the `/var/artwork` sandbox incident precedent).
 *
 * @since S88
 */
final class SidecarNotWritableException extends RuntimeException
{
    private function __construct(
        public readonly string $itemId,
        public readonly string $targetDir,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Sidecar write refused for item %s: %s [%s]',
            $itemId,
            $reason,
            $targetDir,
        ));
    }

    public static function missingDirectory(string $itemId, string $targetDir): self
    {
        return new self(
            $itemId,
            $targetDir,
            'target directory does not exist (was the media file moved or deleted?)',
        );
    }

    public static function degenerateDirectory(string $itemId, string $targetDir): self
    {
        return new self(
            $itemId,
            $targetDir,
            'item row carries no usable media directory (empty path) - refusing to write '
                . 'sidecars into the process working directory',
        );
    }

    public static function notWritable(string $itemId, string $targetDir): self
    {
        return new self(
            $itemId,
            $targetDir,
            'target directory is not writable by the phlix worker user - check the filesystem '
                . 'permissions and, for a systemd deployment, add it to the unit ReadWritePaths',
        );
    }
}
