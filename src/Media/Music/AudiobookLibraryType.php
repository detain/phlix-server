<?php

declare(strict_types=1);

namespace Phlex\Media\Music;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\Media\Library\AudiobookLibraryManager;
use Phlex\Media\Library\AudiobookProgressStore;
use Phlex\Media\Library\AudiobookScanner;
use Phlex\Media\Library\ItemRepository;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * AudiobookLibraryType registers the 'audiobook' library type plugin.
 *
 * This class implements LibraryTypeInterface to provide audiobook-specific
 * scanning and metadata handling. It supports M4B, M4A, and MP3 formats
 * with chapter extraction from MP4 atoms or ID3v2 CMT2/CHAP frames.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Library type plugin for audiobook media with chapter awareness
 * @see AudiobookScanner For M4B chapter extraction and metadata harvesting
 * @see AudiobookLibraryManager For audiobook library management
 * @see AudiobookProgressStore For per-user progress tracking
 * @since 0.18.0
 */
final class AudiobookLibraryType implements \Phlex\Media\Library\LibraryTypeInterface
{
    /** Library type identifier */
    public const TYPE = 'audiobook';

    /**
     * Gets the library type identifier.
     *
     * @return string The type string ('audiobook')
     */
    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * Gets the human-readable label for this library type.
     *
     * @return string Display label ('Audiobooks')
     */
    public function getLabel(): string
    {
        return 'Audiobooks';
    }

    /**
     * Gets the scanner instance for this library type.
     *
     * Returns an AudiobookScanner configured for audiobook file discovery
     * and chapter extraction from M4B `chpl` atoms.
     *
     * @param Connection $db Database connection
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return AudiobookScanner Configured audiobook scanner
     */
    public function getScanner(
        Connection $db,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): AudiobookScanner {
        $structuredLogger = $logger instanceof StructuredLogger ? $logger : null;
        return new AudiobookScanner($db, $itemRepo, $structuredLogger);
    }

    /**
     * Gets the library manager for this library type.
     *
     * Returns an AudiobookLibraryManager configured for audiobook-specific
     * library management and chapter-aware progress tracking.
     *
     * @param Connection $db Database connection
     * @param AudiobookScanner $scanner Audiobook scanner
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return AudiobookLibraryManager Configured audiobook library manager
     */
    public function getLibraryManager(
        Connection $db,
        AudiobookScanner $scanner,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): AudiobookLibraryManager {
        $progressStore = new AudiobookProgressStore($db);
        return new AudiobookLibraryManager($scanner, $itemRepo, $progressStore, $logger);
    }
}
