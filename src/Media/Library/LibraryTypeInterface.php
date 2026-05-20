<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Workerman\MySQL\Connection;

/**
 * LibraryTypeInterface defines the contract for library type plugins.
 *
 * Library type plugins provide type-specific scanner and manager instances
 * for different media types (video, music, photos, books, audiobooks).
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Interface for library type plugins
 * @see MusicLibraryType For the music library type implementation
 */
interface LibraryTypeInterface
{
    /**
     * Gets the library type identifier.
     *
     * @return string Type string (e.g., 'video', 'music', 'photo', 'book', 'audiobook')
     */
    public function getType(): string;

    /**
     * Gets the human-readable label for this library type.
     *
     * @return string Display label (e.g., 'Music', 'Photos', 'Books')
     */
    public function getLabel(): string;

    /**
     * Gets the scanner instance for this library type.
     *
     * @param Connection $db Database connection
     * @param ItemRepository $itemRepo Item repository
     * @param \Psr\Log\LoggerInterface|null $logger Optional logger
     * @return MediaScanner|audioScanner Configured scanner for this type
     */
    public function getScanner(
        Connection $db,
        ItemRepository $itemRepo,
        ?\Psr\Log\LoggerInterface $logger = null
    ): MediaScanner;
}
