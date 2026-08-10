<?php

/**
 * Phlix media server component: Dash.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

    /** MPD namespace — also the target namespace of the DASH-MPD schema. */
    public const MPD_NAMESPACE = 'urn:mpeg:dash:schema:mpd:2011';

    /**
     * The only DASH profile a per-segment ON-DEMAND pipeline can claim.
     *
     * `isoff-on-demand` requires a single-file Representation described by a
     * `SegmentBase`/`sidx` index, which is impossible when each segment is
     * encoded separately on first request. `isoff-live` is the template-based
     * profile, and it is legal for `type="static"` (VOD) content.
     */
    public const PROFILE_ISOFF_LIVE = 'urn:mpeg:dash:profile:isoff-live:2011';

    /**
     * `MPD@minBufferTime` — the buffer a client needs to play without
     * underrunning at the advertised bandwidths.
     *
     * Mirrors `config/dash.php`'s `min_buffer_time`, which is **not wired to any
     * entrypoint** (`start.php` loads only `config/server.php`); wiring or
     * deleting that file belongs to S59, so the value is a constant here rather
     * than a config read that would look live and never be.
     */
    public const MIN_BUFFER_TIME = 'PT2S';

    /** @var array<int, array{id: int, content_type: string, bandwidth: int}> Cached adaptation set info */
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
     * @return array<int, array{id: int, content_type: string, bandwidth: int}> Cached adaptation sets
     */
    public function getCachedAdaptationSets(): array
    {
        return $this->adaptationSets;
    }

    /**
     * Generates the VOD DASH MPD listing all adaptation sets.
     *
     * S58 rewrote this method. It previously hardcoded
     * `mediaPresentationDuration="PT0H0M0S"` (an empty presentation) and
     * `Period@duration="PT0H1M0S"` (a bogus one minute), so no client could ever
     * have played what it described — hence `$durationSeconds` being a REQUIRED
     * parameter rather than an optional one that defaults back to zero.
     *
     * `Period@duration` is gone: for a single-Period static presentation the
     * MPD-level `mediaPresentationDuration` is the authority, and a second,
     * independently-computed length is just a way for the two to disagree.
     * `Period@start="PT0S"` is stated explicitly because the presentation
     * timeline and the segment numbering both start at zero here.
     *
     * @param string $jobId Transcode job identifier — becomes `MPD@id`.
     * @param list<AdaptationSet> $adaptationSets Adaptation sets, video first.
     * @param float $durationSeconds Total presentation duration; must be > 0.
     *
     * @return string Complete MPD manifest XML content
     *
     * @throws \InvalidArgumentException When the duration is not positive or there are no adaptation sets.
     *
     * @example
     * ```php
     * $template = SegmentTemplate::fromSeconds(
     *     6, 0, 'seg-v$RepresentationID$-$Number%05d$.m4s', 'init-v$RepresentationID$.m4s'
     * );
     * $video = new AdaptationSet(0, 'video', 'video/mp4', $template, [
     *     new Representation('1080p', 'avc1.640029,mp4a.40.2', 5128000, 1920, 1080),
     * ], null, AdaptationSet::ROLE_MAIN);
     * $mpd = $streamer->generateMasterMpd('job-123', [$video], 1234.5);
     * ```
     */
    public function generateMasterMpd(string $jobId, array $adaptationSets, float $durationSeconds): string
    {
        if ($durationSeconds <= 0.0) {
            throw new \InvalidArgumentException('An MPD needs a positive mediaPresentationDuration');
        }
        if ($adaptationSets === []) {
            // A Period with no AdaptationSet is schema-valid and completely
            // unplayable; refusing is better than publishing one, because the
            // file's mere existence would look like a working manifest.
            throw new \InvalidArgumentException('An MPD needs at least one adaptation set');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->xmlStandalone = true;

        $mpd = $doc->createElement('MPD');
        $mpd->setAttribute('xmlns', self::MPD_NAMESPACE);
        $mpd->setAttribute('id', $jobId);
        $mpd->setAttribute('profiles', self::PROFILE_ISOFF_LIVE);
        $mpd->setAttribute('type', 'static');
        $mpd->setAttribute('minBufferTime', self::MIN_BUFFER_TIME);
        $mpd->setAttribute('mediaPresentationDuration', self::xsDuration($durationSeconds));

        $period = $doc->createElement('Period');
        $period->setAttribute('id', '1');
        $period->setAttribute('start', 'PT0S');

        foreach ($adaptationSets as $set) {
            $this->adaptationSets[$set->id] = [
                'id' => $set->id,
                'content_type' => $set->contentType,
                'bandwidth' => $set->maxBandwidth(),
            ];
            $period->appendChild($set->toXml($doc));
        }

        $mpd->appendChild($period);
        $doc->appendChild($mpd);

        $doc->formatOutput = true;
        return $doc->saveXML() ?: '';
    }

    /**
     * Formats a duration in seconds as an `xs:duration`.
     *
     * Rounded UP to the millisecond, never down. The presentation length is what
     * a client uses to decide how many segments the template expands to
     * (`ceil(duration / segmentDuration)`), so a value even a microsecond short
     * of the truth can drop the final, partial segment — the same reasoning that
     * makes the HLS media playlist emit a short last `#EXTINF` rather than
     * truncating.
     *
     * @param float $seconds Duration in seconds; must be > 0.
     */
    public static function xsDuration(float $seconds): string
    {
        return sprintf('PT%.3FS', ceil($seconds * 1000.0) / 1000.0);
    }

    /**
     * Generates a DASH MPD for a specific adaptation set.
     *
     * Creates a standalone MPD document for a single adaptation set,
     * useful for clients that only want one content type.
     *
     * ⚠ **Dead, and wrong — do not build on it.** It has zero production callers
     * and emits a THIRD naming scheme (`$RepresentationID$_$Number%05d$.m4s`,
     * `startNumber="1"`, `duration="6000"` with no `@timescale`) that matches
     * neither what this server writes to disk (`seg-v{id}-NNNNN.m4s`,
     * 0-based) nor what {@see self::generateMasterMpd()} now emits. It is left
     * untouched only because deleting dead DASH code is S59's job; the live VOD
     * manifest comes from `generateMasterMpd()`.
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
     *     sample_rate?: int,
     *     min_buffer_time?: string
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
