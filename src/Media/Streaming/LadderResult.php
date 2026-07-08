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
     * When `original` is a stream-copy passthrough it is a genuine additional
     * (highest) variant and is prepended. When it merely mirrors the top
     * transcode rung (`isCopy === false`) it is omitted here so A5 does not emit
     * a duplicate `#EXT-X-STREAM-INF` — the UI's "Original" choice maps onto the
     * existing top rung instead.
     *
     * @return list<Rendition>
     */
    public function streamVariants(): array
    {
        if ($this->original->isCopy) {
            return array_merge([$this->original], $this->renditions);
        }

        return $this->renditions;
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
