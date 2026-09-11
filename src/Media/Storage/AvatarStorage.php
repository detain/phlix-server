<?php

/**
 * Phlix media server component: Storage.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Storage;

use Phlix\Auth\SignedUrl;

/**
 * Stores, resizes, and serves user avatar images.
 *
 * Avatars are stored as 256×256 JPEG files in {@see self::STORAGE_DIR}, one
 * file per user (named `<userId>.jpg`). The original uploaded file is never
 * stored — a temporary file is validated, GD-resized to a square cover frame,
 * re-encoded as JPEG at 85% quality (stripping EXIF), and atomically renamed
 * into place.
 *
 * S456: the resize step itself delegates to {@see ImageResizer::
 * renderSquareCoverJpeg()} — the estate's single home for GD resize arithmetic
 * — while everything Avatar-specific stays here, byte-for-byte: the stricter
 * 5 MB upload cap, the 'Avatar …' validation messages, the flat `<userId>.jpg`
 * layout (a deliberate divergence from the keyed-directory convention, so no
 * target-key/base-dir assertion applies), and the temp-then-rename store.
 *
 * Security guarantees:
 * - MIME type is verified via both {@see getimagesize()} and {@see finfo_file()}
 *   so a file cannot bypass the image validator by renaming a PHP script.
 * - Dimension cap of 4096×4096 prevents memory exhaustion from giant uploads.
 * - Output is always JPEG (never the original format), and EXIF is stripped.
 *
 * @package Phlix\Media\Storage
 */
class AvatarStorage
{
    /** Square resize target in pixels. */
    public const TARGET_SIZE = 256;

    /** Maximum upload size in bytes (5 MB). */
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** Absolute path to the avatar storage directory. */
    public const STORAGE_DIR = '/var/avatars/';

    /**
     * Accepted image types keyed by their {@see IMAGETYPE_*} value.
     *
     * Deliberately excludes SVG (scalar vectors), BMP, TIFF, and other types
     * that could cause parser DoS or contain embedded scripts.
     *
     * @var array<int, string>
     */
    public const ACCEPTED_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_GIF  => 'image/gif',
    ];

    /** PHP executable MIME — explicitly rejected even if the extension is harmless. */
    private const FORBIDDEN_MIME = 'application/x-httpd-php';

    /** Maximum image dimension before resize (prevents memory exhaustion). */
    private const MAX_DIMENSION = 4096;

    public function __construct(
        private string $storageDir = self::STORAGE_DIR,
        private readonly ImageResizer $resizer = new ImageResizer(),
    ) {
        // Normalize path to always have exactly one trailing slash
        $this->storageDir = rtrim($this->storageDir, '/') . '/';
    }

    /**
     * Store a resized, re-encoded avatar for a user.
     *
     * 1. Validate MIME using getimagesize() + finfo (real MIME, not extension)
     * 2. Check file size ≤ MAX_FILE_SIZE
     * 3. GD-resize to 256×256 square (cover fit, center crop)
     * 4. Re-encode as JPEG at 85% quality (strips EXIF)
     * 5. Atomic write: temp file + rename (prevents partial writes)
     * 6. Delete prior avatar file if it exists
     * 7. Return the storage path (e.g. '/var/avatars/user-uuid.jpg')
     *
     * @param string $userId   User UUID
     * @param string $tmpPath  Path to the uploaded tmp file
     * @return string          The stored file path
     * @throws \InvalidArgumentException if validation fails (MIME, size, dimensions)
     * @throws \RuntimeException         if storage fails (I/O, permissions)
     */
    public function store(string $userId, string $tmpPath): string
    {
        $this->ensureStorageDirExists();

        $this->validateTmpFile($tmpPath);

        $avatarData = $this->resizeToAvatar($tmpPath);

        $this->delete($userId);

        $targetFile = $this->storageDir . $userId . '.jpg';
        $tmpFile    = $this->storageDir . bin2hex(random_bytes(16)) . '.tmp';

        $written = file_put_contents($tmpFile, $avatarData);
        if ($written === false) {
            throw new \RuntimeException('Failed to write avatar temp file');
        }

        chmod($tmpFile, 0644);

        if (!rename($tmpFile, $targetFile)) {
            unlink($tmpFile);
            throw new \RuntimeException('Failed to rename avatar temp file to target');
        }

        return $targetFile;
    }

    /**
     * Delete the avatar file for a user (no-op if none exists).
     *
     * @param string $userId User UUID
     */
    public function delete(string $userId): void
    {
        $path = $this->storageDir . $userId . '.jpg';

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Get the file path for a user's avatar (null if none).
     *
     * @param string $userId User UUID
     * @return string|null
     */
    public function path(string $userId): ?string
    {
        $path = $this->storageDir . $userId . '.jpg';

        return is_file($path) ? $path : null;
    }

    /**
     * Resolve an avatar path to a signed URL.
     *
     * The path returned is a relative path like '/api/v1/users/{id}/avatar'
     * which is signed via {@see SignedUrl::mint()}.
     *
     * @param string      $userId      User UUID
     * @param string|null $avatarPath  Override path (for re-signing on update)
     * @return string|null             Signed URL or null if no avatar
     */
    public function url(string $userId, ?string $avatarPath = null): ?string
    {
        if ($avatarPath === null) {
            if ($this->path($userId) === null) {
                return null;
            }
        }

        $signer = SignedUrl::fromEnv();
        $resourcePath = '/api/v1/users/' . $userId . '/avatar';

        return $signer->mint($resourcePath);
    }

    /**
     * Validate the uploaded tmp file for avatar processing.
     *
     * @param string $tmpPath
     * @throws \InvalidArgumentException
     */
    private function validateTmpFile(string $tmpPath): void
    {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new \InvalidArgumentException('Avatar tmp file is not accessible');
        }

        $fileSize = filesize($tmpPath);
        if ($fileSize === false) {
            throw new \InvalidArgumentException('Could not determine avatar file size');
        }
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                sprintf('Avatar file exceeds maximum size of %d bytes', self::MAX_FILE_SIZE),
            );
        }
        if ($fileSize === 0) {
            throw new \InvalidArgumentException('Avatar tmp file is empty');
        }

        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            throw new \InvalidArgumentException('Avatar tmp file is not a valid image');
        }

        /** @var int */
        $width = $imageInfo[0];
        /** @var int */
        $height = $imageInfo[1];
        /** @var int */
        $type = $imageInfo[2];

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Avatar image dimensions (%d×%d) exceed maximum of %d×%d',
                    $width,
                    $height,
                    self::MAX_DIMENSION,
                    self::MAX_DIMENSION,
                ),
            );
        }

        if ($width === 0 || $height === 0) {
            throw new \InvalidArgumentException('Avatar image has zero dimension');
        }

        if (!array_key_exists($type, self::ACCEPTED_TYPES)) {
            throw new \InvalidArgumentException(
                sprintf('Avatar image type %d is not supported', $type),
            );
        }

        $realMime = $this->getRealMime($tmpPath);
        if ($realMime === null) {
            throw new \InvalidArgumentException('Could not determine real MIME type of avatar');
        }

        if ($realMime === self::FORBIDDEN_MIME) {
            throw new \InvalidArgumentException('Avatar file has forbidden MIME type');
        }

        if (!in_array($realMime, self::ACCEPTED_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Avatar real MIME type "%s" does not match expected image types', $realMime),
            );
        }
    }

    /**
     * Resize an image to a 256×256 JPEG avatar using cover fit.
     *
     * S456: the GD arithmetic lives in {@see ImageResizer::renderSquareCoverJpeg()};
     * this method only maps its granular failure reasons back onto the exact
     * exception types and messages this class has always thrown, and applies the
     * original empty-capture rule ('' is an encode failure here).
     *
     * @param string $tmpPath
     * @return string Binary JPEG data
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private function resizeToAvatar(string $tmpPath): string
    {
        /** @var array{bytes: string|null, reason: string|null} $rendered */
        $rendered = $this->resizer->renderSquareCoverJpeg($tmpPath, self::TARGET_SIZE);
        $bytes = $rendered['bytes'];
        $reason = $rendered['reason'];

        if ($reason === ImageResizer::REASON_UNREADABLE) {
            throw new \InvalidArgumentException('Cannot read image for avatar resizing');
        }

        if ($bytes === null) {
            throw match ($reason) {
                ImageResizer::REASON_DECODE_FAILED => new \RuntimeException(
                    'Failed to create image resource from avatar tmp file',
                ),
                ImageResizer::REASON_CANVAS_FAILED => new \RuntimeException('Failed to create avatar canvas'),
                ImageResizer::REASON_RESAMPLE_FAILED => new \RuntimeException('Failed to resample avatar image'),
                default => new \RuntimeException('Failed to encode avatar as JPEG'),
            };
        }

        if ($bytes === '') {
            throw new \RuntimeException('Failed to encode avatar as JPEG');
        }

        return $bytes;
    }

    /**
     * Determine the real MIME type of a file using finfo.
     *
     * @param string $path
     * @return string|null
     */
    private function getRealMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $raw = finfo_file($finfo, $path);
        // No finfo_close(): the finfo object is freed automatically by GC, and
        // finfo_close() is a deprecated no-op as of PHP 8.5 (its E_DEPRECATED
        // would otherwise be escalated by the error handler and abort the caller).

        if ($raw === false) {
            return null;
        }

        return $raw;
    }

    /**
     * Ensure the storage directory exists, creating it with safe permissions.
     *
     * @throws \RuntimeException if creation fails
     */
    private function ensureStorageDirExists(): void
    {
        if (is_dir($this->storageDir)) {
            return;
        }

        if (!mkdir($this->storageDir, 0755, true)) {
            throw new \RuntimeException(
                sprintf('Failed to create avatar storage directory: %s', $this->storageDir),
            );
        }
    }
}
