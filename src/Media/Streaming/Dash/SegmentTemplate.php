<?php

/**
 * Phlix media server component: Dash.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming\Dash;

use DOMElement;

/**
 * DASH SegmentTemplate — one `<SegmentTemplate>` element.
 *
 * A SegmentTemplate replaces an explicit per-segment list with a URL pattern the
 * client expands itself, which is what makes an ON-DEMAND segment pipeline
 * expressible in DASH at all: the manifest can describe segments that have not
 * been encoded yet.
 *
 * ⚠ **`@duration` is in TIMESCALE units, not seconds.** MPD's default
 * `@timescale` is **1**, so the pre-S58 code's `duration = seconds * 1000` with
 * no `@timescale` declared a segment length of 6000 **seconds**. Both values are
 * therefore explicit here and {@see self::DEFAULT_TIMESCALE} is emitted always,
 * never left to the schema default.
 *
 * `$startNumber` defaults to **0** because every index this codebase produces is
 * 0-based (`seg-v720p-00000.m4s`, `#EXT-X-MEDIA-SEQUENCE:0`), while DASH's own
 * default is 1. A client that took the DASH default would ask for
 * `seg-v720p-00001.m4s` first and never fetch segment 0 at all.
 *
 * @author Phlix Media Server Team
 * @version 2.0.0
 * @since 0.11.0
 * @see https://dashif.org/specifications/DASH-MPD.pdf
 */
final class SegmentTemplate
{
    /**
     * Ticks per second for `@timescale`.
     *
     * Milliseconds: every segment length this server emits is a whole number of
     * seconds ({@see \Phlix\Media\Transcoding\TranscodeManager}'s
     * `segment_seconds`), so a millisecond timescale represents it exactly while
     * leaving room for a future fractional length.
     */
    public const DEFAULT_TIMESCALE = 1000;

    /**
     * @param int         $duration       Segment length in `$timescale` units (NOT seconds).
     * @param int         $timescale      Ticks per second.
     * @param int         $startNumber    Index of the FIRST segment (0-based here — see the class docblock).
     * @param string      $media          Media-segment URL template (`$RepresentationID$`, `$Number%05d$`).
     * @param string|null $initialization Initialization-segment URL template, or null when there is none.
     */
    public function __construct(
        public readonly int $duration,
        public readonly int $timescale = self::DEFAULT_TIMESCALE,
        public readonly int $startNumber = 0,
        public readonly string $media = '$RepresentationID$_$Number%05d$.m4s',
        public readonly ?string $initialization = null,
    ) {
    }

    /**
     * Builds a SegmentTemplate whose `@duration` is given in whole seconds.
     *
     * The named constructor exists so a caller holding a segment length in
     * seconds (which is how the whole transcode pipeline carries it) cannot
     * accidentally pass seconds into `$duration`, which is in ticks.
     *
     * @param int         $seconds        Segment length in seconds.
     * @param int         $startNumber    Index of the first segment.
     * @param string      $media          Media-segment URL template.
     * @param string|null $initialization Initialization-segment URL template.
     */
    public static function fromSeconds(
        int $seconds,
        int $startNumber,
        string $media,
        ?string $initialization = null,
    ): self {
        return new self(
            $seconds * self::DEFAULT_TIMESCALE,
            self::DEFAULT_TIMESCALE,
            $startNumber,
            $media,
            $initialization,
        );
    }

    /**
     * Converts the SegmentTemplate to a DOMElement for MPD generation.
     *
     * @param \DOMDocument|null $ownerDoc Optional owner document for element creation
     *
     * @return DOMElement The SegmentTemplate XML element
     */
    public function toXml(?\DOMDocument $ownerDoc = null): DOMElement
    {
        $doc = $ownerDoc ?? new \DOMDocument('1.0', 'UTF-8');
        $element = $doc->createElement('SegmentTemplate');

        $element->setAttribute('timescale', (string) $this->timescale);
        $element->setAttribute('duration', (string) $this->duration);
        $element->setAttribute('startNumber', (string) $this->startNumber);
        $element->setAttribute('media', $this->media);

        if ($this->initialization !== null) {
            $element->setAttribute('initialization', $this->initialization);
        }

        return $element;
    }

    /**
     * Gets the initialization URL template.
     *
     * @return string|null The initialization URL template or null
     */
    public function getInitializationTemplate(): ?string
    {
        return $this->initialization;
    }

    /**
     * Gets the media URL template.
     *
     * @return string The media URL template
     */
    public function getMediaTemplate(): string
    {
        return $this->media;
    }

    /**
     * Gets the segment duration in `@timescale` units.
     *
     * @return int Duration in ticks
     */
    public function getDuration(): int
    {
        return $this->duration;
    }

    /**
     * Gets the timescale (ticks per second).
     */
    public function getTimescale(): int
    {
        return $this->timescale;
    }

    /**
     * Gets the starting segment number.
     *
     * @return int Starting segment number
     */
    public function getStartNumber(): int
    {
        return $this->startNumber;
    }
}
