<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Subtitles;

use Phlix\Media\Transcoding\Subtitles\AssWebVttCleaner;

/**
 * Pure string→string conversion of a downloaded subtitle's raw content into
 * WebVTT, so a provider-fetched subtitle can be served to the player through
 * the SAME `text/vtt` `<track>` contract the embedded-track extraction endpoint
 * already emits ({@see \Phlix\Server\Http\Controllers\SubtitleController::getTrack()}).
 *
 * This is deliberately I/O-free and dependency-free (beyond the existing
 * {@see AssWebVttCleaner}), so it is safe on the resident Workerman worker and
 * trivially unit-testable: no ffmpeg process is spawned for the common
 * text formats providers return (SubRip / WebVTT), which are the only ones
 * `opensubtitles` and its peers deliver via their download endpoints.
 *
 * @package Phlix\Media\Subtitles
 * @since 0.43.0
 */
final class WebVttConverter
{
    /**
     * Defensive upper bound on the subtitle payload we will process. A text
     * subtitle far larger than this is not legitimate; capping bounds the
     * transient memory the conversion (which explodes the whole string) can use
     * on a provider-supplied blob. 8 MiB is orders of magnitude above any real
     * subtitle track.
     */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Convert raw subtitle content to WebVTT text.
     *
     *  - `vtt`/`webvtt` → cleaned through {@see AssWebVttCleaner} (strips any
     *    stray ASS override markup and guarantees a `WEBVTT` header).
     *  - `srt`/`subrip` → SubRip cues rewritten to WebVTT (comma→dot in
     *    timestamps, numeric cue indexes dropped, `WEBVTT` header prepended).
     *  - anything else → wrapped defensively with a `WEBVTT` header so the
     *    player never receives a header-less body (best-effort; exotic binary
     *    formats such as PGS never reach here — the search layer only surfaces
     *    text candidates).
     *
     * @param string $content Raw decoded subtitle content.
     * @param string $format  Source format extension without a dot (e.g. `srt`).
     *
     * @return string WebVTT text, always starting with `WEBVTT`.
     *
     * @since 0.43.0
     */
    public static function toWebVtt(string $content, string $format): string
    {
        if (strlen($content) > self::MAX_BYTES) {
            $content = substr($content, 0, self::MAX_BYTES);
        }

        $normalised = str_replace(["\r\n", "\r"], "\n", $content);

        return match (strtolower(trim($format))) {
            'vtt', 'webvtt' => AssWebVttCleaner::clean($normalised),
            'srt', 'subrip' => self::fromSubRip($normalised),
            default => self::ensureHeader($normalised),
        };
    }

    /**
     * Convert SubRip (`.srt`) text to WebVTT.
     *
     * SubRip and WebVTT share cue structure; the only wire differences that
     * matter for playback are the `WEBVTT` header, the `,` millisecond
     * separator (WebVTT uses `.`), and SubRip's leading numeric cue index
     * (WebVTT treats a bare number as an optional cue id, which is harmless, but
     * dropping it keeps the output clean).
     *
     * @param string $srt Newline-normalised SubRip text.
     *
     * @return string WebVTT text.
     */
    private static function fromSubRip(string $srt): string
    {
        $lines = explode("\n", $srt);
        $out = ['WEBVTT', ''];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Drop a SubRip numeric cue-index line ONLY when the NEXT line is a
            // timing line (which is how a real cue index appears). Without the
            // look-ahead, a legitimate cue whose text is nothing but a number
            // (e.g. a countdown "3") would be silently dropped.
            if (
                $trimmed !== '' && ctype_digit($trimmed)
                && isset($lines[$i + 1]) && str_contains($lines[$i + 1], '-->')
            ) {
                continue;
            }

            // Timing line: `00:00:01,000 --> 00:00:04,000` → `.` separators.
            if (str_contains($line, '-->')) {
                $out[] = str_replace(',', '.', $line);
                continue;
            }

            $out[] = $line;
        }

        return rtrim(implode("\n", $out), "\n") . "\n";
    }

    /**
     * Guarantee the body starts with a `WEBVTT` header without otherwise
     * altering it.
     *
     * @param string $body Newline-normalised subtitle body.
     *
     * @return string
     */
    private static function ensureHeader(string $body): string
    {
        $head = ltrim($body, "\xEF\xBB\xBF \t\n");
        if (str_starts_with($head, 'WEBVTT')) {
            return $body;
        }

        return "WEBVTT\n\n" . $body;
    }
}
