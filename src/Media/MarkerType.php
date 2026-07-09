<?php
declare(strict_types=1);
namespace Phlix\Media;

enum MarkerType: string
{
    case Intro = 'intro';
    case Outro = 'outro';
    case Credits = 'credits';
    case Ad = 'ad';
    case Chapter = 'chapter';
}
