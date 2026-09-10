<?php

/**
 * Phlix media server component: Storage.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Storage;

/**
 * Generic, media-item-agnostic image validation / resize / atomic-write service.
 *
 * S71 extracts this from the poster-only {@see ArtworkStorage} pipeline so the
 * same guarantees can serve ANY keyed image cache — posters, title logos,
 * backdrops, or people photos — with no coupling to a "media item UUID". The
 * store is addressed by an opaque flat target KEY (the `itemDir()` charset
 * filter it inherits accepts `[a-zA-Z0-9-]` only, e.g. a UUID, `people-42`, or a
 * content hash) resolved under a base directory; nested keys containing a path
 * separator are refused before any filesystem call.
 *
 * Security guarantees (moved verbatim from {@see ArtworkStorage}):
 * - MIME type is verified via both {@see getimagesize()} and {@see finfo_file()}
 *   so a file cannot bypass the image validator by renaming a PHP script.
 * - Dimension cap of 4096×4096 prevents memory exhaustion from giant images.
 * - Size variants are re-encoded as JPEG at 85% quality (stripping EXIF);
 *   {@see storeBytes()} stores caller-supplied bytes verbatim for formats that
 *   must not be re-encoded (e.g. transparency-safe PNG logos).
 * - A target key must match the flat `[a-zA-Z0-9-]+` charset EXACTLY (empty,
 *   nested, dotted, and traversal keys are refused before any filesystem
 *   call), which keeps every resolved directory one level under the base
 *   directory — this is a key-syntax gate, not a realpath() jail.
 * - Every write goes through a temp-then-rename in the SAME directory, so a
 *   concurrent reader never observes a truncated file.
 *
 * KNOWN LIMIT (by design): this service validates and transforms LOCAL files.
 * Fetching bytes from a remote source stays in the callers — {@see
 * ArtworkStorage} keeps its TMDB path-fragment download seam untouched, because
 * widening it to arbitrary URLs is the SSRF surface owed to S73's allowlist.
 *
 * @package Phlix\Media\Storage
 */
class ImageResizer
{
    /** Original (full-size) variant name used by {@see resizeToWidths()}. */
    public const ORIGINAL = 'original';

    /** JPEG quality for re-encoded variants. */
    public const JPEG_QUALITY = 85;

    /** Maximum image dimension before resize (prevents memory exhaustion). */
    private const MAX_DIMENSION = 4096;

    /** PHP executable MIME — explicitly rejected even if the extension is harmless. */
    private const FORBIDDEN_MIME = 'application/x-httpd-php';

    /** Accepted image types keyed by their {@see IMAGETYPE_*} value. */
    private const ACCEPTED_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_GIF  => 'image/gif',
    ];

    public function __construct(
        private string $baseDir = '/var/images/',
    ) {
        // Normalize path to always have exactly one trailing slash
        $this->baseDir = rtrim($this->baseDir, '/') . '/';
    }

    /**
     * The normalized base directory every target key resolves under.
     *
     * Callers that own a storage root (e.g. {@see ArtworkStorage}) compare this
     * against their own to fail fast on a mismatched injection.
     */
    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * Resolve a flat target key to its jailed storage directory.
     *
     * @param string $targetKey Flat key matching `[a-zA-Z0-9-]+` (a UUID,
     *                          `people-42`, a content hash — anything the
     *                          charset accepts).
     * @throws \InvalidArgumentException if the key is empty or contains any
     *                                   character outside the charset (a path
     *                                   separator, dot segment, or traversal
     *                                   sequence), BEFORE any filesystem call.
     */
    public function targetDir(string $targetKey): string
    {
        // Sanitize target key to prevent path traversal
        $sanitizedKey = preg_replace('/[^a-zA-Z0-9\-]/', '', $targetKey);
        if ($sanitizedKey === '' || $sanitizedKey !== $targetKey) {
            throw new \InvalidArgumentException('Invalid target key for image storage');
        }

        return $this->baseDir . $sanitizedKey . '/';
    }

    /**
     * Ensure the storage directory for a target key exists.
     *
     * @throws \InvalidArgumentException on an unusable key (via {@see targetDir()}).
     * @throws \RuntimeException        when the directory cannot be created.
     */
    public function ensureTargetDirExists(string $targetKey): void
    {
        $dir = $this->targetDir($targetKey);
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true)) {
            throw new \RuntimeException(
                sprintf('Failed to create artwork storage directory: %s', $dir),
            );
        }
    }

    /**
     * Validate, resize into the given width variants, and store the original —
     * the full keyed write pipeline {@see ArtworkStorage::downloadAndStore()}
     * used to run inline.
     *
     * Order of operations is the historical poster pipeline's, byte-for-byte:
     * validate the source, ensure the target directory, generate each `w###`
     * variant in the given order, then store the `original` copy. A variant that
     * fails to generate is skipped (not fatal); the return lists only the sizes
     * actually stored.
     *
     * @param string    $targetKey    Flat target key (see {@see targetDir()}).
     * @param string    $sourcePath   Path to the already-downloaded source image.
     * @param list<int> $widths       Target widths, e.g. [185, 342, 500, 780].
     * @param bool      $withOriginal Also store the full-size `original` variant.
     * @return list<string>           Size names stored ('w185', …, 'original').
     * @throws \InvalidArgumentException if the source fails validation or the
     *                                   key is unusable.
     * @throws \RuntimeException        if the target directory cannot be created.
     */
    public function resizeToWidths(
        string $targetKey,
        string $sourcePath,
        array $widths,
        bool $withOriginal = true,
    ): array {
        // Validate the source image before touching the target directory.
        $this->validateImageFile($sourcePath);

        $this->ensureTargetDirExists($targetKey);

        $stored = [];
        foreach ($widths as $width) {
            if ($this->generateVariant($targetKey, $sourcePath, $width) !== null) {
                $stored[] = 'w' . $width;
            }
        }

        if ($withOriginal && $this->storeOriginal($targetKey, $sourcePath)) {
            $stored[] = self::ORIGINAL;
        }

        return $stored;
    }

    /**
     * Store caller-supplied bytes verbatim (NO re-encode) as a named file under
     * the key's directory, via the same atomic writer the JPEG variants use.
     *
     * This is the transparency-safe path: a PNG that must survive byte-for-byte
     * (title logos) never enters the JPEG pipeline.
     *
     * @param string $targetKey Flat target key (see {@see targetDir()}).
     * @param string $filename  File name inside the key directory (e.g. 'logo.png').
     * @param string $bytes     Raw bytes to store.
     * @return string|null      Full path to the stored file, or null on empty
     *                          bytes or any I/O failure.
     * @throws \InvalidArgumentException if the key is unusable.
     */
    public function storeBytes(string $targetKey, string $filename, string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $this->ensureTargetDirExists($targetKey);

        $targetFile = $this->targetDir($targetKey) . $filename;
        if (!$this->atomicWriteVariant($targetFile, $bytes)) {
            return null;
        }

        return $targetFile;
    }

    /**
     * Validate an image file meets security requirements.
     *
     * @param string $tmpPath Path to the image file
     * @throws \InvalidArgumentException if validation fails
     */
    public function validateImageFile(string $tmpPath): void
    {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new \InvalidArgumentException('Artwork file is not accessible');
        }

        $fileSize = filesize($tmpPath);
        if ($fileSize === false) {
            throw new \InvalidArgumentException('Could not determine artwork file size');
        }
        if ($fileSize === 0) {
            throw new \InvalidArgumentException('Artwork file is empty');
        }

        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            throw new \InvalidArgumentException('Artwork file is not a valid image');
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
                    'Artwork dimensions (%d×%d) exceed maximum of %d×%d',
                    $width,
                    $height,
                    self::MAX_DIMENSION,
                    self::MAX_DIMENSION,
                ),
            );
        }

        if ($width === 0 || $height === 0) {
            throw new \InvalidArgumentException('Artwork has zero dimension');
        }

        if (!array_key_exists($type, self::ACCEPTED_TYPES)) {
            throw new \InvalidArgumentException(
                sprintf('Artwork image type %d is not supported', $type),
            );
        }

        $realMime = $this->getRealMime($tmpPath);
        if ($realMime === null) {
            throw new \InvalidArgumentException('Could not determine real MIME type of artwork');
        }

        if ($realMime === self::FORBIDDEN_MIME) {
            throw new \InvalidArgumentException('Artwork file has forbidden MIME type');
        }

        if (!in_array($realMime, self::ACCEPTED_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Artwork real MIME type "%s" does not match expected image types', $realMime),
            );
        }
    }

    /**
     * Atomically write bytes to a variant file.
     *
     * Writes to a uniquely-named sibling temp file in the SAME directory (so the
     * final {@see rename()} is atomic on a single filesystem) and then renames it
     * onto the final path. `serveArtwork` streams these files via `withFile()`,
     * and keyed writes only early-return when ALL variants already exist — so a
     * retry after a partial prior failure would otherwise do an in-place
     * `file_put_contents()` overwrite, exposing a truncated/0-byte JPEG (and a
     * mid-write ETag/Last-Modified) to a concurrent reader. The temp file is
     * removed on any write or rename failure so no orphaned `.tmp` files leak.
     *
     * Mirrors {@see \Phlix\Media\Storage\AvatarStorage::store()}'s temp-then-rename idiom.
     *
     * @param string $variantFile Absolute final path (e.g. '…/w185.jpg').
     * @param string $bytes       Encoded JPEG bytes (or verbatim bytes via
     *                            {@see storeBytes()}) to write.
     * @return bool               True on success, false on any I/O failure.
     */
    public function atomicWriteVariant(string $variantFile, string $bytes): bool
    {
        // Unique temp name in the SAME directory: keeps rename() atomic
        // (same filesystem) and collision-safe if two coroutines/processes
        // regenerate the same target concurrently. PID + random bytes.
        $pid = getmypid();
        $tmpFile = $variantFile . '.' . ($pid !== false ? $pid : 0) . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $written = file_put_contents($tmpFile, $bytes);
        if ($written === false) {
            $this->cleanupTemp($tmpFile);
            return false;
        }

        chmod($tmpFile, 0644);

        // @-suppressed: the boolean return is authoritative and this is a
        // best-effort write on a resident worker (no warning spam on failure).
        if (!@rename($tmpFile, $variantFile)) {
            $this->cleanupTemp($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * Generate a resized variant of an image under the key's directory.
     *
     * @param string $targetKey Flat target key
     * @param string $tmpPath   Path to source image
     * @param int    $width     Target width
     * @return string|null      Path to stored variant or null on failure
     */
    private function generateVariant(string $targetKey, string $tmpPath, int $width): ?string
    {
        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return null;
        }

        /** @var int */
        $sourceWidth = $imageInfo[0];
        /** @var int */
        $sourceHeight = $imageInfo[1];
        /** @var int */
        $sourceType = $imageInfo[2];

        // Calculate proportional height
        $ratio = $sourceWidth > 0 ? $sourceWidth / $width : 1;
        $height = (int) round($sourceHeight / $ratio);

        // Don't upscale
        if ($width >= $sourceWidth) {
            // Just copy original if we're upscaling or very close
            return $this->storeVariant($targetKey, $tmpPath, 'w' . $width, $sourceType);
        }

        $source = $this->createImageFromType($tmpPath, $sourceType);
        if ($source === false) {
            return null;
        }

        // Guard against invalid computed dimensions (PHPStan doesn't track the math)
        if ($width < 1 || $height < 1) {
            return null;
        }

        $canvas = @imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            return null;
        }

        // Preserve transparency for PNG and WebP
        if ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_WEBP) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }

        $resampled = imagecopyresampled(
            $canvas,
            $source,
            0,       // dstX
            0,       // dstY
            0,       // srcX
            0,       // srcY
            $width,  // dstW
            $height, // dstH
            $sourceWidth,  // srcW
            $sourceHeight, // srcH
        );

        if ($resampled === false) {
            return null;
        }

        // Capture JPEG
        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpegData = ob_get_clean();

        if ($jpegData === false || $jpegData === '') {
            return null;
        }

        $variantFile = $this->targetDir($targetKey) . 'w' . $width . '.jpg';
        if (!$this->atomicWriteVariant($variantFile, $jpegData)) {
            return null;
        }

        return $variantFile;
    }

    /**
     * Store the original (full-size) variant.
     */
    private function storeOriginal(string $targetKey, string $tmpPath): bool
    {
        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return false;
        }

        /** @var int */
        $sourceType = $imageInfo[2];
        return $this->storeVariant($targetKey, $tmpPath, self::ORIGINAL, $sourceType) !== null;
    }

    /**
     * Store a variant from a source file, re-encoding as JPEG.
     */
    private function storeVariant(string $targetKey, string $tmpPath, string $sizeName, int $sourceType): ?string
    {
        $source = $this->createImageFromType($tmpPath, $sourceType);
        if ($source === false) {
            return null;
        }

        // imagesx/imagesy return int<1, max> (positive int) when the image resource is valid
        $width = imagesx($source);
        $height = imagesy($source);

        $canvas = @imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            return null;
        }

        // Preserve transparency for PNG and WebP
        if ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_WEBP) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }

        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        // Capture JPEG
        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpegData = ob_get_clean();

        if ($jpegData === false || $jpegData === '') {
            return null;
        }

        $variantFile = $this->targetDir($targetKey) . $sizeName . '.jpg';
        if (!$this->atomicWriteVariant($variantFile, $jpegData)) {
            return null;
        }

        return $variantFile;
    }

    /**
     * Create a GD image resource from file path and IMAGETYPE constant.
     *
     * @param string $path Image file path
     * @param int    $type IMAGETYPE_* constant
     * @return \GdImage|false
     */
    private function createImageFromType(string $path, int $type): \GdImage|false
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_GIF  => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default        => throw new \InvalidArgumentException(
                sprintf('Unsupported image type %d for artwork processing', $type),
            ),
        };
    }

    /**
     * Determine the real MIME type of a file using finfo.
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
     * Best-effort removal of a temp file after a failed write.
     */
    private function cleanupTemp(string $tmpFile): void
    {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}
