<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Auth\SignedUrl;

/**
 * Shapes `media_streams` rows into the playback-info `audio_tracks` and
 * `subtitle_tracks` arrays.
 *
 * Extracted so BOTH playback-info dispatch paths — the API router's
 * {@see \Phlix\Server\Http\Controllers\MediaItemController::getPlaybackInfo()}
 * (`GET /api/v1/media/{id}/playback-info`) and the web-portal's
 * {@see \Phlix\Server\WebPortal\WebPortalRouter::getPlaybackInfo()}
 * (`GET /api/v1/media/{id}/playback`) — emit byte-identical track shapes for
 * the player's audio/subtitle selection menus (mirrors the
 * {@see MediaItemShaper} convention).
 *
 * Index semantics: each track's `index` is the 0-based PER-TYPE ordinal
 * (ffmpeg's `0:a:N` / `0:s:N` selector space), counting every stream of that
 * type in `stream_index` order. The subtitle extraction endpoint
 * (`GET /api/v1/media/{id}/subtitles/{index}`, see
 * {@see \Phlix\Server\Http\Controllers\SubtitleController::getTrack()} →
 * {@see \Phlix\Media\Transcoding\FfmpegRunner::extractSubtitleVtt()} `-map 0:s:{index}`)
 * keys on exactly that subtitle-relative ordinal, so the ordinal counts ALL
 * subtitle rows (including any non-text/bitmap ones) even though only
 * text-codec tracks are emitted. `stream_index` is the global ffprobe stream
 * index persisted in the `media_streams.stream_index` column.
 *
 * @since 0.74.0
 */
final class StreamTrackShaper
{
    /**
     * Text subtitle codecs the extraction endpoint can convert to WebVTT.
     * Byte-identical to {@see \Phlix\Media\Transcoding\Subtitles\SubtitleExtractor}::TEXT_CODECS
     * — anything else (bitmap PGS/VobSub) has no text and is not listed.
     *
     * @var array<int, string>
     */
    private const TEXT_SUBTITLE_CODECS = ['ass', 'ssa', 'subrip', 'srt', 'mov_text', 'webvtt', 'vtt', 'text'];

    /**
     * Shapes the audio `media_streams` rows into the `audio_tracks` array.
     *
     * Keeps the pre-existing P3B-S2 fields (`id`/`codec`/`language`/`channels`/
     * `bitrate`/`title`) and ADDS `index` (0-based ordinal among audio streams,
     * `stream_index` order), `stream_index` (global ffprobe index) and `default`
     * (from a stored disposition when present, else the first audio track).
     *
     * @param array<int, array<string, mixed>> $streams All of the item's
     *        `media_streams` rows (any type; ideally already `stream_index`-ordered
     *        as {@see ItemRepository::getItemStreams()} returns them).
     *
     * @return list<array<string, mixed>> The shaped audio tracks (empty when none).
     */
    public static function audioTracks(array $streams): array
    {
        $tracks = [];
        $ordinal = -1;
        $defaultSeen = false;

        foreach (self::sortedByStreamIndex($streams) as $stream) {
            if (($stream['stream_type'] ?? '') !== 'audio') {
                continue;
            }
            $ordinal++;

            $isDefault = self::hasDefaultDisposition($stream);
            if ($isDefault) {
                $defaultSeen = true;
            }

            $tracks[] = [
                'id' => $stream['id'] ?? '',
                'index' => $ordinal,
                'stream_index' => self::intFrom($stream['stream_index'] ?? null) ?? $ordinal,
                'codec' => $stream['codec'] ?? '',
                'language' => self::nonEmptyString($stream['language'] ?? null) ?? 'und',
                'channels' => is_scalar($stream['channels'] ?? null) ? (int) $stream['channels'] : 0,
                'bitrate' => isset($stream['bitrate']) && is_scalar($stream['bitrate'])
                    ? (int) $stream['bitrate']
                    : null,
                'title' => $stream['title'] ?? null,
                'default' => $isDefault,
            ];
        }

        // No stored disposition marked a default → promote the first track, so
        // exactly one track is `default` whenever any exist.
        if (!$defaultSeen && $tracks !== []) {
            $tracks[0]['default'] = true;
        }

        return $tracks;
    }

    /**
     * Shapes the subtitle `media_streams` rows into the `subtitle_tracks` array.
     *
     * Only TEXT-codec tracks (extractable to WebVTT) are emitted, but the
     * `index` ordinal counts EVERY subtitle row so it stays in lock-step with
     * ffmpeg's `0:s:N` numbering — the exact index space the extraction
     * endpoint expects. Each track carries a SIGNED `url` to that endpoint
     * because a `<track>` element cannot attach a Bearer header (mirrors how
     * `stream_url` is minted for `/media/{id}/stream`).
     *
     * @param array<int, array<string, mixed>> $streams All of the item's
     *        `media_streams` rows (any type).
     * @param string         $itemId The media item UUID (used to build the URL;
     *                               tracks get `url: null` when empty).
     * @param SignedUrl|null $signer Signature minter; defaults to
     *                               {@see SignedUrl::fromEnv()} (test seam).
     *
     * @return list<array<string, mixed>> The shaped subtitle tracks (empty when none).
     */
    public static function subtitleTracks(array $streams, string $itemId, ?SignedUrl $signer = null): array
    {
        $signer ??= SignedUrl::fromEnv();
        $tracks = [];
        $subOrdinal = -1;
        $emitted = 0;

        foreach (self::sortedByStreamIndex($streams) as $stream) {
            if (($stream['stream_type'] ?? '') !== 'subtitle') {
                continue;
            }

            // DOWNLOADED external subtitle (F3): stored as a `.vtt` file on disk
            // (media_streams.storage_path, migration 088) rather than embedded in
            // the container. It is NOT part of ffmpeg's 0:s:N selector space, so
            // it must NOT consume a `$subOrdinal` — it is served by row id via the
            // external endpoint instead of the extraction endpoint.
            $storagePath = self::nonEmptyString($stream['storage_path'] ?? null);
            if ($storagePath !== null) {
                $emitted++;
                $streamId = self::nonEmptyString($stream['id'] ?? null);
                $language = self::nonEmptyString($stream['language'] ?? null);
                $title = self::nonEmptyString($stream['title'] ?? null);
                $source = self::nonEmptyString($stream['source'] ?? null);

                $tracks[] = [
                    'id' => $stream['id'] ?? '',
                    'index' => self::intFrom($stream['stream_index'] ?? null) ?? $emitted,
                    'stream_index' => self::intFrom($stream['stream_index'] ?? null) ?? $emitted,
                    'language' => $language ?? 'und',
                    'label' => $title ?? $language ?? ('Subtitle ' . $emitted),
                    'codec' => strtolower(self::nonEmptyString($stream['codec'] ?? null) ?? 'webvtt'),
                    'source' => $source,
                    'hearing_impaired' => self::isTruthy($stream['hearing_impaired'] ?? null),
                    'url' => ($itemId !== '' && $streamId !== null)
                        ? $signer->mint('/api/v1/media/' . $itemId . '/subtitles/external/' . $streamId)
                        : null,
                ];
                continue;
            }

            // Per-type ordinal increments for EVERY embedded subtitle row (even
            // skipped bitmaps) so it matches ffmpeg's 0:s:N selector — see docblock.
            $subOrdinal++;

            $codec = strtolower(self::nonEmptyString($stream['codec'] ?? null) ?? '');
            if (!in_array($codec, self::TEXT_SUBTITLE_CODECS, true)) {
                continue; // bitmap (pgs/dvdsub) — no text, the endpoint can't serve it
            }

            $emitted++;
            $language = self::nonEmptyString($stream['language'] ?? null);
            $title = self::nonEmptyString($stream['title'] ?? null);

            $tracks[] = [
                'id' => $stream['id'] ?? '',
                'index' => $subOrdinal,
                'stream_index' => self::intFrom($stream['stream_index'] ?? null) ?? $subOrdinal,
                'language' => $language ?? 'und',
                'label' => $title ?? $language ?? ('Subtitle ' . $emitted),
                'codec' => $codec,
                'source' => null,
                'hearing_impaired' => self::isTruthy($stream['hearing_impaired'] ?? null),
                'url' => $itemId !== ''
                    ? $signer->mint('/api/v1/media/' . $itemId . '/subtitles/' . $subOrdinal)
                    : null,
            ];
        }

        return $tracks;
    }

    /**
     * Returns the stream rows ordered by their global `stream_index` (stable —
     * rows without one keep their relative order at index 0). Defensive: both
     * callers already receive `stream_index`-ordered rows from
     * {@see ItemRepository::getItemStreams()}, but the per-type ordinals MUST
     * be computed over a deterministic order.
     *
     * @param array<int, array<string, mixed>> $streams
     *
     * @return list<array<string, mixed>>
     */
    private static function sortedByStreamIndex(array $streams): array
    {
        $rows = array_values(array_filter($streams, 'is_array'));
        usort(
            $rows,
            static fn (array $a, array $b): int =>
                (self::intFrom($a['stream_index'] ?? null) ?? 0) <=> (self::intFrom($b['stream_index'] ?? null) ?? 0)
        );

        return $rows;
    }

    /**
     * Whether a stream row carries a stored default disposition. The base
     * schema has no disposition column, so this accepts either a decoded
     * `disposition` array (ffprobe shape, `{default: 1}`) or a bare numeric
     * flag — and returns false when nothing is stored (the caller then
     * promotes the first track).
     *
     * @param array<string, mixed> $stream
     */
    private static function hasDefaultDisposition(array $stream): bool
    {
        $disposition = $stream['disposition'] ?? null;
        if (is_array($disposition)) {
            $flag = $disposition['default'] ?? 0;

            return is_numeric($flag) && (int) $flag === 1;
        }

        return is_numeric($disposition) && (int) $disposition === 1;
    }

    /**
     * Coerces a row value to an int, or null when not numeric (ffprobe/DB
     * values may arrive as numeric strings).
     */
    private static function intFrom(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Coerces a row value to a non-empty string, or null otherwise.
     */
    private static function nonEmptyString(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * Coerces a stored flag (0/1, "0"/"1", bool) to a boolean.
     */
    private static function isTruthy(mixed $value): bool
    {
        return is_numeric($value) ? (int) $value === 1 : $value === true;
    }
}
