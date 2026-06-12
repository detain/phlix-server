<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Subtitles;

/**
 * Detects embedded TEXT subtitle tracks and builds extraction commands.
 *
 * Used by the transcode pipeline so a 10-bit HEVC (or any) source's embedded
 * ASS/SRT/mov_text subtitles become selectable WebVTT sidecar tracks served
 * alongside the CMAF segments. BITMAP subtitles (PGS / DVD VobSub) are NOT
 * extractable to text and are skipped.
 *
 * Each detected text track is mapped to a `sub-{index}.vtt` sidecar in the job
 * directory. Extraction is performed by FFmpeg's `webvtt` encoder and then the
 * ASS override/drawing markup is stripped via {@see AssWebVttCleaner} (FFmpeg
 * copies that markup verbatim into the cue text). The actual command runs in the
 * detached job script so the request thread never blocks on FFmpeg.
 *
 * @since 0.25.0
 */
final class SubtitleExtractor
{
    /**
     * Text subtitle codecs that can be converted to WebVTT. Anything else
     * (bitmap PGS/VobSub) is excluded — there is no text to extract.
     *
     * @var array<int, string>
     */
    private const TEXT_CODECS = ['ass', 'ssa', 'subrip', 'srt', 'mov_text', 'webvtt', 'vtt', 'text'];

    /**
     * Builds the selectable WebVTT track descriptors for a probed source.
     *
     * The `index` is the per-type subtitle ordinal (0-based) used by FFmpeg's
     * `-map 0:s:{index}` selector and by the `sub-{index}.vtt` filename, NOT the
     * absolute stream index. `label` is disambiguated when multiple tracks share
     * a language ("English 1" / "English 2"). Exactly one track is marked
     * `default`: the source's default-disposition text track, else the first.
     *
     * @param array<string, mixed> $probe Raw ffprobe result (with `streams`).
     *
     * @return list<array{
     *     index: int,
     *     language: string,
     *     label: string,
     *     default: bool,
     *     codec: string,
     *     filename: string
     * }>
     *
     * @since 0.25.0
     */
    public function detectTextTracks(array $probe): array
    {
        $streams = $probe['streams'] ?? [];
        if (!is_array($streams)) {
            return [];
        }

        /** @var list<array{index:int,language:string,label:string,default:bool,codec:string,filename:string}> $tracks */
        $tracks = [];
        $subOrdinal = -1;
        $langCounts = [];
        $defaultSeen = false;

        foreach ($streams as $stream) {
            if (!is_array($stream) || ($stream['codec_type'] ?? null) !== 'subtitle') {
                continue;
            }
            // Per-type ordinal increments for EVERY subtitle stream so it stays in
            // lock-step with ffmpeg's 0:s:N numbering, even for skipped bitmaps.
            $subOrdinal++;

            $codec = strtolower(is_string($stream['codec_name'] ?? null) ? $stream['codec_name'] : '');
            if (!in_array($codec, self::TEXT_CODECS, true)) {
                continue; // bitmap (pgs/dvdsub) — not text, cannot become VTT
            }

            $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
            $language = $this->stringTag($tags, 'language')
                ?? $this->stringTag($tags, 'LANGUAGE')
                ?? 'und';
            $title = $this->stringTag($tags, 'title') ?? $this->stringTag($tags, 'TITLE');

            $disposition = is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];
            $defaultFlag = $disposition['default'] ?? 0;
            $isDefault = is_numeric($defaultFlag) && (int) $defaultFlag === 1;
            if ($isDefault) {
                $defaultSeen = true;
            }

            $langCounts[$language] = ($langCounts[$language] ?? 0) + 1;

            $tracks[] = [
                'index' => $subOrdinal,
                'language' => $language,
                'label' => $title ?? $this->languageName($language),
                'default' => $isDefault,
                'codec' => $codec,
                'filename' => "sub-{$subOrdinal}.vtt",
            ];
        }

        if ($tracks === []) {
            return [];
        }

        // Disambiguate labels when a language repeats and the track had no title.
        $langSeen = [];
        foreach ($tracks as $i => $track) {
            if (($langCounts[$track['language']] ?? 0) > 1) {
                $n = ($langSeen[$track['language']] ?? 0) + 1;
                $langSeen[$track['language']] = $n;
                // Only number the generated (language-name) labels; respect titles.
                if ($track['label'] === $this->languageName($track['language'])) {
                    $tracks[$i]['label'] = $this->languageName($track['language']) . ' ' . $n;
                }
            }
        }

        // Mark a default: keep the source default disposition if present, else
        // promote the first track.
        if (!$defaultSeen) {
            $tracks[0]['default'] = true;
        }

        return $tracks;
    }

    /**
     * Builds a shell snippet that extracts + cleans one text subtitle track.
     *
     * Runs `ffmpeg -map 0:s:{index} -c:s webvtt` into a temp file then pipes it
     * through the VTT-cleaner CLI ({@see scripts/clean-vtt.php}) into the final
     * `sub-{index}.vtt`. Returns a single `&&`-chainable command so the caller
     * can append it to the detached transcode script — no blocking in the worker.
     *
     * @param string $ffmpegPath Absolute ffmpeg binary path.
     * @param string $phpPath    Absolute php binary path (for the cleaner CLI).
     * @param string $cleanerCli Absolute path to scripts/clean-vtt.php.
     * @param string $inputPath  Source media path.
     * @param string $outDir     Job output directory.
     * @param int    $index      Per-type subtitle ordinal (0:s:{index}).
     *
     * @return string A shell command (no trailing separator).
     *
     * @since 0.25.0
     */
    public function buildExtractCommand(
        string $ffmpegPath,
        string $phpPath,
        string $cleanerCli,
        string $inputPath,
        string $outDir,
        int $index
    ): string {
        $raw = $outDir . '/sub-' . $index . '.raw.vtt';
        $final = $outDir . '/sub-' . $index . '.vtt';

        $extract = sprintf(
            '%s -y -hide_banner -loglevel error -i %s -map 0:s:%d -c:s webvtt %s',
            escapeshellarg($ffmpegPath),
            escapeshellarg($inputPath),
            $index,
            escapeshellarg($raw)
        );
        $cleanCmd = sprintf(
            '%s %s %s %s',
            escapeshellarg($phpPath),
            escapeshellarg($cleanerCli),
            escapeshellarg($raw),
            escapeshellarg($final)
        );

        // Extraction failures (e.g. a track that won't convert) must not abort the
        // whole job, so this sub-pipeline is wrapped to always succeed: a missing
        // sub-N.vtt simply won't be served. The video encode is the hard part.
        return '( ' . $extract . ' && ' . $cleanCmd . ' ; rm -f ' . escapeshellarg($raw) . ' ) || true';
    }

    /**
     * Reads a tag value as a non-empty string, or null.
     *
     * @param array<string, mixed> $tags
     */
    private function stringTag(array $tags, string $key): ?string
    {
        $value = $tags[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return null;
    }

    /**
     * Maps an ISO language code to a display name (falls back to upper-cased code).
     */
    private function languageName(string $code): string
    {
        $names = [
            'eng' => 'English',
            'en' => 'English',
            'fra' => 'French',
            'fre' => 'French',
            'spa' => 'Spanish',
            'deu' => 'German',
            'ger' => 'German',
            'ita' => 'Italian',
            'por' => 'Portuguese',
            'rus' => 'Russian',
            'jpn' => 'Japanese',
            'jp' => 'Japanese',
            'kor' => 'Korean',
            'chi' => 'Chinese',
            'zho' => 'Chinese',
            'und' => 'Unknown',
        ];
        return $names[strtolower($code)] ?? strtoupper($code);
    }
}
