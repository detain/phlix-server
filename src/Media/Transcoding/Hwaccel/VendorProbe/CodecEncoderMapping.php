<?php

/**
 * Phlix media server component: VendorProbe.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Transcoding\Hwaccel\VendorProbe;

/**
 * Shared codec→encoder mapping for the vendor probes.
 *
 * Every codec-suffixed probe (VAAPI, QSV, AMF, V4L2, VideoToolbox) parses the
 * same `ffmpeg -encoders` shape and needed the same two derivations — the
 * codec-keyed map that frees rung-time selection from the alphabetical probe
 * order (73a98001), and the primary-codec name that both the default encode
 * target and the display-only `decoder:` field take. The bodies lived
 * byte-identically in all five classes until this extraction; one copy per
 * law, keyed on the using class's own constants.
 *
 * Using classes must define:
 *  - `private const ENCODER_SUFFIX` — the vendor's `<codec>` suffix
 *    (e.g. `_amf`), the same token their `getEncoders()` regex matches;
 *  - `private const PRIMARY_CODEC` — the codec whose encoder the capability
 *    presents as its default (e.g. `h264`).
 *
 * @since 1.2.4
 */
trait CodecEncoderMapping
{
    /**
     * Parses the probed `<codec><suffix>` names into a codec-keyed encoder map
     * so rung-time selection never depends on the alphabetical probe order.
     *
     * @param array<int, string> $encoders
     * @return array<string, string>
     */
    private function mapEncodersByCodec(array $encoders): array
    {
        $by_codec = [];

        foreach ($encoders as $encoder) {
            if (!str_ends_with($encoder, self::ENCODER_SUFFIX)) {
                continue;
            }

            $codec = substr($encoder, 0, -strlen(self::ENCODER_SUFFIX));
            if ($codec === '') {
                continue;
            }

            $by_codec[$codec] = $encoder;
        }

        return $by_codec;
    }

    /**
     * The probe's primary-codec encoder name.
     *
     * Both `encoder:` (the default encode target) and `decoder:` (a
     * display-only field — its sole consumer is the `hwaccel:probe` command's
     * table) take this: the alphabetical `$encoders[0]` is the vendor's AV1
     * name wherever an AV1 encoder exists, so showing it next to an H.264
     * encoder was lying to the operator. The honest first entry remains the
     * fallback only when the probe never saw the primary codec.
     *
     * @param array<string, string> $encoders_by_codec
     * @param array<int, string>    $encoders
     */
    private function primaryCodecEncoder(array $encoders_by_codec, array $encoders): string
    {
        return $encoders_by_codec[self::PRIMARY_CODEC] ?? $encoders[0];
    }
}
