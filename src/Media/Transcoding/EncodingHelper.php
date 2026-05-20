<?php

declare(strict_types=1);

namespace Phlix\Media\Transcoding;

/**
 * Encoding Helper - Calculates encoding parameters based on source and profile.
 *
 * Analyzes source media information and device capabilities to determine
 * optimal encoding parameters for transcoding operations.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Encoding parameter calculator for FFmpeg transcoding
 * @see FfmpegRunner For command execution
 * @see https://trac.ffmpeg.org/wiki/Encode/H.264
 */
class EncodingHelper
{
    /**
     * CRF values by codec for quality targeting.
     *
     * Lower values mean higher quality but larger file size.
     * - libx264: 18-28 is usable range, 23 is default
     * - libx265: 24-34 is usable range, 28 is default
     * - libvpx-vp9: 24-36 is usable range, 31 is default
     */

    /**
     * Calculates encoding parameters for a transcode operation.
     *
     * Determines if transcoding is needed based on source codec compatibility
     * and device profile constraints, then calculates optimal parameters.
     *
     * @param array{
     *     streams: array<int, array{codec_type: string, codec?: string, width?: int, height?: int, bitrate?: int, channels?: int}>,
     *     format?: array{format_name?: string}
     * } $sourceInfo Source media information from probe
     * @param array{
     *     max_bitrate?: int,
     *     max_resolution?: array<int, int>,
     *     direct_play?: array<string>,
     *     transcode?: array<string>
     * } $profile Device profile with constraints
     * @param array<string, mixed> $options Additional options
     *
     * @return array{
     *     method: string,
     *     video_codec?: string,
     *     audio_codec?: string,
     *     width?: int,
     *     height?: int,
     *     preset?: string,
     *     crf?: int,
     *     audio_bitrate?: string,
     *     audio_channels?: int,
     *     audio_sample_rate?: int,
     *     container?: string,
     *     format?: string
     * } Encoding parameters or direct play info
     *
     * @example
     * ```php
     * $params = $helper->getEncodingParams($sourceInfo, $profile);
     * if ($params['method'] === 'direct') {
     *     // Stream without transcoding
     * } else {
     *     // Use params for transcoding
     * }
     * ```
     */
    public function getEncodingParams(array $sourceInfo, array $profile, array $options = []): array
    {
        $videoStream = $this->getVideoStream($sourceInfo);
        $audioStream = $this->getAudioStream($sourceInfo);

        $params = [];

        $needsTranscode = $this->needsTranscode($videoStream, $audioStream, $profile);

        if (!$needsTranscode) {
            $direct = ['method' => 'direct'];
            if (isset($videoStream['codec'])) {
                $direct['video_codec'] = $videoStream['codec'];
            }
            if (isset($audioStream['codec'])) {
                $direct['audio_codec'] = $audioStream['codec'];
            }
            return $direct;
        }

        $params['method'] = 'transcode';

        $params['video_codec'] = $this->selectVideoCodec($profile, $videoStream);
        $params['preset'] = 'medium';
        $params['crf'] = $this->selectCrf($params['video_codec']);

        $maxRes = $profile['max_resolution'] ?? [1920, 1080];
        $sourceRes = [$videoStream['width'] ?? 1920, $videoStream['height'] ?? 1080];

        if ($sourceRes[0] > $maxRes[0] || $sourceRes[1] > $maxRes[1]) {
            $params['width'] = $maxRes[0];
            $params['height'] = $maxRes[1];
        }

        $params['audio_codec'] = 'aac';
        $params['audio_bitrate'] = $this->selectAudioBitrate($profile);
        $params['audio_channels'] = min($audioStream['channels'] ?? 2, 6);
        $params['audio_sample_rate'] = 48000;

        $params['container'] = 'ts';
        $params['format'] = 'mpegts';

        return $params;
    }

    /**
     * Determines if transcoding is required.
     *
     * Checks video codec support and resolution constraints to decide
     * if direct play is possible.
     *
     * @param array{codec_type?: string, codec?: string, width?: int, height?: int, bitrate?: int, channels?: int} $videoStream Video stream info
     * @param array{codec_type?: string, codec?: string, channels?: int} $audioStream Audio stream info
     * @param array{max_bitrate?: int, max_resolution?: array<int, int>, direct_play?: array<string>, transcode?: array<string>} $profile Device profile
     *
     * @return bool True if transcoding is needed
     */
    private function needsTranscode(array $videoStream, array $audioStream, array $profile): bool
    {
        $videoCodec = strtolower($videoStream['codec'] ?? '');
        $directPlayCodecs = $profile['direct_play'] ?? ['h264', 'h265', 'vp9'];

        if (!in_array($videoCodec, $directPlayCodecs, true)) {
            return true;
        }

        $width = $videoStream['width'] ?? 0;
        $height = $videoStream['height'] ?? 0;
        [$maxWidth, $maxHeight] = $profile['max_resolution'] ?? [1920, 1080];

        if ($width > $maxWidth || $height > $maxHeight) {
            return true;
        }

        return false;
    }

    /**
     * Selects appropriate video codec for transcoding.
     *
     * @param array{max_bitrate?: int, max_resolution?: array<int, int>, direct_play?: array<string>, transcode?: array<string>} $profile Device profile
     * @param array{codec_type?: string, codec?: string, width?: int, height?: int, bitrate?: int, channels?: int} $videoStream Video stream info
     *
     * @return string Selected video codec (libx264, libx265, etc.)
     */
    private function selectVideoCodec(array $profile, array $videoStream): string
    {
        $videoCodec = strtolower($videoStream['codec'] ?? '');
        $transcodeCodecs = $profile['transcode'] ?? ['h264'];

        if ($videoCodec === 'hevc' && !in_array('h264', $transcodeCodecs, true)) {
            return 'libx265';
        }

        return 'libx264';
    }

    /**
     * Selects CRF value based on codec.
     *
     * @param string $codec FFmpeg codec name
     *
     * @return int CRF value for quality targeting
     */
    private function selectCrf(string $codec): int
    {
        return match ($codec) {
            'libx264' => 23,
            'libx265' => 28,
            'libvpx-vp9' => 31,
            default => 23,
        };
    }

    /**
     * Selects audio bitrate based on device profile.
     *
     * @param array{max_bitrate?: int, max_resolution?: array<int, int>, direct_play?: array<string>, transcode?: array<string>} $profile Device profile
     *
     * @return string Audio bitrate (96k, 128k, or 192k)
     */
    private function selectAudioBitrate(array $profile): string
    {
        $maxBitrate = $profile['max_bitrate'] ?? 100000000;

        if ($maxBitrate < 2000000) {
            return '96k';
        } elseif ($maxBitrate < 5000000) {
            return '128k';
        } else {
            return '192k';
        }
    }

    /**
     * Extracts video stream from source info.
     *
     * @param array{streams?: array<int, array{codec_type?: string, codec?: string, width?: int, height?: int, bitrate?: int, channels?: int}>, format?: array{format_name?: string}} $sourceInfo Source media information
     *
     * @return array{codec_type?: string, codec?: string, width?: int, height?: int, bitrate?: int, channels?: int} Video stream data or empty array
     */
    private function getVideoStream(array $sourceInfo): array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                return $stream;
            }
        }
        return [];
    }

    /**
     * Extracts audio stream from source info.
     *
     * @param array{streams?: array<int, array{codec_type?: string, codec?: string, channels?: int}>, format?: array{format_name?: string}} $sourceInfo Source media information
     *
     * @return array{codec_type?: string, codec?: string, channels?: int} Audio stream data or empty array
     */
    private function getAudioStream(array $sourceInfo): array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                return $stream;
            }
        }
        return [];
    }
}
