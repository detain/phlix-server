<?php

declare(strict_types=1);

namespace Phlix\Media\Streaming\Dash;

use DOMDocument;
use DOMElement;

/**
 * DASH Streamer - Generates DASH MPD manifests and manages segment files.
 *
 * Handles Dynamic Adaptive Streaming over HTTP (DASH) manifest generation
 * following the DASH-IF Interoperability Points specification. Produces
 * MPD (Media Presentation Description) manifests that list available
 * adaptation sets (video, audio, text) with segment templates.
 *
 * Both HLS and DASH share the same segment files on disk (M4S container).
 * This streamer generates the manifest structure while relying on shared
 * segment storage.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @since 0.11.0
 * @see https://developer.mozilla.org/en-US/docs/Web/Media/DASH_Adaptive_Streaming
 * @see https://dashif.org/specifications/DASH-MPD.pdf
 */
class DashStreamer
{
    /** @var string Directory path where segments are stored */
    private string $segmentDir;

    /** @var string Base URL for streaming endpoints */
    private string $baseUrl;

    /** @var array<string, array{id: string, content_type: string, bandwidth: int}> Cached adaptation set info */
    private array $adaptationSets = [];

    /**
     * Creates a new DASH streamer instance.
     *
     * @param string $segmentDir Base directory for storing segment files
     * @param string $baseUrl Base URL for streaming endpoints
     *
     * @example
     * ```php
     * $streamer = new DashStreamer('/var/segments', 'http://localhost:8096');
     * ```
     */
    public function __construct(string $segmentDir, string $baseUrl)
    {
        $this->segmentDir = $segmentDir;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Gets the cached adaptation sets.
     *
     * Returns the adaptation set metadata cached during the last call to
     * generateMasterMpd(). Useful for clients to discover available
     * adaptation sets without parsing the MPD.
     *
     * @return array<string, array{id: string, content_type: string, bandwidth: int}> Cached adaptation sets
     */
    public function getCachedAdaptationSets(): array
    {
        return $this->adaptationSets;
    }

    /**
     * Generates the DASH MPD master manifest listing all adaptation sets.
     *
     * Creates the root MPD document with DASH-IF live profile and all
     * available adaptation sets for adaptive streaming.
     *
     * @param string $jobId Transcode job identifier
     * @param array<int, AdaptationSet> $adaptationSets Array of adaptation sets
     *
     * @return string Complete MPD manifest XML content
     *
     * @example
     * ```php
     * $videoSet = new AdaptationSet('video-1080', 'video', 'avc1.64001f', 1920, 1080, 5000000);
     * $audioSet = new AdaptationSet('audio-en', 'audio', 'mp4a.40.2', 0, 0, 128000, 48000);
     * $mpd = $streamer->generateMasterMpd('job-123', [$videoSet, $audioSet]);
     * ```
     */
    public function generateMasterMpd(string $jobId, array $adaptationSets): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->xmlStandalone = true;

        $mpd = $doc->createElement('MPD');
        $mpd->setAttribute('xmlns', 'urn:mpeg:dash:schema:mpd:2011');
        $mpd->setAttribute('profiles', 'urn:mpeg:dash:profile:isoff-live:2011');
        $mpd->setAttribute('type', 'static');
        $mpd->setAttribute('minBufferTime', 'PT2S');
        $mpd->setAttribute('mediaPresentationDuration', 'PT0H0M0S');

        $period = $doc->createElement('Period');
        $period->setAttribute('id', '1');
        $period->setAttribute('duration', 'PT0H1M0S');

        foreach ($adaptationSets as $set) {
            $this->adaptationSets[$set->id] = [
                'id' => $set->id,
                'content_type' => $set->contentType,
                'bandwidth' => $set->bandwidth,
            ];
            $period->appendChild($set->toXml($doc));
        }

        $mpd->appendChild($period);
        $doc->appendChild($mpd);

        $doc->formatOutput = true;
        return $doc->saveXML() ?: '';
    }

    /**
     * Generates a DASH MPD for a specific adaptation set.
     *
     * Creates a standalone MPD document for a single adaptation set,
     * useful for clients that only want one content type.
     *
     * @param string $jobId Transcode job identifier
     * @param int $setId Adaptation set index (0-based)
     * @param array<int, array{duration: float, url: string}> $segments Array of segment definitions
     * @param array{
     *     codec?: string,
     *     bandwidth?: int,
     *     width?: int,
     *     height?: int,
     *     content_type?: string,
     *     sample_rate?: int
     * } $params Adaptation set parameters
     *
     * @return string MPD manifest content for the adaptation set
     */
    public function generateAdaptationSetMpd(string $jobId, int $setId, array $segments, array $params): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->xmlStandalone = true;

        $mpd = $doc->createElement('MPD');
        $mpd->setAttribute('xmlns', 'urn:mpeg:dash:schema:mpd:2011');
        $mpd->setAttribute('profiles', 'urn:mpeg:dash:profile:isoff-live:2011');
        $mpd->setAttribute('type', 'static');
        $mpd->setAttribute('minBufferTime', $params['min_buffer_time'] ?? 'PT2S');

        $period = $doc->createElement('Period');
        $period->setAttribute('id', '1');

        $adaptationSet = $doc->createElement('AdaptationSet');
        $adaptationSet->setAttribute('id', (string) $setId);
        $adaptationSet->setAttribute('contentType', $params['content_type'] ?? 'video');

        if (isset($params['bandwidth'])) {
            $adaptationSet->setAttribute('bandwidth', (string) $params['bandwidth']);
        }

        if (isset($params['width']) && isset($params['height'])) {
            $adaptationSet->setAttribute('width', (string) $params['width']);
            $adaptationSet->setAttribute('height', (string) $params['height']);
        }

        if (isset($params['sample_rate'])) {
            $adaptationSet->setAttribute('audioSamplingRate', (string) $params['sample_rate']);
        }

        $segmentTemplate = $doc->createElement('SegmentTemplate');
        $segmentTemplate->setAttribute('media', "\$RepresentationID\$_\$Number%05d\$.m4s");
        $segmentTemplate->setAttribute('initialization', "\$RepresentationID\$_init.m4s");
        $segmentTemplate->setAttribute('startNumber', '1');
        $segmentTemplate->setAttribute('duration', '6000');

        $adaptationSet->appendChild($segmentTemplate);
        $period->appendChild($adaptationSet);
        $mpd->appendChild($period);
        $doc->appendChild($mpd);

        $doc->formatOutput = true;
        return $doc->saveXML() ?: '';
    }

    /**
     * Returns the URL path to the master MPD.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string URL path to the master manifest
     */
    public function getMasterMpdUrl(string $jobId): string
    {
        return "/dash/{$jobId}/manifest.mpd";
    }

    /**
     * Returns the URL path to an adaptation set MPD.
     *
     * @param string $jobId Transcode job identifier
     * @param int $setId Adaptation set ID
     *
     * @return string URL path to the adaptation set manifest
     */
    public function getAdaptationSetMpdUrl(string $jobId, int $setId): string
    {
        return "/dash/{$jobId}/{$setId}/manifest.mpd";
    }

    /**
     * Saves an MPD file to the job directory.
     *
     * Creates the job directory if it doesn't exist and writes the MPD content.
     *
     * @param string $jobId Transcode job identifier
     * @param string $content MPD file content
     * @param string $filename MPD filename (e.g., 'manifest.mpd')
     *
     * @throws \RuntimeException If directory creation or file write fails
     */
    public function saveMpd(string $jobId, string $content, string $filename): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $path = "{$dir}/{$filename}";
        $result = file_put_contents($path, $content);
        if ($result === false) {
            throw new \RuntimeException("Failed to write MPD file: {$path}");
        }
    }

    /**
     * Gets the filesystem path for a DASH segment file (.m4s).
     *
     * @param string $jobId Transcode job identifier
     * @param int $setId Adaptation set ID
     * @param int $segmentNumber Segment number
     *
     * @return string Full filesystem path to the segment
     */
    public function getSegmentPath(string $jobId, int $setId, int $segmentNumber): string
    {
        return "{$this->segmentDir}/{$jobId}/segment_{$setId}_" . sprintf('%05d', $segmentNumber) . ".m4s";
    }

    /**
     * Saves a DASH segment file.
     *
     * @param string $jobId Transcode job identifier
     * @param int $setId Adaptation set ID
     * @param int $segmentNumber Segment number
     * @param string $content Segment content
     *
     * @throws \RuntimeException If file write fails
     */
    public function saveSegment(string $jobId, int $setId, int $segmentNumber, string $content): void
    {
        $path = $this->getSegmentPath($jobId, $setId, $segmentNumber);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $result = file_put_contents($path, $content);
        if ($result === false) {
            throw new \RuntimeException("Failed to write segment file: {$path}");
        }
    }

    /**
     * Cleans up all DASH files for a job.
     *
     * Deletes all MPD and segment files in the job directory.
     *
     * @param string $jobId Transcode job identifier
     */
    public function cleanupJob(string $jobId): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            return;
        }

        $files = glob("{$dir}/*");
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    /**
     * Gets the job directory path.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Full path to the job's segment directory
     */
    public function getJobDirectory(string $jobId): string
    {
        return "{$this->segmentDir}/{$jobId}";
    }

    /**
     * Gets the segment URL path.
     *
     * @param string $jobId Transcode job identifier
     * @param int $setId Adaptation set ID
     * @param int $segmentNumber Segment number
     *
     * @return string Relative URL path to the segment
     */
    public function getSegmentUrl(string $jobId, int $setId, int $segmentNumber): string
    {
        return "/dash/{$jobId}/{$setId}/segment_" . sprintf('%05d', $segmentNumber) . ".m4s";
    }

    /**
     * Gets the absolute segment URL.
     *
     * @param string $jobId Transcode job identifier
     * @param int $setId Adaptation set ID
     * @param int $segmentNumber Segment number
     *
     * @return string Absolute URL to the segment
     */
    public function getSegmentUrlAbsolute(string $jobId, int $setId, int $segmentNumber): string
    {
        $path = $this->getSegmentUrl($jobId, $setId, $segmentNumber);
        return "{$this->baseUrl}{$path}";
    }

    /**
     * Gets the absolute master MPD URL.
     *
     * @param string $jobId Transcode job identifier
     *
     * @return string Absolute URL to the master manifest
     */
    public function getMasterMpdUrlAbsolute(string $jobId): string
    {
        return "{$this->baseUrl}{$this->getMasterMpdUrl($jobId)}";
    }
}
