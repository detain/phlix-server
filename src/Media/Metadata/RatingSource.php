<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

enum RatingSource: string
{
    case Tmdb = 'tmdb';
    case Imdb = 'imdb';
    case User = 'user';
}
