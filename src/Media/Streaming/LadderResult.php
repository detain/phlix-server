<?php

/**
 * Phlix media server component: Streaming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming;

/**
 * LadderResult — the immutable output of {@see AbrLadder::build()}.
 *
 * Carries the ordered quality rungs (`renditions`, highest resolution first)
 * plus the `original` descriptor (a stream-copy passthrough or a transcode at
 * source resolution, per plan §1 D4). A5 consumes this to emit the HLS master and
 * per-variant media playlists; A7 mirrors it into the `variants[]` API payload.
 */
final readonly class LadderResult
{
    /**
     * @param list<Rendition> $renditions Quality rungs, ordered highest resolution first.
     * @param Rendition       $original   The "Original" descriptor (copy passthrough or top rung).
     */
    public function __construct(
        public array $renditions,
        public Rendition $original,
    ) {
    }

    /**
     * The job's STREAM variants — every rendition that gets its own media
     * playlist — highest-first, `original` always first.
     *
     * `original` is ALWAYS prepended as an additional (highest) variant: a
     * stream-copy passthrough when the source is HLS-safe, else a transcode at
     * source resolution (see {@see AbrLadder::build()}). It is never dropped, so
     * every job — including a HEVC/AC-3 one whose "Original" re-encode lands on
     * the same frame and bandwidth as its top rung — always publishes a real,
     * servable `media_voriginal.m3u8` and always offers "Original" in a client's
     * quality picker (S49).
     *
     * S49 HISTORY / DO NOT REINTRODUCE. Until v8 this method FOLDED a re-encoded
     * (non-copy) "Original" whose frame + BANDWIDTH duplicated the top rung,
     * because the master playlist would otherwise advertise two
     * identical-BANDWIDTH `#EXT-X-STREAM-INF` levels (the v7 low-bitrate-collapse
     * defect). The fold cured that at the wrong layer: `writeVodPlaylists()`
     * iterates exactly this list, so folding also meant no media playlist was
     * ever written and "Original" 404'd for every HEVC/non-AAC title. The
     * duplicate-level defect is now handled where it belongs — in
     * {@see \Phlix\Media\Transcoding\TranscodeManager}'s SV-4.6 filter, which
     * excludes BOTH copy and `original` variants from the master's switchable ABR
     * set while still writing each one's media playlist. Do not re-add a filter
     * here: this list decides which variants EXIST, not which are ABR-switchable.
     *
     * @return list<Rendition>
     */
    public function streamVariants(): array
    {
        return array_merge([$this->original], $this->renditions);
    }

    /**
     * Array form for the API/`variants[]` payload.
     *
     * @return array{renditions: list<array<string, mixed>>, original: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'renditions' => array_map(
                static fn (Rendition $rendition): array => $rendition->toArray(),
                $this->renditions,
            ),
            'original' => $this->original->toArray(),
        ];
    }
}
