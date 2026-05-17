<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Markers;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Markers\SkipButtonSpec;
use Phlex\Media\Markers\MarkerSet;
use Phlex\Media\Markers\IntroMarker;
use Phlex\Media\Markers\OutroMarker;

class SkipButtonSpecTest extends TestCase
{
    public function test_to_array_serializes_all_four_fields(): void
    {
        $spec = new SkipButtonSpec(
            skip_intro_start: 10,
            skip_intro_end: 90,
            skip_outro_start: 2340,
            skip_outro_end: 2520,
        );

        $array = $spec->toArray();

        $this->assertEquals(10, $array['skip_intro_start']);
        $this->assertEquals(90, $array['skip_intro_end']);
        $this->assertEquals(2340, $array['skip_outro_start']);
        $this->assertEquals(2520, $array['skip_outro_end']);
    }

    public function test_null_fields_when_no_marker(): void
    {
        $spec = new SkipButtonSpec(
            skip_intro_start: null,
            skip_intro_end: null,
            skip_outro_start: null,
            skip_outro_end: null,
        );

        $array = $spec->toArray();

        $this->assertNull($array['skip_intro_start']);
        $this->assertNull($array['skip_intro_end']);
        $this->assertNull($array['skip_outro_start']);
        $this->assertNull($array['skip_outro_end']);
    }

    public function test_from_marker_set_maps_correctly(): void
    {
        $markerSet = new MarkerSet(
            intro: new IntroMarker(
                start_seconds: 10,
                end_seconds: 90,
                confidence: 95,
            ),
            outro: new OutroMarker(
                start_seconds: 2340,
                end_seconds: 2520,
                confidence: 88,
            ),
        );

        $spec = SkipButtonSpec::fromMarkerSet($markerSet);

        $this->assertEquals(10, $spec->skip_intro_start);
        $this->assertEquals(90, $spec->skip_intro_end);
        $this->assertEquals(2340, $spec->skip_outro_start);
        $this->assertEquals(2520, $spec->skip_outro_end);
    }

    public function test_from_marker_set_with_no_markers(): void
    {
        $markerSet = MarkerSet::empty();

        $spec = SkipButtonSpec::fromMarkerSet($markerSet);

        $this->assertNull($spec->skip_intro_start);
        $this->assertNull($spec->skip_intro_end);
        $this->assertNull($spec->skip_outro_start);
        $this->assertNull($spec->skip_outro_end);
    }
}
