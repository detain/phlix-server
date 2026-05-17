<?php

declare(strict_types=1);

namespace Phlex\Media\Streaming\Trickplay;

/**
 * Trickplay Configuration Value Object.
 *
 * Encapsulates all configuration for trickplay (thumbnail seek) generation
 * including grid dimensions, thumbnail size, and image format settings.
 *
 * @since 0.11.0
 */
final class TrickplayConfig
{
    /**
     * Creates a new TrickplayConfig instance.
     *
     * @param int $interval_seconds Time between thumbnails in seconds (default: 10)
     * @param int $grid_columns Number of columns in each grid image (default: 8)
     * @param int $grid_rows Number of rows in each grid image (default: 4)
     * @param int $thumb_width Width of each thumbnail in pixels (default: 160)
     * @param int $thumb_height Height of each thumbnail in pixels (default: 90)
     * @param string $image_format Image format: 'jpeg' or 'png' (default: 'jpeg')
     * @param int $jpeg_quality JPEG compression quality 1-100 (default: 72)
     */
    public function __construct(
        public readonly int $interval_seconds = 10,
        public readonly int $grid_columns = 8,
        public readonly int $grid_rows = 4,
        public readonly int $thumb_width = 160,
        public readonly int $thumb_height = 90,
        public readonly string $image_format = 'jpeg',
        public readonly int $jpeg_quality = 72,
    ) {
        if ($interval_seconds < 1) {
            throw new \InvalidArgumentException('interval_seconds must be at least 1');
        }
        if ($grid_columns < 1) {
            throw new \InvalidArgumentException('grid_columns must be at least 1');
        }
        if ($grid_rows < 1) {
            throw new \InvalidArgumentException('grid_rows must be at least 1');
        }
        if ($thumb_width < 1) {
            throw new \InvalidArgumentException('thumb_width must be at least 1');
        }
        if ($thumb_height < 1) {
            throw new \InvalidArgumentException('thumb_height must be at least 1');
        }
        if (!in_array($image_format, ['jpeg', 'png'], true)) {
            throw new \InvalidArgumentException('image_format must be "jpeg" or "png"');
        }
        if ($jpeg_quality < 1 || $jpeg_quality > 100) {
            throw new \InvalidArgumentException('jpeg_quality must be between 1 and 100');
        }
    }

    /**
     * Gets the total number of thumbnails per grid image.
     *
     * @return int Number of thumbnails in a single grid
     */
    public function getThumbnailsPerGrid(): int
    {
        return $this->grid_columns * $this->grid_rows;
    }

    /**
     * Gets the grid image dimensions.
     *
     * @return array{width: int, height: int} Full grid image dimensions
     */
    public function getGridDimensions(): array
    {
        return [
            'width' => $this->grid_columns * $this->thumb_width,
            'height' => $this->grid_rows * $this->thumb_height,
        ];
    }

    /**
     * Gets the file extension for the configured image format.
     *
     * @return string File extension (including dot)
     */
    public function getFileExtension(): string
    {
        return $this->image_format === 'jpeg' ? '.jpg' : '.png';
    }

    /**
     * Gets the MIME type for the configured image format.
     *
     * @return string MIME type string
     */
    public function getMimeType(): string
    {
        return $this->image_format === 'jpeg' ? 'image/jpeg' : 'image/png';
    }
}
