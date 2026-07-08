<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Transcoding\Subtitles;

/**
 * Cleans the WebVTT that FFmpeg's `webvtt` encoder emits from an ASS/SSA source.
 *
 * FFmpeg converts ASS dialogue to WebVTT but copies the ASS inline override and
 * drawing markup verbatim into the cue text — e.g. `{*\fax0.392}`, `{\an5}`,
 * `\N`, `<font color="...">…</font>`, and standalone vector-drawing commands like
 * `m 0 0 l 150 0 150 150`. A browser renders that markup as literal garbage. This
 * helper post-processes the VTT so each cue carries only readable text:
 *
 *   - strips every `{...}` ASS override block (including `{*\...}` comment blocks);
 *   - converts `\N` / `\n` hard line breaks to real newlines and `\h` to a space;
 *   - drops `<font ...>` / `</font>` tags FFmpeg leaks (VTT has no <font>), while
 *     KEEPING `<b>` / `<i>` / `<u>` which WebVTT natively supports;
 *   - removes cues whose text is a pure ASS vector-drawing path (typeset shapes
 *     that have no dialogue value), collapsing them to empty so they are skipped;
 *   - preserves the `WEBVTT` header, cue timing lines and blank-line structure.
 *
 * The transform is a pure string→string function with no I/O so it is trivially
 * unit-testable; {@see SubtitleExtractor} writes the cleaned result to disk.
 *
 * @since 0.25.0
 */
final class AssWebVttCleaner
{
    /**
     * Cleans a full WebVTT document produced from an ASS/SSA track.
     *
     * @param string $vtt Raw WebVTT text (as emitted by `ffmpeg -c:s webvtt`).
     *
     * @return string Cleaned WebVTT text, guaranteed to start with `WEBVTT`.
     *
     * @since 0.25.0
     */
    public static function clean(string $vtt): string
    {
        // Normalise line endings so cue/structure detection is consistent.
        $vtt = str_replace(["\r\n", "\r"], "\n", $vtt);
        $lines = explode("\n", $vtt);

        $out = [];
        $hasHeader = false;
        foreach ($lines as $line) {
            if (!$hasHeader) {
                // The first non-empty line is the WEBVTT signature; keep it as-is
                // (it may carry a BOM / header text which browsers tolerate).
                $out[] = $line;
                if (str_starts_with(ltrim($line, "\xEF\xBB\xBF"), 'WEBVTT')) {
                    $hasHeader = true;
                }
                continue;
            }

            // Structural lines (blank separators, cue timing, NOTE/STYLE blocks,
            // numeric cue identifiers) pass through untouched.
            if ($line === '' || self::isStructuralLine($line)) {
                $out[] = $line;
                continue;
            }

            $out[] = self::cleanCueText($line);
        }

        if (!$hasHeader) {
            array_unshift($out, 'WEBVTT');
        }

        return implode("\n", $out);
    }

    /**
     * Cleans a single line of cue text (ASS markup → readable WebVTT text).
     *
     * Exposed for unit testing the per-line transform in isolation.
     *
     * @param string $text One line of WebVTT cue payload.
     *
     * @return string The cleaned line (may be empty if it was pure drawing/markup).
     *
     * @since 0.25.0
     */
    public static function cleanCueText(string $text): string
    {
        // 1. Remove every {...} ASS override/comment block, including {*\...}.
        //    Non-greedy so adjacent blocks aren't merged across real text.
        $text = (string) preg_replace('/\{[^}]*\}/u', '', $text);

        // 2. Convert ASS hard line breaks (\N and \n) to real newlines and the
        //    non-breaking space (\h) to a normal space.
        $text = str_replace(['\\N', '\\n'], "\n", $text);
        $text = str_replace('\\h', ' ', $text);

        // 3. Drop <font ...> / </font> (VTT has no font tag) but keep b/i/u/ruby.
        $text = (string) preg_replace('#</?font[^>]*>#i', '', $text);

        // 4. A cue that is now nothing but an ASS vector-drawing path (e.g.
        //    "m 0 0 l 150 0 150 150 0 150 b 1 2 3 4 5 6") carries no dialogue;
        //    collapse it to empty so the player skips it.
        if (self::isDrawingPath($text)) {
            return '';
        }

        // 5. Trim trailing whitespace introduced by stripped markup.
        return rtrim($text);
    }

    /**
     * Whether a line is WebVTT structure (not cue text) that must pass through.
     *
     * @param string $line A non-empty VTT line.
     */
    private static function isStructuralLine(string $line): bool
    {
        // Cue timing line: "00:00.000 --> 00:05.000 [settings]".
        if (str_contains($line, '-->')) {
            return true;
        }
        $trimmed = trim($line);
        // NOTE / STYLE / REGION blocks.
        if (
            str_starts_with($trimmed, 'NOTE')
            || str_starts_with($trimmed, 'STYLE')
            || str_starts_with($trimmed, 'REGION')
        ) {
            return true;
        }
        // Pure-numeric cue identifier (a line that is only digits).
        return preg_match('/^\d+$/', $trimmed) === 1;
    }

    /**
     * Whether stripped text is an ASS vector-drawing path with no real words.
     *
     * ASS drawing mode (`\p1`) lines are sequences of single-letter drawing
     * commands (m, n, l, b, s, p, c) and numbers — never natural-language text.
     *
     * @param string $text Already override-stripped cue text.
     */
    private static function isDrawingPath(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return false;
        }
        // Must start with a drawing command letter followed by a number, and
        // contain ONLY drawing command letters, numbers, dots, minus and spaces.
        if (preg_match('/^[mnlbspc]\s+-?\d/i', $trimmed) !== 1) {
            return false;
        }
        return preg_match('/^[mnlbspc0-9.\s-]+$/i', $trimmed) === 1;
    }
}
