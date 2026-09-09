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
 * Thrown by {@see EmbeddedMetadataWriter} when a write that was ALLOWED to run
 * could not be completed safely (S89).
 *
 * Reserved for write failures ONLY — a policy denial or an operator-curated
 * skip is a normal return with a logged reason (coordinator ruling R1), never
 * an exception. Its sibling {@see SidecarNotWritableException} owns pre-flight
 * directory refusals; this exception owns the media-file half of the contract:
 * the temp/remux stage failed, ffmpeg exited non-zero (including a signal
 * kill), or the atomic publish rename failed. Every throw site in the writer
 * has already left the ORIGINAL media file untouched — that is exactly what
 * makes the message safe to log at the worker's per-writer catch as the
 * operator-visible status (S88 interface contract: throw, never swallow).
 *
 * @since S89
 */
final class EmbeddedWriteFailedException extends RuntimeException
{
    private function __construct(
        public readonly string $itemId,
        public readonly string $mediaPath,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Embedded metadata write refused for item %s: %s [%s]',
            $itemId,
            $reason,
            $mediaPath,
        ));
    }

    public static function missingMediaFile(string $itemId, string $mediaPath): self
    {
        return new self(
            $itemId,
            $mediaPath,
            'media file does not exist (was it moved or deleted between scan and drain?)',
        );
    }

    public static function degenerateDirectory(string $itemId, string $mediaPath): self
    {
        return new self(
            $itemId,
            $mediaPath,
            'item row carries no usable media directory (empty path) - refusing to rewrite '
                . 'files relative to the process working directory',
        );
    }

    public static function directoryNotWritable(string $itemId, string $mediaPath): self
    {
        return new self(
            $itemId,
            $mediaPath,
            'media directory is not writable by the phlix worker user - embedded writing needs '
                . 'create+rename rights beside the file; for a systemd deployment add the path '
                . 'to the unit ReadWritePaths',
        );
    }

    public static function stageFailed(string $itemId, string $mediaPath, string $reason): self
    {
        return new self($itemId, $mediaPath, $reason);
    }

    public static function publishFailed(string $itemId, string $mediaPath): self
    {
        return new self(
            $itemId,
            $mediaPath,
            'atomic publish (rename over the original) failed after a successful write - the '
                . 'original file is unchanged and no partial tag data was left in place',
        );
    }
}
