<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

enum RatingType: string
{
    case Average = 'average';
    case User = 'user';
    case Critic = 'critic';
    case Meta = 'meta';
}
