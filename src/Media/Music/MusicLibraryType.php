<?php

declare(strict_types=1);

namespace Phlix\Media\Music;

use Phlix\Media\Library\AudioScanner;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * MusicLibraryType registers the 'music' library type plugin.
 *
 * This class implements LibraryTypeInterface to provide music-specific
 * scanning and metadata handling. It is automatically discovered by the
 * library type registry and used when creating or scanning music libraries.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Library type plugin for music media
 * @see LibraryManager For library management operations
 * @see AudioScanner For audio file scanning
 */
final class MusicLibraryType implements \Phlix\Media\Library\LibraryTypeInterface
{
    /** Library type identifier */
    public const TYPE = 'music';

    /**
     * Gets the library type identifier.
     *
     * @return string The type string ('music')
     */
    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * Gets the human-readable label for this library type.
     *
     * @return string Display label ('Music')
     */
    public function getLabel(): string
    {
        return 'Music';
    }

    /**
     * Gets the scanner instance for this library type.
     *
     * Returns an AudioScanner configured for music file discovery
     * and ID3/MP4 tag harvesting.
     *
     * @param \Workerman\MySQL\Connection $db Database connection
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return AudioScanner Configured audio scanner
     */
    public function getScanner(
        \Workerman\MySQL\Connection $db,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): AudioScanner {
        $structured = $logger instanceof StructuredLogger ? $logger : null;
        return new AudioScanner($db, $itemRepo, $structured);
    }

    /**
     * Gets the library manager for this library type.
     *
     * Returns a MusicLibraryManager configured for music-specific
     * library management and metadata enrichment.
     *
     * @param \Workerman\MySQL\Connection $db Database connection
     * @param AudioScanner $scanner Audio scanner
     * @param MetadataManager $metadataManager Metadata manager
     * @param ItemRepository $itemRepo Item repository
     * @param LoggerInterface|null $logger Optional logger
     * @return \Phlix\Media\Library\MusicLibraryManager Configured music library manager
     */
    public function getLibraryManager(
        \Workerman\MySQL\Connection $db,
        AudioScanner $scanner,
        MetadataManager $metadataManager,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): \Phlix\Media\Library\MusicLibraryManager {
        $structured = $logger instanceof StructuredLogger ? $logger : null;
        return new \Phlix\Media\Library\MusicLibraryManager(
            $scanner,
            $metadataManager,
            $itemRepo,
            $db,
            $structured
        );
    }
}
