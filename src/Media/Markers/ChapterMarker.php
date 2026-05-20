<?php

declare(strict_types=1);

namespace Phlix\Media\Markers;

/**
 * Chapter marker DTO representing a scene chapter within a media item.
 *
 * @since 0.12.0
 */
final class ChapterMarker
{
    /**
     * @param int         $start_seconds Chapter start time in seconds
     * @param int         $end_seconds   Chapter end time in seconds
     * @param string|null $title         Optional chapter title
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly int $start_seconds,
        public readonly int $end_seconds,
        public readonly ?string $title = null,
    ) {
    }

    /**
     * Convert to array representation.
     *
     * @return array{start: int, end: int, title?: string|null}
     *
     * @since 0.12.0
     */
    public function toArray(): array
    {
        $result = [
            'start' => $this->start_seconds,
            'end' => $this->end_seconds,
        ];

        if ($this->title !== null) {
            $result['title'] = $this->title;
        }

        return $result;
    }
}
