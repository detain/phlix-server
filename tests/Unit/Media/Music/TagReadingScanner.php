<?php

/**
 * Phlix media server test double: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicLibraryScanner;

/**
 * Exposes the two `protected` seams S122(c) changes.
 *
 * @internal
 */
final class TagReadingScanner extends MusicLibraryScanner
{
    /**
     * @param string $path Absolute filesystem path.
     * @return array<string, mixed>|null
     */
    public function readTags(string $path): ?array
    {
        return $this->probeViaGetId3($path);
    }

    public function reader(): \getID3
    {
        return $this->getId3Reader();
    }
}
