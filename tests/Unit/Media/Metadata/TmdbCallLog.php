<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

/**
 * Mutable request counter shared with the TmdbProvider test double in
 * {@see SeriesMetadataResolverIdentityTest}. A plain object (not a by-ref array)
 * so the capturing closures stay static and PHPStan can type the fields.
 */
final class TmdbCallLog
{
    /** @var int Number of `/search/tv` requests issued. */
    public int $searches = 0;

    /** @var list<string> TMDB ids passed to `getTvDetails()`, in order. */
    public array $details = [];
}
