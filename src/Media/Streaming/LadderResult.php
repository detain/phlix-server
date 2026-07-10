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
     * `original` is ALWAYS prepended as a genuine additional (highest) variant —
     * a stream-copy passthrough when the source is HLS-safe, else a transcode at
     * source resolution (see {@see AbrLadder::build()}). It is never dropped, so
     * the client's "Original" choice always has a real `media_voriginal.m3u8`
     * behind it.
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
