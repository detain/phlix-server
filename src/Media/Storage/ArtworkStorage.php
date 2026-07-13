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
use Phlix\Common\Http\EventLoopTls;
use Phlix\Common\Runtime\WorkerContext;
use Phlix\Server\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Client;

/**
 * Downloads, resizes, and serves TMDB poster images locally.
 *
 * Posters are stored in {@see self::STORAGE_DIR} under subdirectories named
 * by media item UUID. Each subdirectory contains multiple size variants:
 * w185, w342, w500, and original (full-size). This enables responsive images
 * via srcset without requiring clients to fetch from TMDB directly.
 *
 * This solves two problems:
 * 1. LAN/offline installs have no artwork when TMDB is unreachable
 * 2. Clients can serve optimally-sized posters via srcset
 *
 * Security guarantees:
 * - MIME type is verified via both {@see getimagesize()} and {@see finfo_file()}
 *   so a file cannot bypass the image validator by renaming a PHP script.
 * - Dimension cap of 4096×4096 prevents memory exhaustion from giant images.
 * - All variants are re-encoded as JPEG at 85% quality (stripping EXIF).
 * - Storage directory is validated to prevent path traversal.
 *
 * @package Phlix\Media\Storage
 */
class ArtworkStorage
{
    /** Width variants to generate (matches TMDB's standard sizes). */
    public const WIDTHS = [185, 342, 500, 780];

    /** Original size variant name. */
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

    /** TMDB image CDN base URL. */
    private const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p';

    /** Timeout for downloading poster from TMDB (seconds). */
    private const DOWNLOAD_TIMEOUT = 30;

    /** Connect timeout for downloading poster from TMDB (seconds). */
    private const CONNECT_TIMEOUT = 10;

    /** @var Client|null Async HTTP client instance (lazy initialized). */
    private ?Client $asyncClient = null;

    public function __construct(
        private string $storageDir = '/var/artwork/',
    ) {
        // Normalize path to always have exactly one trailing slash
        $this->storageDir = rtrim($this->storageDir, '/') . '/';
    }

    /**
     * Download a poster from TMDB and store all size variants locally.
     *
     * @param string $itemId      Media item UUID
     * @param string $posterPath  TMDB poster path (e.g., '/abc123.jpg')
     * @return string[]           Array of size names that were stored
     * @throws \InvalidArgumentException if the poster path is invalid
     * @throws \RuntimeException        if download or processing fails
     */
    public function downloadAndStore(string $itemId, string $posterPath): array
    {
        if ($posterPath === '' || $posterPath === '/') {
            throw new \InvalidArgumentException('Poster path cannot be empty');
        }

        // Check if already cached - return early if all variants exist
        $existingVariants = $this->getStoredVariants($itemId);
        if ($existingVariants !== [] && count($existingVariants) >= count(self::WIDTHS) + 1) {
            return $existingVariants;
        }

        // Download original from TMDB
        $originalUrl = $this->buildTmdbUrl($posterPath, self::ORIGINAL);
        $tmpPath = $this->downloadToTemp($originalUrl);

        try {
            // Validate the downloaded image
            $this->validateImageFile($tmpPath);

            $this->ensureItemDirExists($itemId);

            $stored = [];
            foreach (self::WIDTHS as $width) {
                $variantPath = $this->generateVariant($itemId, $tmpPath, $width);
                if ($variantPath !== null) {
                    $stored[] = 'w' . $width;
                }
            }

            // Store original variant (copy of downloaded file)
            $originalStored = $this->storeOriginal($itemId, $tmpPath);
            if ($originalStored) {
                $stored[] = self::ORIGINAL;
            }

            return $stored;
        } finally {
            // Clean up temp file
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    /**
     * Get the path to a specific variant for an item.
     *
     * @param string $itemId Media item UUID
     * @param string $size   Size variant (e.g., 'w185', 'w342', 'w500', 'w780', 'original')
     * @return string|null  Full path to the variant or null if not found
     */
    public function variantPath(string $itemId, string $size): ?string
    {
        $path = $this->itemDir($itemId) . $size . '.jpg';
        return is_file($path) ? $path : null;
    }

    /**
     * Get all stored variant sizes for an item.
     *
     * @param string $itemId Media item UUID
     * @return string[]      Array of size names that are stored
     */
    public function getStoredVariants(string $itemId): array
    {
        $itemDir = $this->itemDir($itemId);
        if (!is_dir($itemDir)) {
            return [];
        }

        $variants = [];
        $files = scandir($itemDir);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (preg_match('/^(w\d+|original)\.jpg$/i', $file)) {
                $variants[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $variants;
    }

    /**
     * Check if artwork is cached for an item.
     *
     * @param string $itemId Media item UUID
     * @return bool True if at least one variant exists
     */
    public function hasArtwork(string $itemId): bool
    {
        return $this->getStoredVariants($itemId) !== [];
    }

    /**
     * Delete all artwork variants for an item.
     *
     * @param string $itemId Media item UUID
     */
    public function delete(string $itemId): void
    {
        $itemDir = $this->itemDir($itemId);
        if (!is_dir($itemDir)) {
            return;
        }

        $files = scandir($itemDir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $itemDir . $file;
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }

        rmdir($itemDir);
    }

    /**
     * Build a signed URL for an artwork variant.
     *
     * @param string      $itemId Media item UUID
     * @param string      $size   Size variant (e.g., 'w185', 'original')
     * @param string|null $path   Override path for URL signing
     * @return string|null        Signed URL or null if variant not found
     */
    public function url(string $itemId, string $size, ?string $path = null): ?string
    {
        if ($path === null && $this->variantPath($itemId, $size) === null) {
            return null;
        }

        $signer = SignedUrl::fromEnv();
        $resourcePath = '/api/v1/artwork/' . $itemId . '?size=' . $size;

        return $signer->mint($resourcePath);
    }

    /**
     * Build the local relative URL path for an artwork variant.
     *
     * Unlike {@see url()}, this returns the unsigned path that will be signed
     * by the controller for signed URL support.
     *
     * @param string $itemId Media item UUID
     * @param string $size   Size variant
     * @return string|null    Relative URL path or null if not found
     */
    public function relativePath(string $itemId, string $size): ?string
    {
        if ($this->variantPath($itemId, $size) === null) {
            return null;
        }

        return '/api/v1/artwork/' . $itemId . '?size=' . $size;
    }

    /**
     * Build a srcset string for responsive images.
     *
     * @param string $itemId Media item UUID
     * @return string|null   srcset string or null if no variants exist
     */
    public function srcset(string $itemId): ?string
    {
        $variants = $this->getStoredVariants($itemId);
        if ($variants === []) {
            return null;
        }

        $candidates = [];
        foreach ($variants as $size) {
            $path = $this->relativePath($itemId, $size);
            if ($path === null) {
                continue;
            }
            // Extract width from size string (e.g., 'w185' -> 185).
            $width = (int) preg_replace('/[^0-9]/', '', $size);
            // The 'original' variant has no digits, so this evaluates to 0.
            // A `0w` width descriptor is a parse error per the HTML srcset spec —
            // conformant browsers DROP that candidate — so skip it here rather
            // than emit an invalid `…?size=original 0w` entry. 'original' remains
            // separately reachable via relativePath()/url() and the signed
            // poster_url; it just carries no meaningful `w`-descriptor width.
            if ($width < 1) {
                continue;
            }
            $candidates[] = $path . ' ' . $width . 'w';
        }

        return implode(', ', $candidates);
    }

    /**
     * Build a TMDB image URL for a given poster path and size.
     */
    private function buildTmdbUrl(string $posterPath, string $size): string
    {
        // Remove leading slash if present
        $cleanPath = ltrim($posterPath, '/');
        return self::TMDB_IMAGE_BASE . '/' . $size . '/' . $cleanPath;
    }

    /**
     * Download a file from URL to a temp location.
     *
     * SV-3.4: this class is reachable from an interactive HTTP handler
     * (`MediaMatchController::apply()` → `LibraryMetadataMatcher::applyMatch()`
     * → … → `downloadAndStore()`), so the network fetch must never block the
     * resident Workerman worker. When running inside a Workerman worker AND a
     * live Swoole coroutine (and the URL is not a Swoole-event-loop-TLS-stall
     * case) we use the non-blocking {@see Client} + coroutine-Channel
     * cooperative wait — the same pattern as {@see \Phlix\Media\Metadata\MetadataHttpClient}.
     * Otherwise (CLI scans, PHPUnit, or the https-under-Swoole TLS stall) we
     * fall back to blocking cURL, which is deliberately excluded from the
     * curated coroutine hook mask so it runs as a plain blocking call.
     *
     * The decision is delegated to {@see shouldUseBlockingDownload()} via the
     * shared {@see WorkerContext} helper (introduced by SV-0.3/SV-0.4) so we do
     * not hand-roll a fresh context check.
     *
     * @param string $url Full URL to download
     * @return string     Path to temp file
     * @throws \RuntimeException if download fails
     */
    private function downloadToTemp(string $url): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'artwork_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temp file for artwork download');
        }

        // CURLOPT_URL / the async client both require a non-empty URL.
        assert($url !== '');

        if ($this->shouldUseBlockingDownload($url)) {
            return $this->downloadToTempBlocking($url, $tmpFile);
        }

        return $this->downloadToTempAsync($url, $tmpFile);
    }

    /**
     * Decide whether the blocking cURL download path must be used.
     *
     * Blocking is required when there is no running Workerman event loop
     * (CLI/scan/PHPUnit), when we are not inside a live Swoole coroutine (a
     * `Swoole\Coroutine\Channel` may only be used there — see
     * {@see WorkerContext::inCoroutine()}), or when the URL is an https request
     * that would stall under the Swoole event loop's TLS reads
     * ({@see EventLoopTls::requiresBlockingCurl()}).
     *
     * Protected so a unit test can force the async path without a live worker.
     *
     * @param string $url Absolute request URL.
     */
    protected function shouldUseBlockingDownload(string $url): bool
    {
        return !WorkerContext::isEventLoopRunning()
            || !WorkerContext::inCoroutine()
            || EventLoopTls::requiresBlockingCurl($url);
    }

    /**
     * Lazily construct the non-blocking Workerman HTTP client.
     *
     * Protected so a unit test can substitute a fake client that resolves the
     * success/error callbacks synchronously (no network).
     */
    protected function getAsyncClient(): Client
    {
        if ($this->asyncClient === null) {
            $this->asyncClient = new Client([
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
            ]);
        }

        return $this->asyncClient;
    }

    /**
     * Download via the non-blocking async client, waiting cooperatively on a
     * Swoole coroutine Channel (yields to the event loop, never busy-spins).
     *
     * @param non-empty-string $url     Full URL to download.
     * @param string           $tmpFile Pre-created temp file to write the body into.
     * @return string          Path to the temp file on success.
     * @throws \RuntimeException on timeout, transport error, non-200, or write failure.
     */
    private function downloadToTempAsync(string $url, string $tmpFile): string
    {
        $channel = new \Swoole\Coroutine\Channel(1);

        /** @var array{response: ResponseInterface|null, error: \Throwable|null} $state */
        $state = [
            'response' => null,
            'error' => null,
        ];

        $this->getAsyncClient()->request($url, [
            'method' => 'GET',
            'success' => function (ResponseInterface $response) use (&$state, $channel): void {
                $state['response'] = $response;
                $channel->push(true);
            },
            'error' => function (\Throwable $error) use (&$state, $channel): void {
                $state['error'] = $error;
                $channel->push(true);
            },
        ]);

        // Yield to the event loop until a callback pushes or the timeout fires.
        // A false return here is a timeout: state stays empty and we fail below.
        $channel->pop((float) self::DOWNLOAD_TIMEOUT);

        $response = $state['response'];

        if ($state['error'] !== null || !$response instanceof ResponseInterface) {
            $this->cleanupTemp($tmpFile);
            throw new \RuntimeException(sprintf(
                'Failed to download artwork from TMDB (async): %s',
                $state['error'] instanceof \Throwable ? $state['error']->getMessage() : 'timeout',
            ));
        }

        $httpCode = $response->getStatusCode();
        if ($httpCode !== 200) {
            $this->cleanupTemp($tmpFile);
            throw new \RuntimeException(sprintf(
                'Failed to download artwork from TMDB: HTTP %d - %s',
                $httpCode,
                'async request',
            ));
        }

        $body = (string) $response->getBody();
        if ($body === '' || file_put_contents($tmpFile, $body) === false) {
            $this->cleanupTemp($tmpFile);
            throw new \RuntimeException('Failed to write downloaded artwork to temp file');
        }

        return $tmpFile;
    }

    /**
     * Blocking cURL download into the pre-created temp file. Used in CLI/scan/
     * test contexts and for the https-under-Swoole TLS-stall case. cURL is
     * excluded from the coroutine hook mask, so this is a plain blocking call.
     *
     * @param non-empty-string $url     Full URL to download.
     * @param string           $tmpFile Pre-created temp file to write the body into.
     * @return string          Path to the temp file on success.
     * @throws \RuntimeException on init/open/transport error or non-200.
     */
    private function downloadToTempBlocking(string $url, string $tmpFile): string
    {
        // Use cURL for reliable download with timeout
        $ch = curl_init();
        if ($ch === false) {
            $this->cleanupTemp($tmpFile);
            throw new \RuntimeException('Failed to initialize cURL');
        }

        $fp = fopen($tmpFile, 'wb');
        if ($fp === false) {
            curl_close($ch);
            $this->cleanupTemp($tmpFile);
            throw new \RuntimeException('Failed to open temp file for artwork download');
        }

        // Use individual curl_setopt calls to avoid PHPStan array type strictness.
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::DOWNLOAD_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FILE, $fp);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode !== 200) {
            $this->cleanupTemp($tmpFile);
            throw new \RuntimeException(
                sprintf('Failed to download artwork from TMDB: HTTP %d - %s', $httpCode, $error ?: 'Unknown error'),
            );
        }

        return $tmpFile;
    }

    /**
     * Best-effort removal of a temp file after a failed download.
     */
    private function cleanupTemp(string $tmpFile): void
    {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    /**
     * Validate an image file meets security requirements.
     *
     * @param string $tmpPath Path to the image file
     * @throws \InvalidArgumentException if validation fails
     */
    private function validateImageFile(string $tmpPath): void
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
     * Get the storage directory for a specific item.
     */
    private function itemDir(string $itemId): string
    {
        // Sanitize item ID to prevent path traversal
        $sanitizedId = preg_replace('/[^a-zA-Z0-9\-]/', '', $itemId);
        if ($sanitizedId === '' || $sanitizedId !== $itemId) {
            throw new \InvalidArgumentException('Invalid item ID for artwork storage');
        }

        return $this->storageDir . $sanitizedId . '/';
    }

    /**
     * Ensure the storage directory for an item exists.
     */
    private function ensureItemDirExists(string $itemId): void
    {
        $dir = $this->itemDir($itemId);
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
     * Generate a resized variant of an image.
     *
     * @param string $itemId   Media item UUID
     * @param string $tmpPath Path to source image
     * @param int    $width    Target width
     * @return string|null    Path to stored variant or null on failure
     */
    private function generateVariant(string $itemId, string $tmpPath, int $width): ?string
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
            return $this->storeVariant($itemId, $tmpPath, 'w' . $width, $sourceType);
        }

        $source = $this->createImageFromType($tmpPath, $sourceType);
        if ($source === false) {
            return null;
        }

        // Guard against invalid computed dimensions (PHPStan doesn't track the math)
        if ($width < 1 || $height < 1) {
            imagedestroy($source);
            return null;
        }

        $canvas = @imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            imagedestroy($source);
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
            imagedestroy($source);
            imagedestroy($canvas);
            return null;
        }

        // Capture JPEG
        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpegData = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if ($jpegData === false || $jpegData === '') {
            return null;
        }

        $variantFile = $this->itemDir($itemId) . 'w' . $width . '.jpg';
        if (!$this->atomicWriteVariant($variantFile, $jpegData)) {
            return null;
        }

        return $variantFile;
    }

    /**
     * Store the original (full-size) variant.
     */
    private function storeOriginal(string $itemId, string $tmpPath): bool
    {
        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return false;
        }

        /** @var int */
        $sourceType = $imageInfo[2];
        return $this->storeVariant($itemId, $tmpPath, self::ORIGINAL, $sourceType) !== null;
    }

    /**
     * Store a variant from a source file, re-encoding as JPEG.
     */
    private function storeVariant(string $itemId, string $tmpPath, string $sizeName, int $sourceType): ?string
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
            imagedestroy($source);
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

        imagedestroy($source);
        imagedestroy($canvas);

        if ($jpegData === false || $jpegData === '') {
            return null;
        }

        $variantFile = $this->itemDir($itemId) . $sizeName . '.jpg';
        if (!$this->atomicWriteVariant($variantFile, $jpegData)) {
            return null;
        }

        return $variantFile;
    }

    /**
     * Atomically write JPEG bytes to a variant file.
     *
     * Writes to a uniquely-named sibling temp file in the SAME directory (so the
     * final {@see rename()} is atomic on a single filesystem) and then renames it
     * onto the final path. `serveArtwork` streams these files via `withFile()`,
     * and {@see downloadAndStore()} only early-returns when ALL variants already
     * exist — so a retry after a partial prior failure would otherwise do an
     * in-place `file_put_contents()` overwrite, exposing a truncated/0-byte JPEG
     * (and a mid-write ETag/Last-Modified) to a concurrent reader. The temp file
     * is removed on any write or rename failure so no orphaned `.tmp` files leak.
     *
     * Mirrors {@see \Phlix\Media\Storage\AvatarStorage::store()}'s temp-then-rename idiom.
     *
     * Protected so a unit test can exercise the atomic path (and its failure
     * cleanup) without a live download.
     *
     * @param string $variantFile Absolute final path (e.g. '…/w185.jpg').
     * @param string $jpegData    Encoded JPEG bytes to write.
     * @return bool               True on success, false on any I/O failure.
     */
    protected function atomicWriteVariant(string $variantFile, string $jpegData): bool
    {
        // Unique temp name in the SAME directory: keeps rename() atomic
        // (same filesystem) and collision-safe if two coroutines/processes
        // regenerate the same item concurrently. PID + random bytes.
        $pid = getmypid();
        $tmpFile = $variantFile . '.' . ($pid !== false ? $pid : 0) . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $written = file_put_contents($tmpFile, $jpegData);
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
        finfo_close($finfo);

        if ($raw === false) {
            return null;
        }

        return $raw;
    }
}
