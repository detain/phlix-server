<?php

/**
 * Phlix media server component: Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
        // This narrowing is FORCED and stays (unlike getLibraryManager()'s, removed in
        // review r2 F2): `AudioScanner extends MediaScanner`, whose constructor declares
        // `?StructuredLogger $logger` (`MediaScanner.php:290`). Widening it is a video-
        // scanner change with an estate-wide blast radius, well outside an Effort-S music
        // observability step — and `MediaScanner::createDefaultLogger()` mints its own
        // `/tmp/phlix_media_*` directory, so it is one of ~10 copies of the same pattern
        // recorded as a follow-up rather than a one-line fix here.
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
     * @param LoggerInterface|null $logger Optional logger. Forwarded AS-IS since review
     *        r2 F2 — see below for what the old narrowing did.
     * @return \Phlix\Media\Library\MusicLibraryManager Configured music library manager
     */
    public function getLibraryManager(
        \Workerman\MySQL\Connection $db,
        AudioScanner $scanner,
        MetadataManager $metadataManager,
        ItemRepository $itemRepo,
        ?LoggerInterface $logger = null
    ): \Phlix\Media\Library\MusicLibraryManager {
        // ⚠ The `$logger instanceof StructuredLogger ? $logger : null` narrowing that used
        // to be on this line was the SECOND HALF of the S96(a) defect, in a second class
        // (review r2 F2). It silently threw away any other PSR-3 logger and passed `null`,
        // which sent `MusicLibraryManager` down its private temp-directory branch — so no
        // amount of container wiring could have fixed that class either. The manager's
        // constructor now accepts `?LoggerInterface`, so the logger is forwarded intact.
        return new \Phlix\Media\Library\MusicLibraryManager(
            $scanner,
            $metadataManager,
            $itemRepo,
            $db,
            $logger
        );
    }
}
