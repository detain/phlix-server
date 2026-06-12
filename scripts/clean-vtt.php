#!/usr/bin/env php
<?php

/**
 * VTT cleaner CLI.
 *
 * Post-processes a WebVTT file emitted by `ffmpeg -c:s webvtt` from an ASS/SSA
 * source, stripping the ASS override/drawing markup FFmpeg copies verbatim into
 * the cue text (e.g. `{*\fax0.392}`, `\N`, `<font ...>`, vector-drawing paths).
 * Invoked by the detached transcode job script after subtitle extraction so the
 * Workerman request thread never blocks on it.
 *
 * Usage: php scripts/clean-vtt.php <input.vtt> <output.vtt>
 *
 * Exits 0 on success, 1 on bad arguments / unreadable input / write failure.
 *
 * @since 0.25.0
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Phlix\Media\Transcoding\Subtitles\AssWebVttCleaner;

$argvList = $argv ?? [];
if (count($argvList) < 3) {
    fwrite(STDERR, "usage: clean-vtt.php <input.vtt> <output.vtt>\n");
    exit(1);
}

$input = (string) $argvList[1];
$output = (string) $argvList[2];

$raw = is_file($input) ? file_get_contents($input) : false;
if (!is_string($raw)) {
    fwrite(STDERR, "clean-vtt: cannot read {$input}\n");
    exit(1);
}

$cleaned = AssWebVttCleaner::clean($raw);

if (file_put_contents($output, $cleaned) === false) {
    fwrite(STDERR, "clean-vtt: cannot write {$output}\n");
    exit(1);
}

exit(0);
