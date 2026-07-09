<?php
declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

namespace Phlix\Media;

enum MarkerType: string
{
    case Intro = 'intro';
    case Outro = 'outro';
    case Credits = 'credits';
    case Ad = 'ad';
    case Chapter = 'chapter';
}
