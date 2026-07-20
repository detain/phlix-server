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
 * plus the `original` descriptor (a stream-copy passthrough or the top clamped
 * transcode rung, per plan §1 D4). A5 consumes this to emit the HLS master and
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
     * Distinct variants for the HLS master playlist, highest-first.
     *
     * `original` is normally prepended as an additional (highest) variant — a
     * stream-copy passthrough when the source is HLS-safe, else a transcode at
     * source resolution (see {@see AbrLadder::build()}). It is DROPPED in exactly
     * one case: a re-encoded (non-copy) "Original" that is byte-identical to the
     * top ladder rung — same frame at the same source-capped BANDWIDTH (a HEVC
     * source, say, whose "Original" re-encode collapses onto its 1080p rung). The
     * rung already covers it, so advertising both would give ABR two
     * identical-BANDWIDTH variants that a player just merges. A stream-COPY
     * "Original" is never folded: it is a genuinely distinct passthrough
     * rendition (different bytes, no transcode) and remains its own
     * manually-selectable `media_voriginal.m3u8`.
     *
     * @return list<Rendition>
     */
    public function streamVariants(): array
    {
        $top = $this->renditions[0] ?? null;
        if ($top !== null && !$this->original->isCopy && $this->original->duplicatesForAbr($top)) {
            return $this->renditions;
        }

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
