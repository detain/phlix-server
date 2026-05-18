<?php

declare(strict_types=1);

namespace Phlex\Media\Music;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Library\PhotoLibraryManager;
use Phlex\Media\Library\PhotoScanner;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * PhotoLibraryType registers the 'photo' library type plugin.
 *
 * This class implements LibraryTypeInterface to provide photo-specific
 * scanning and metadata handling. It is automatically discovered by the
 * library type registry and used when creating or scanning photo libraries.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Library type plugin for photo media with EXIF extraction
 * @see PhotoScanner For photo discovery and EXIF extraction
 * @see PhotoLibraryManager For photo library management
 * @since 0.16.0
 */
final class PhotoLibraryType implements \Phlex\Media\Library\LibraryTypeInterface
{
    /** Library type identifier */
    public const TYPE = 'photo';

    /**
     * Gets the library type identifier.
     *
     * @return string The type string ('photo')
     */
    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * Gets the human-readable label for this library type.
     *
     * @return string Display label ('Photos')
     */
    public function getLabel(): string
    {
        return 'Photos';
    }

    /**
     * Gets the scanner instance for this library type.
     *
     * Returns a PhotoScanner configured for photo file discovery
     * and EXIF metadata extraction.
     *
     * @param Connection $db Database connection
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return PhotoScanner Configured photo scanner
     */
    public function getScanner(
        Connection $db,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): PhotoScanner {
        // The interface accepts LoggerInterface but PhotoScanner requires StructuredLogger
        // Only pass the logger if it's a StructuredLogger instance
        $structuredLogger = $logger instanceof StructuredLogger ? $logger : null;
        return new PhotoScanner($db, $itemRepo, $structuredLogger);
    }

    /**
     * Gets the library manager for this library type.
     *
     * Returns a PhotoLibraryManager configured for photo-specific
     * library management and EXIF metadata enrichment.
     *
     * @param Connection $db Database connection
     * @param PhotoScanner $scanner Photo scanner
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return PhotoLibraryManager Configured photo library manager
     */
    public function getLibraryManager(
        Connection $db,
        PhotoScanner $scanner,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): PhotoLibraryManager {
        // Only pass the logger if it's a StructuredLogger instance
        $structuredLogger = $logger instanceof StructuredLogger ? $logger : null;
        return new PhotoLibraryManager($scanner, $itemRepo, $structuredLogger);
    }
}
