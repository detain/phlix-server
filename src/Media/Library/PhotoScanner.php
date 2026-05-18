<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Workerman\MySQL\Connection;

/**
 * PhotoScanner discovers and indexes photo files with EXIF metadata extraction.
 *
 * This class extends MediaScanner to handle image files (JPEG, PNG, TIFF, WebP, HEIC),
 * extracting EXIF metadata including camera info, lens settings, GPS coordinates,
 * and image dimensions. Uses PHP's built-in exif_read_data() for JPEG files.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Filesystem scanner for discovering and indexing photo files with EXIF extraction
 * @see MediaScanner For base scanning functionality
 * @since 0.16.0
 */
class PhotoScanner extends MediaScanner
{
    /** @var array<string> Supported image extensions */
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'webp', 'heic', 'heif'];

    /** @var array<string> Supported EXIF-enabled formats */
    private const EXIF_FORMATS = ['jpg', 'jpeg'];

    /**
     * Constructor for PhotoScanner.
     *
     * @param Connection $db Database connection for media item persistence
     * @param ItemRepository $itemRepository Repository for media item operations
     * @param StructuredLogger|null $logger Optional logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional event dispatcher
     */
    public function __construct(
        Connection $db,
        ItemRepository $itemRepository,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        parent::__construct($db, $itemRepository, $logger, $eventDispatcher);
    }

    /**
     * Extracts EXIF data from a JPEG photo file.
     *
     * Returns a structured array with all documented EXIF fields. Returns
     * an empty array on failure or for non-JPEG formats without EXIF support.
     *
     * @param string $path Absolute filesystem path to the photo
     * @return array<string, mixed> EXIF data array with keys:
     *   - camera_make: string|null
     *   - camera_model: string|null
     *   - lens: string|null
     *   - aperture: string|null
     *   - iso: int|null
     *   - shutter_speed: string|null
     *   - focal_length: string|null
     *   - width: int|null
     *   - height: int|null
     *   - orientation: int|null
     *   - orientation_name: string|null
     *   - date_taken_unix: int|null
     *   - gps_lat: float|null
     *   - gps_lng: float|null
     *   - gps_alt: float|null
     *
     * @since 0.16.0
     */
    public function harvestExif(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Only JPEG supports embedded EXIF
        if (!in_array($extension, self::EXIF_FORMATS, true)) {
            return $this->harvestBasicImageMetadata($path);
        }

        // Read EXIF data using PHP's built-in function
        $exif = @exif_read_data($path, 'EXIF', true);

        if ($exif === false) {
            return $this->harvestBasicImageMetadata($path);
        }

        $data = [];

        // Camera info
        $data['camera_make'] = $exif['IFD0']['Make'] ?? null;
        $data['camera_model'] = $exif['IFD0']['Model'] ?? null;

        // Lens info (some cameras store in LensModel tag)
        $data['lens'] = $exif['EXIF']['LensModel'] ?? null;

        // Exposure settings
        $data['aperture'] = isset($exif['EXIF']['FNumber']) && $exif['EXIF']['FNumber'] !== ''
            ? 'f/' . $exif['EXIF']['FNumber']
            : null;

        $data['iso'] = isset($exif['EXIF']['ISOSpeedRatings']) && is_numeric($exif['EXIF']['ISOSpeedRatings'])
            ? (int)$exif['EXIF']['ISOSpeedRatings']
            : null;

        $data['shutter_speed'] = $exif['EXIF']['ExposureTime'] ?? null;

        // Focal length
        $data['focal_length'] = isset($exif['EXIF']['FocalLength']) && $exif['EXIF']['FocalLength'] !== ''
            ? (is_numeric($exif['EXIF']['FocalLength'])
                ? $exif['EXIF']['FocalLength'] . 'mm'
                : $exif['EXIF']['FocalLength'])
            : null;

        // Image dimensions
        $data['width'] = isset($exif['EXIF']['ExifImageWidth']) && is_numeric($exif['EXIF']['ExifImageWidth'])
            ? (int)$exif['EXIF']['ExifImageWidth']
            : (isset($exif['FILE']['FileSize']) && isset($exif['COMPUTED']['Height'])
                ? (int)$exif['COMPUTED']['Height']
                : null);

        // Fallback dimension reading from FILE
        if ($data['width'] === null) {
            $data['width'] = isset($exif['COMPUTED']['Width']) && is_numeric($exif['COMPUTED']['Width'])
                ? (int)$exif['COMPUTED']['Width']
                : null;
        }

        $data['height'] = isset($exif['EXIF']['ExifImageLength']) && is_numeric($exif['EXIF']['ExifImageLength'])
            ? (int)$exif['EXIF']['ExifImageLength']
            : (isset($exif['COMPUTED']['Height']) && is_numeric($exif['COMPUTED']['Height'])
                ? (int)$exif['COMPUTED']['Height']
                : null);

        if ($data['height'] === null && $data['width'] !== null) {
            // Try to get from EXIF ImageWidth
            $data['height'] = isset($exif['EXIF']['ExifImageWidth']) && is_numeric($exif['EXIF']['ExifImageWidth'])
                ? (int)$exif['EXIF']['ExifImageWidth']
                : null;
        }

        // Orientation
        $data['orientation'] = isset($exif['IFD0']['Orientation']) && is_numeric($exif['IFD0']['Orientation'])
            ? (int)$exif['IFD0']['Orientation']
            : null;
        $data['orientation_name'] = $this->getOrientationName($data['orientation']);

        // Date taken
        $data['date_taken_unix'] = $this->parseExifDate($exif['EXIF']['DateTimeOriginal'] ?? null);

        // GPS coordinates
        $gps = $this->parseGpsCoordinates(
            $exif['GPS'] ?? [],
            $exif['GPSLatitude'] ?? null,
            $exif['GPSLatitudeRef'] ?? null,
            $exif['GPSLongitude'] ?? null,
            $exif['GPSLongitudeRef'] ?? null
        );
        $data['gps_lat'] = $gps['lat'];
        $data['gps_lng'] = $gps['lng'];
        $data['gps_alt'] = isset($exif['GPS']['GPSAltitude']) && is_numeric($exif['GPS']['GPSAltitude'])
            ? (float)$exif['GPS']['GPSAltitude']
            : null;

        return $data;
    }

    /**
     * Harvests basic image metadata from file without EXIF.
     *
     * @param string $path Absolute filesystem path
     * @return array<string, mixed> Basic metadata including dimensions
     *
     * @since 0.16.0
     */
    private function harvestBasicImageMetadata(string $path): array
    {
        $data = [
            'camera_make' => null,
            'camera_model' => null,
            'lens' => null,
            'aperture' => null,
            'iso' => null,
            'shutter_speed' => null,
            'focal_length' => null,
            'width' => null,
            'height' => null,
            'orientation' => null,
            'orientation_name' => 'Normal',
            'date_taken_unix' => null,
            'gps_lat' => null,
            'gps_lng' => null,
            'gps_alt' => null,
        ];

        // Try to get dimensions using GD if available
        if (function_exists('getimagesize')) {
            $size = @getimagesize($path);
            if ($size !== false) {
                $data['width'] = $size[0];
                $data['height'] = $size[1];
            }
        }

        return $data;
    }

    /**
     * Converts numeric orientation to human-readable name.
     *
     * @param int|null $orientation Numeric orientation (1-8)
     * @return string Orientation name
     *
     * @since 0.16.0
     */
    private function getOrientationName(?int $orientation): string
    {
        return match ($orientation) {
            1 => 'Normal',
            2 => 'Mirror Horizontal',
            3 => 'Rotate 180',
            4 => 'Mirror Vertical',
            5 => 'Mirror Horizontal and Rotate 270',
            6 => 'Rotate 90',
            7 => 'Mirror Horizontal and Rotate 90',
            8 => 'Rotate 270',
            default => 'Normal',
        };
    }

    /**
     * Parses EXIF date string to Unix timestamp.
     *
     * EXIF dates are in format "YYYY:MM:DD HH:MM:SS"
     *
     * @param string|null $dateString EXIF date string
     * @return int|null Unix timestamp or null if parsing fails
     *
     * @since 0.16.0
     */
    private function parseExifDate(?string $dateString): ?int
    {
        if ($dateString === null || $dateString === '') {
            return null;
        }

        // EXIF format: "2024:01:15 14:30:00"
        $timestamp = strtotime(str_replace(':', '-', substr($dateString, 0, 10)) . ' ' . substr($dateString, 11));

        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * Parses GPS coordinates from EXIF GPS section.
     *
     * @param array<string, mixed> $gps GPS section of EXIF data
     * @param mixed $lat Latitude values
     * @param mixed $latRef Latitude reference (N/S)
     * @param mixed $lng Longitude values
     * @param mixed $lngRef Longitude reference (E/W)
     * @return array{lat: float|null, lng: float|null} Parsed coordinates
     *
     * @since 0.16.0
     */
    private function parseGpsCoordinates(array $gps, mixed $lat, mixed $latRef, mixed $lng, mixed $lngRef): array
    {
        $result = ['lat' => null, 'lng' => null];

        if (
            !is_array($lat) || count($lat) < 3
            || !is_numeric($lat[0]) || !is_numeric($lat[1]) || !is_numeric($lat[2])
        ) {
            // Try to get from GPS section directly if available
            if (isset($gps['GPSLatitude']) && is_array($gps['GPSLatitude'])) {
                $lat = $gps['GPSLatitude'];
            }
            if (isset($gps['GPSLongitude']) && is_array($gps['GPSLongitude'])) {
                $lng = $gps['GPSLongitude'];
            }
            if (isset($gps['GPSLatitudeRef'])) {
                $latRef = $gps['GPSLatitudeRef'];
            }
            if (isset($gps['GPSLongitudeRef'])) {
                $lngRef = $gps['GPSLongitudeRef'];
            }
        }

        if (!is_array($lat) || count($lat) < 3) {
            return $result;
        }

        // Convert rational numbers to decimal
        $latDecimal = $this->convertGpsRational($lat);
        $lngDecimal = $this->convertGpsRational($lng);

        // Apply references (N/S and E/W)
        if ($latRef === 'S' || $latRef === 's') {
            $latDecimal = -$latDecimal;
        }
        if ($lngRef === 'W' || $lngRef === 'w') {
            $lngDecimal = -$lngDecimal;
        }

        $result['lat'] = $latDecimal;
        $result['lng'] = $lngDecimal;

        return $result;
    }

    /**
     * Converts GPS rational coordinates to decimal degrees.
     *
     * @param mixed $rational Array of [degrees, minutes, seconds] as rationals
     * @return float Decimal degrees
     *
     * @since 0.16.0
     */
    private function convertGpsRational(mixed $rational): float
    {
        if (!is_array($rational) || count($rational) < 3) {
            return 0.0;
        }

        $degrees = $this->rationalToFloat($rational[0]);
        $minutes = $this->rationalToFloat($rational[1]);
        $seconds = $this->rationalToFloat($rational[2]);

        return $degrees + ($minutes / 60) + ($seconds / 3600);
    }

    /**
     * Converts a rational number (possibly string "num/denom") to float.
     *
     * @param mixed $value Rational value
     * @return float Float value
     *
     * @since 0.16.0
     */
    private function rationalToFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        if (is_string($value) && str_contains($value, '/')) {
            [$num, $denom] = explode('/', $value);
            return $denom != 0 ? (float)$num / (float)$denom : 0.0;
        }

        return 0.0;
    }

    /**
     * Checks if an extension represents a supported photo format.
     *
     * @param string $extension File extension (without dot)
     * @return bool True if the extension is a supported photo format
     *
     * @since 0.16.0
     */
    public function isPhotoExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Scans a photo library and yields item arrays with EXIF metadata.
     *
     * This method recursively scans a directory for photo files, extracts
     * EXIF metadata, and yields hydrated media item arrays ready for
     * insertion into the repository.
     *
     * @param string $libraryPath Absolute filesystem path to scan
     * @param string $libraryId The library's unique identifier
     * @return \Generator<array<string, mixed>> Yields media item arrays
     *
     * @since 0.16.0
     */
    public function scanPhotoLibrary(string $libraryPath, string $libraryId): \Generator
    {
        if (!is_dir($libraryPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($libraryPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!$this->isPhotoExtension($extension)) {
                continue;
            }

            if ($this->shouldSkipFile($file->getFilename())) {
                continue;
            }

            /** @var string */
            $path = $file->getPathname();
            $exif = $this->harvestExif($path);

            /** @var string */
            $name = $file->getBasename('.' . $extension);

            yield [
                'library_id' => $libraryId,
                'name' => $name,
                'type' => 'photo',
                'path' => $path,
                'metadata_json' => $exif,
            ];
        }
    }

    /**
     * Gets the supported photo extensions.
     *
     * @return array<string> Array of supported extensions
     *
     * @since 0.16.0
     */
    public function getSupportedExtensions(): array
    {
        return self::SUPPORTED_EXTENSIONS;
    }
}
