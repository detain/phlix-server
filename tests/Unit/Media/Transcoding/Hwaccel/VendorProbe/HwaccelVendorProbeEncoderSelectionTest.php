<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel\VendorProbe;

use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\VendorProbe\AmfProbe;
use Phlix\Media\Transcoding\Hwaccel\VendorProbe\QsvProbe;
use Phlix\Media\Transcoding\Hwaccel\VendorProbe\V4L2Probe;
use Phlix\Media\Transcoding\Hwaccel\VendorProbe\VaapiProbe;
use Phlix\Media\Transcoding\Hwaccel\VendorProbe\VideoToolboxProbe;
use PHPUnit\Framework\TestCase;

/**
 * The vendor probes must key the capability on the CODEC-CORRECT encoder,
 * never on the first match of the `ffmpeg -encoders` listing: that listing is
 * alphabetical, so on a full-featured VAAPI box `av1_vaapi` leads
 * (production-verified order on ffmpeg 8.0.1-3ubuntu2: av1_vaapi h264_vaapi
 * hevc_vaapi mjpeg_vaapi mpeg2_vaapi vp8_vaapi vp9_vaapi) and the old
 * `$encoders[0]` selection spliced that AV1 encoder into every rung's
 * `-c:v` while the master playlist advertised avc1/H.264.
 *
 * Each test drives the REAL probe assembly path (probe($ffmpeg_path)) with a
 * fake ffmpeg binary emitting a realistic alphabetical multi-vendor listing;
 * only isAvailable() is overridden so the tests never require a GPU.
 */
final class HwaccelVendorProbeEncoderSelectionTest extends TestCase
{
    /**
     * Representative `ffmpeg -encoders` output in ffmpeg's alphabetical order,
     * covering every vendor block the probes grep for plus decoy lines. The
     * VAAPI block uses the production-verified 8.0.1-3ubuntu2 ordering.
     */
    private const ENC_LISTING = <<<'LISTING'
Encoders:
  V..... = Video
  A..... = Audio
  S..... = Subtitle
  .F.... = Frame-level multithreading
  ..S... = Slice-level multithreading
  ...X.. = Codec is experimental
  ....B. = Supports draw_horiz_band
  .....D = Supports direct rendering method 1
  ------
  V..... a64multi             Multicolor charset for Commodore 64 (codec a64_multi)
  V..... av1_amf              AMD AMF AV1 encoder (codec av1)
  V..... av1_nvenc            NVIDIA NVENC AV1 encoder (codec av1)
  V..... av1_qsv              AV1 (Intel Quick Sync Video acceleration) (codec av1)
  V..... av1_vaapi            AV1 (VAAPI) (codec av1)
  V..... flv1                 flv (Flash Video) (codec flv1)
  V..... h263                 H.263 (codec h263)
  V..... h264_amf             AMD AMF H264 Encoder (codec h264)
  V..... h264_nvenc           NVIDIA NVENC H.264 encoder (codec h264)
  V..... h264_qsv             H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (Intel QSV) (codec h264)
  V..... h264_v4l2m2m         V4L2 m2m(compression offload and memory to memory processing) (codec h264)
  V..... h264_vaapi           H.264/AVC (VAAPI) (codec h264)
  V..... h264_videotoolbox    VideoToolbox H.264 Encoder (codec h264)
  V..... hevc_amf             AMD AMF HEVC encoder (codec hevc)
  V..... hevc_qsv             HEVC (Intel Quick Sync Video acceleration) (codec hevc)
  V..... hevc_v4l2m2m         V4L2 m2m(compression offload and memory to memory processing) (codec hevc)
  V..... hevc_vaapi           H.265/HEVC (VAAPI) (codec hevc)
  V..... hevc_videotoolbox    VideoToolbox HEVC encoder (codec hevc)
  V..... libx264              libx264 H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (codec h264)
  V..... mjpeg_qsv            Motion JPEG (M-JPEG) (Intel Quick Sync Video acceleration) (codec mjpeg)
  V..... mjpeg_vaapi          MJPEG (VAAPI) (codec mjpeg)
  V..... mpeg2_qsv            MPEG-2 video (Intel Quick Sync Video acceleration) (codec mpeg2)
  V..... mpeg2_vaapi          MPEG-2 (VAAPI) (codec mpeg2)
  V..... mpeg2_v4l2m2m        V4L2 m2m(compression offload and memory to memory processing) (codec mpeg2)
  V..... mpeg4_v4l2m2m        V4L2 m2m(compression offload and memory to memory processing) (codec mpeg4)
  V..... prores_videotoolbox  ProRes (VideoToolbox) (codec prores)
  V..... vp8_vaapi            VP8 (VAAPI) (codec vp8)
  V..... vp8_v4l2m2m          V4L2 m2m(compression offload and memory to memory processing) (codec vp8)
  V..... vp9_amf              AMD AMF VP9 encoder (codec vp9)
  V..... vp9_qsv              VP9 video (Intel Quick Sync Video acceleration) (codec vp9)
  V..... vp9_vaapi            VP9 (VAAPI) (codec vp9)
  V..... vp9_videotoolbox     VideoToolbox VP9 encoder (codec vp9)
  ------
LISTING;

    /** @var list<string> scratch files owned by this test, removed in tearDown */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->scratch = [];

        parent::tearDown();
    }

    /**
     * Writes the canned listing plus a one-line shell binary that cats it, and
     * returns the fake ffmpeg path (the probes only ever exec it as
     * `<path> -encoders 2>/dev/null | grep <vendor>`).
     */
    private function fakeFfmpeg(): string
    {
        $listing = tempnam(sys_get_temp_dir(), 'phlix_hwaccel_enc_');
        $binary = tempnam(sys_get_temp_dir(), 'phlix_hwaccel_ffmpeg_');
        $this->assertIsString($listing);
        $this->assertIsString($binary);

        file_put_contents($listing, self::ENC_LISTING . "\n");
        file_put_contents($binary, "#!/bin/sh\ncat " . escapeshellarg($listing) . "\n");
        chmod($binary, 0755);

        $this->scratch[] = $listing;
        $this->scratch[] = $binary;

        return $binary;
    }

    public function test_vaapi_probe_yields_codec_correct_encoders_not_alphabetical_first(): void
    {
        $probe = new class () extends VaapiProbe {
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $capability = $probe->probe($this->fakeFfmpeg());

        $this->assertInstanceOf(HwaccelCapability::class, $capability);
        // The bug witness: the first name in this listing is av1_vaapi.
        $this->assertNotSame('av1_vaapi', $capability->encoder);
        $this->assertSame('h264_vaapi', $capability->encoder);
        $this->assertSame([
            'av1' => 'av1_vaapi',
            'h264' => 'h264_vaapi',
            'hevc' => 'hevc_vaapi',
            'mjpeg' => 'mjpeg_vaapi',
            'mpeg2' => 'mpeg2_vaapi',
            'vp8' => 'vp8_vaapi',
            'vp9' => 'vp9_vaapi',
        ], $capability->encoders_by_codec);
        $this->assertSame('av1_vaapi', $capability->encoderForCodec('av1'));
        $this->assertTrue($capability->supportsCodec('h264'));
        $this->assertTrue($capability->supportsCodec('hevc'));
    }

    public function test_qsv_probe_yields_codec_correct_encoders_not_alphabetical_first(): void
    {
        $probe = new class () extends QsvProbe {
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $capability = $probe->probe($this->fakeFfmpeg());

        $this->assertInstanceOf(HwaccelCapability::class, $capability);
        // The bug witness: the first *_qsv name in this listing is av1_qsv.
        $this->assertNotSame('av1_qsv', $capability->encoder);
        $this->assertSame('h264_qsv', $capability->encoder);
        $this->assertSame('hevc_qsv', $capability->encoderForCodec('hevc'));
        $this->assertSame('av1_qsv', $capability->encoderForCodec('av1'));
    }

    public function test_amf_probe_yields_codec_correct_encoders_not_alphabetical_first(): void
    {
        $probe = new class () extends AmfProbe {
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $capability = $probe->probe($this->fakeFfmpeg());

        $this->assertInstanceOf(HwaccelCapability::class, $capability);
        // The bug witness: the first *_amf name in this listing is av1_amf.
        $this->assertNotSame('av1_amf', $capability->encoder);
        $this->assertSame('h264_amf', $capability->encoder);
        $this->assertSame('hevc_amf', $capability->encoderForCodec('hevc'));
        $this->assertSame('av1_amf', $capability->encoderForCodec('av1'));
    }

    public function test_videotoolbox_probe_yields_codec_correct_encoders(): void
    {
        $probe = new class () extends VideoToolboxProbe {
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $capability = $probe->probe($this->fakeFfmpeg());

        $this->assertInstanceOf(HwaccelCapability::class, $capability);
        $this->assertSame('h264_videotoolbox', $capability->encoder);
        $this->assertSame('hevc_videotoolbox', $capability->encoderForCodec('hevc'));
        $this->assertSame('prores_videotoolbox', $capability->encoderForCodec('prores'));
    }

    public function test_v4l2_probe_yields_codec_correct_encoders(): void
    {
        $probe = new class () extends V4L2Probe {
            public function isAvailable(): bool
            {
                return true;
            }
        };

        $capability = $probe->probe($this->fakeFfmpeg());

        $this->assertInstanceOf(HwaccelCapability::class, $capability);
        $this->assertSame('h264_v4l2m2m', $capability->encoder);
        $this->assertSame('hevc_v4l2m2m', $capability->encoderForCodec('hevc'));
        $this->assertSame('vp8_v4l2m2m', $capability->encoderForCodec('vp8'));
    }
}
