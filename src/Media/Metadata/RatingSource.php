<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

enum RatingSource: string
{
    case Imdb = 'imdb';
    case Tmdb = 'tmdb';
    case Rt = 'rt';
    case Aggregate = 'aggregate';
    case User = 'user';
}
