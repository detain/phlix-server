<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Media\Metadata\ExifProvider;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * PhotoController handles photo library API endpoints.
 *
 * Provides endpoints for browsing photo albums, viewing individual photos,
 * generating thumbnails, and slideshow functionality.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description REST API for photo library browsing and slideshow
 * @since 0.16.0
 */
class PhotoController
{
    /**
     * Constructor for PhotoController.
     *
     * @param ItemRepository $itemRepo Repository for media item access
     * @param PhotoLibraryManager $photoManager Photo library manager
     * @param ExifProvider $exifProvider EXIF metadata provider
     */
    public function __construct(
        private readonly ItemRepository $itemRepo,
        private readonly PhotoLibraryManager $photoManager,
        private readonly ExifProvider $exifProvider
    ) {
    }

    /**
     * Lists all photo albums.
     *
     * Albums are grouped by date taken (year-month).
     *
     * GET /photo/albums
     *
     * @param Request $request The HTTP request
     * @return Response JSON response with albums array
     *
     * @since 0.16.0
     */
    public function listAlbums(Request $request): Response
    {
        /** @var string|null */
        $libraryId = $request->query['library_id'] ?? null;

        if ($libraryId === null) {
            return (new Response())->status(400)->json([
                'error' => 'library_id is required',
            ]);
        }

        $grouped = $this->photoManager->getPhotosGroupedByDate($libraryId);

        $albums = [];
        foreach ($grouped as $dateKey => $photos) {
            $firstPhoto = $photos[0];
            $albums[] = [
                'id' => md5($dateKey),
                'date' => $dateKey,
                'photo_count' => count($photos),
                'cover_photo' => $firstPhoto,
                'photos' => $photos,
            ];
        }

        // Sort by date descending (most recent first)
        usort($albums, fn($a, $b) => strcmp($b['date'], $a['date']));

        return (new Response())->json([
            'albums' => $albums,
        ]);
    }

    /**
     * Gets a single album by ID.
     *
     * GET /photo/albums/{id}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with album data
     *
     * @since 0.16.0
     */
    public function getAlbum(Request $request, array $params): Response
    {
        $albumId = $params['id'] ?? null;
        /** @var string|null */
        $libraryId = $request->query['library_id'] ?? null;

        if ($albumId === null || $libraryId === null) {
            return (new Response())->status(400)->json([
                'error' => 'album id and library_id are required',
            ]);
        }

        $grouped = $this->photoManager->getPhotosGroupedByDate($libraryId);

        // Find the album by date hash
        $targetDate = null;
        foreach ($grouped as $dateKey => $photos) {
            if (md5($dateKey) === $albumId) {
                $targetDate = $dateKey;
                break;
            }
        }

        if ($targetDate === null) {
            return (new Response())->status(404)->json([
                'error' => 'Album not found',
            ]);
        }

        return (new Response())->json([
            'album' => [
                'id' => $albumId,
                'date' => $targetDate,
                'photo_count' => count($grouped[$targetDate]),
                'photos' => $grouped[$targetDate],
            ],
        ]);
    }

    /**
     * Lists all photos with optional filtering.
     *
     * GET /photo/photos
     *
     * Query params:
     *   - library_id: Required - the library to list from
     *   - limit: Maximum number of results (default 100)
     *   - offset: Pagination offset (default 0)
     *
     * @param Request $request The HTTP request
     * @return Response JSON response with photos array
     *
     * @since 0.16.0
     */
    public function listPhotos(Request $request): Response
    {
        /** @var string|null */
        $libraryId = $request->query['library_id'] ?? null;

        if ($libraryId === null) {
            return (new Response())->status(400)->json([
                'error' => 'library_id is required',
            ]);
        }

        /** @var mixed */
        $limitVal = $request->query['limit'] ?? 100;
        /** @var int */
        $limit = min(1000, max(1, is_numeric($limitVal) ? (int)$limitVal : 100));
        /** @var mixed */
        $offsetVal = $request->query['offset'] ?? 0;
        /** @var int */
        $offset = max(0, is_numeric($offsetVal) ? (int)$offsetVal : 0);

        $items = $this->itemRepo->getByLibrary($libraryId, $limit, $offset);
        $photos = array_filter($items, fn($item) => $item['type'] === 'photo');

        return (new Response())->json([
            'photos' => array_values($photos),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($photos),
            ],
        ]);
    }

    /**
     * Gets a single photo by ID with full EXIF data.
     *
     * GET /photo/photos/{id}
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response JSON response with photo data and EXIF
     *
     * @since 0.16.0
     */
    public function getPhoto(Request $request, array $params): Response
    {
        $photoId = $params['id'] ?? null;

        if ($photoId === null) {
            return (new Response())->status(400)->json([
                'error' => 'photo id is required',
            ]);
        }

        $item = $this->itemRepo->findById($photoId);

        if ($item === null || $item['type'] !== 'photo') {
            return (new Response())->status(404)->json([
                'error' => 'Photo not found',
            ]);
        }

        // Get EXIF metadata
        $exif = $this->exifProvider->getPhotoMetadata($photoId);

        return (new Response())->json([
            'photo' => [
                'id' => $item['id'],
                'name' => $item['name'],
                'path' => $item['path'],
                'metadata' => $exif,
                'exif' => $exif,
            ],
        ]);
    }

    /**
     * Generates and serves a thumbnail for a photo.
     *
     * GET /photo/photos/{id}/thumbnail?w=300&h=300&fit=cover
     *
     * Query params:
     *   - w: Target width (default 300)
     *   - h: Target height (default 300)
     *   - fit: Fit mode - 'cover' or 'contain' (default 'cover')
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response Image response with thumbnail
     *
     * @since 0.16.0
     */
    public function getThumbnail(Request $request, array $params): Response
    {
        $photoId = $params['id'] ?? null;

        if ($photoId === null) {
            return (new Response())->status(400)->json([
                'error' => 'photo id is required',
            ]);
        }

        $item = $this->itemRepo->findById($photoId);

        if ($item === null || $item['type'] !== 'photo') {
            return (new Response())->status(404)->json([
                'error' => 'Photo not found',
            ]);
        }

        /** @var mixed */
        $widthVal = $request->query['w'] ?? 300;
        /** @var int */
        $width = max(1, min(2000, is_numeric($widthVal) ? (int)$widthVal : 300));
        /** @var mixed */
        $heightVal = $request->query['h'] ?? 300;
        /** @var int */
        $height = max(1, min(2000, is_numeric($heightVal) ? (int)$heightVal : 300));
        /** @var mixed */
        $fitVal = $request->query['fit'] ?? 'cover';
        /** @var string */
        $fit = is_string($fitVal) ? $fitVal : 'cover';

        /** @var string */
        $path = $item['path'];

        if (!file_exists($path)) {
            return (new Response())->status(404)->json([
                'error' => 'Photo file not found',
            ]);
        }

        // Generate thumbnail using GD
        $thumbnail = $this->generateThumbnail($path, $width, $height, $fit);

        if ($thumbnail === null) {
            return (new Response())->status(500)->json([
                'error' => 'Failed to generate thumbnail',
            ]);
        }

        // Get original image type
        $imageInfo = @getimagesize($path);
        $mimeType = $imageInfo !== false ? $imageInfo['mime'] : 'image/jpeg';

        return (new Response())
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'public, max-age=86400')
            ->header('Content-Length', (string)strlen($thumbnail))
            ->text(base64_encode($thumbnail));
    }

    /**
     * Gets the full-resolution photo image.
     *
     * GET /photo/photos/{id}/full
     *
     * Serves the full-size photo file directly with proper Content-Type.
     * Supports Range requests for seeking.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Route parameters including 'id'
     * @return Response Image response with full photo or error
     *
     * @since 0.16.0
     */
    public function getFull(Request $request, array $params): Response
    {
        $photoId = $params['id'] ?? null;

        if ($photoId === null) {
            return (new Response())->status(400)->json([
                'error' => 'photo id is required',
            ]);
        }

        $item = $this->itemRepo->findById($photoId);

        if ($item === null || $item['type'] !== 'photo') {
            return (new Response())->status(404)->json([
                'error' => 'Photo not found',
            ]);
        }

        /** @var string */
        $path = $item['path'];

        if (!file_exists($path)) {
            return (new Response())->status(404)->json([
                'error' => 'Photo file not found',
            ]);
        }

        $fileSize = filesize($path);
        if ($fileSize === false) {
            return (new Response())->status(500)->json([
                'error' => 'Failed to read photo file',
            ]);
        }

        // Determine MIME type
        $imageInfo = @getimagesize($path);
        $mimeType = $imageInfo !== false ? $imageInfo['mime'] : 'image/jpeg';

        // Handle Range requests for seeking.
        // Read via getHeader() (case-insensitive) rather than the raw
        // $request->headers['Range'] array access: parseHeaders() stores
        // header keys upper-cased (e.g. "RANGE"), so a mixed-case lookup
        // never matched and range requests silently fell through to 200.
        $rangeHeader = $request->getHeader('Range');
        $start = 0;
        $end = $fileSize - 1;

        if ($rangeHeader !== null) {
            // Parse Range header (e.g., "bytes=1024-2048")
            if (preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
                $start = (int) $matches[1];
                $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

                if ($start > $end || $start >= $fileSize) {
                    return (new Response())
                        ->status(416)
                        ->header('Content-Range', "bytes */{$fileSize}")
                        ->json(['error' => 'Range not satisfiable']);
                }
            }
        }

        // Open file and seek to start position
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to open file']);
        }

        if (fseek($handle, $start) === -1) {
            fclose($handle);
            return (new Response())->status(500)->json(['error' => 'Failed to seek file']);
        }

        $length = $end - $start + 1;
        if ($length < 1) {
            fclose($handle);
            return (new Response())->status(416)->json(['error' => 'Invalid range length']);
        }
        /** @var positive-int $length */
        $content = fread($handle, $length);
        fclose($handle);

        if ($content === false) {
            return (new Response())->status(500)->json(['error' => 'Failed to read file']);
        }

        $response = (new Response())
            ->status($rangeHeader !== null ? 206 : 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Length', (string)strlen($content))
            ->header('Accept-Ranges', 'bytes');

        if ($rangeHeader !== null) {
            $response->header('Content-Range', "bytes {$start}-{$end}/{$fileSize}");
        }

        return $response->body($content);
    }

    /**
     * Gets photos for slideshow presentation.
     *
     * GET /photo/slideshow?album_id=xxx&interval=5
     *
     * Query params:
     *   - album_id: Album ID to slideshow (optional, shows all if not provided)
     *   - library_id: Required - the library
     *   - interval: Seconds between slides (default 5)
     *
     * Returns array of {id, url, thumbnail_url, caption} for JS rotation.
     *
     * @param Request $request The HTTP request
     * @return Response JSON response with slideshow data
     *
     * @since 0.16.0
     */
    public function slideshow(Request $request): Response
    {
        /** @var string|null */
        $libraryId = $request->query['library_id'] ?? null;
        /** @var string|null */
        $albumId = $request->query['album_id'] ?? null;
        /** @var mixed */
        $intervalVal = $request->query['interval'] ?? 5;
        /** @var int */
        $interval = max(1, min(300, is_numeric($intervalVal) ? (int)$intervalVal : 5));

        if ($libraryId === null) {
            return (new Response())->status(400)->json([
                'error' => 'library_id is required',
            ]);
        }

        $photos = [];

        if ($albumId !== null) {
            // Get specific album
            $grouped = $this->photoManager->getPhotosGroupedByDate($libraryId);
            foreach ($grouped as $dateKey => $groupPhotos) {
                if (md5($dateKey) === $albumId) {
                    $photos = $groupPhotos;
                    break;
                }
            }
        } else {
            // Get all photos
            $items = $this->itemRepo->getByLibrary($libraryId, 10000, 0);
            $photos = array_filter($items, fn($item) => $item['type'] === 'photo');
            $photos = array_values($photos);
        }

        $slideshow = array_map(function (array $photo) use ($interval): array {
            /** @var array<string, mixed> */
            $exif = $photo['metadata'] ?? [];
            /** @var string */
            $caption = ($exif['camera_model'] ?? $photo['name']) ?? '';

            if (isset($exif['date_taken_unix']) && is_numeric($exif['date_taken_unix'])) {
                /** @var int */
                $timestamp = (int)$exif['date_taken_unix'];
                $caption .= ' - ' . date('Y-m-d H:i', $timestamp);
            }

            /** @var string */
            $photoId = $photo['id'];

            return [
                'id' => $photoId,
                'url' => '/photo/photos/' . $photoId . '/full',
                'thumbnail_url' => '/photo/photos/' . $photoId . '/thumbnail?w=400&h=400&fit=cover',
                'caption' => $caption,
                'interval' => $interval,
            ];
        }, $photos);

        return (new Response())->json([
            'slideshow' => $slideshow,
            'interval' => $interval,
        ]);
    }

    /**
     * Generates a thumbnail image.
     *
     * @param string $path Original image path
     * @param int $width Target width (1-2000)
     * @param int $height Target height (1-2000)
     * @param string $fit Fit mode ('cover' or 'contain')
     * @return string|null Base64-encoded thumbnail or null on failure
     *
     * @since 0.16.0
     */
    private function generateThumbnail(string $path, int $width, int $height, string $fit): ?string
    {
        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            return null;
        }

        /** @var int */
        $sourceWidth = $imageInfo[0];
        /** @var int */
        $sourceHeight = $imageInfo[1];
        /** @var int */
        $sourceType = $imageInfo[2];

        // Create source image resource
        $source = $this->createImageFromType($path, $sourceType);
        if ($source === false) {
            return null;
        }

        // Calculate dimensions
        $ratio = $fit === 'cover'
            ? max($width / $sourceWidth, $height / $sourceHeight)
            : min($width / $sourceWidth, $height / $sourceHeight);
        $newWidth = (int)($sourceWidth * $ratio);
        $newHeight = (int)($sourceHeight * $ratio);
        $srcX = $fit === 'cover' ? max(0, (int)(($newWidth - $width) / 2)) : 0;
        $srcY = $fit === 'cover' ? max(0, (int)(($newHeight - $height) / 2)) : 0;

        // Create thumbnail
        /** @var positive-int */
        $thumbWidth = max(1, $width);
        /** @var positive-int */
        $thumbHeight = max(1, $height);
        $thumb = @imagecreatetruecolor($thumbWidth, $thumbHeight);
        if ($thumb === false) {
            imagedestroy($source);
            return null;
        }

        // Preserve transparency for PNG
        if ($sourceType === IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        $resampleResult = imagecopyresampled(
            $thumb,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            $width,
            $height,
            $newWidth,
            $newHeight
        );

        if ($resampleResult === false) {
            imagedestroy($source);
            imagedestroy($thumb);
            return null;
        }

        // Capture output
        ob_start();
        imagejpeg($thumb, null, 85);
        $data = ob_get_clean();

        imagedestroy($source);
        imagedestroy($thumb);

        return $data !== false ? $data : null;
    }

    /**
     * Creates an image resource from file path and type.
     *
     * @param string $path Image file path
     * @param int $type Image type constant
     * @return \GdImage|false Image resource or false on failure
     *
     * @since 0.16.0
     */
    private function createImageFromType(string $path, int $type): \GdImage|false
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => imagecreatefromjpeg($path),
        };
    }
}
