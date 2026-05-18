<?php

declare(strict_types=1);

namespace Phlex\Media\Music;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\BookLibraryManager;
use Phlex\Media\Library\BookScanner;
use Phlex\Media\Library\ItemRepository;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * BookLibraryType registers the 'book' library type plugin.
 *
 * This class implements LibraryTypeInterface to provide book-specific
 * scanning and metadata handling. It supports EPUB, PDF, and CBZ formats
 * and provides OPDS 1.2 compliant feeds for third-party clients.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Library type plugin for book media with OPDS support
 * @see BookScanner For EPUB, PDF, CBZ discovery and metadata extraction
 * @see BookLibraryManager For book library management
 * @since 0.17.0
 */
final class BookLibraryType implements \Phlex\Media\Library\LibraryTypeInterface
{
    /** Library type identifier */
    public const TYPE = 'book';

    /**
     * Gets the library type identifier.
     *
     * @return string The type string ('book')
     */
    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * Gets the human-readable label for this library type.
     *
     * @return string Display label ('Books')
     */
    public function getLabel(): string
    {
        return 'Books';
    }

    /**
     * Gets the scanner instance for this library type.
     *
     * Returns a BookScanner configured for book file discovery
     * and metadata extraction (EPUB content.opf, PDF metadata, CBZ ComicInfo.xml).
     *
     * @param Connection $db Database connection
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return BookScanner Configured book scanner
     */
    public function getScanner(
        Connection $db,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): BookScanner {
        // The interface accepts LoggerInterface but BookScanner requires StructuredLogger
        // Only pass the logger if it's a StructuredLogger instance
        $structuredLogger = $logger instanceof StructuredLogger ? $logger : null;
        return new BookScanner($db, $itemRepo, $structuredLogger);
    }

    /**
     * Gets the library manager for this library type.
     *
     * Returns a BookLibraryManager configured for book-specific
     * library management and OPDS feed generation.
     *
     * @param Connection $db Database connection
     * @param BookScanner $scanner Book scanner
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return BookLibraryManager Configured book library manager
     */
    public function getLibraryManager(
        Connection $db,
        BookScanner $scanner,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): BookLibraryManager {
        return new BookLibraryManager($scanner, $itemRepo, $logger);
    }
}
