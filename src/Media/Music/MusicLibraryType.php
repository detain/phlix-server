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
        // review r2 F2): `AudioScanner extends MediaScanner`
        // (`src/Media/Library/AudioScanner.php:28`), whose constructor declares
        // `?StructuredLogger $logger = null` (`src/Media/Library/MediaScanner.php:483`,
        // param at :486). That CURRENT signature is what pins this call site. Widening a
        // video-scanner constructor carries an estate-wide blast radius, well outside a
        // music observability step — and after the W42 rescope nothing external asks for it.
        //
        // W42 retired this comment's old excuses (step **S132**; rescope provenance: the
        // unnumbered S167 arm, 1d505406 + f53c8567, and S439, e4853f0f). The temp-dir
        // minter is gone — `MediaScanner::createDefaultLogger()` (:546) returns the shared
        // `LoggerFactory::get(LogChannels::MEDIA)` logger, an `.logs/` destination that
        // mints no temporary directory — its own docblock (:533-545) records the retired
        // minter. And the constructor was never widened, so no external "blocker" keeps
        // this narrowing beyond the signature quoted above.
        //
        // ⚠ The pattern is **23** classes (review r3 finding 9). This comment used to say
        // "one of ~10 copies … recorded as a follow-up": the count was 2.3x low AND no
        // such step existed, so a reader who trusted it went looking for work nobody had
        // filed.
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
