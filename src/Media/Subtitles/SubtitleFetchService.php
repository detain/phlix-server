<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Subtitles;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\StreamTrackShaper;
use Phlix\Media\Subtitles\Quota\SubtitleProviderQuotaRepository;
use Phlix\Shared\Subtitle\Exception\QuotaExceeded;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Throwable;

/**
 * Host-side, ON-DEMAND subtitle fetch orchestrator (Wave 3 / F3).
 *
 * Given a media item and desired language(s) it {@see search()}es the enabled
 * subtitle-source plugins (held in {@see SubtitleSourceRegistry}) in effective
 * priority order — `subtitles.provider_priority` first, then each source's own
 * weight — deriving search keys from the item (its file path for the primary
 * OSDb-hash/name match, its IMDb id as a fallback) and returning
 * {@see SubtitleCandidate}s. Providers currently known to be out of download
 * quota (persisted in {@see SubtitleProviderQuotaRepository}) are SKIPPED, so
 * search falls through to the next available provider.
 *
 * {@see download()} then fetches ONE chosen candidate, persists it to
 * `/var/subtitles` via {@see SubtitleStorage}, and attaches it as an external
 * `media_streams` subtitle row the player consumes through the existing
 * `subtitle_tracks[]`/`text/vtt` `<track>` contract. On
 * {@see QuotaExceeded} it records the provider's quota state (so subsequent
 * searches skip it) and reports the exhaustion to the caller; on success it
 * clears any recorded exhaustion.
 *
 * RULE-7 SAFETY: with NO subtitle sources registered, {@see search()} returns
 * an empty list and {@see download()} is a no-op error — the whole feature
 * degrades to nothing, exactly like pre-F3 behaviour.
 *
 * All I/O the source plugins perform must be async/non-blocking (the shared
 * {@see \Phlix\Shared\Subtitle\SubtitleSourceInterface} contract requires it);
 * this orchestrator itself performs only DB + local-filesystem work.
 *
 * @package Phlix\Media\Subtitles
 * @since 0.43.0
 */
final class SubtitleFetchService
{
    /**
     * Result discriminant: the download completed and a track was attached.
     */
    public const RESULT_OK = 'ok';

    /**
     * Result discriminant: the media item was not found.
     */
    public const RESULT_ITEM_NOT_FOUND = 'item_not_found';

    /**
     * Result discriminant: no source is registered for the requested provider.
     */
    public const RESULT_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * Result discriminant: the provider's download quota is exhausted.
     */
    public const RESULT_QUOTA_EXCEEDED = 'quota_exceeded';

    /**
     * Result discriminant: the provider download failed for another reason.
     */
    public const RESULT_DOWNLOAD_FAILED = 'download_failed';

    private readonly StructuredLogger $logger;

    public function __construct(
        private readonly SubtitleSourceRegistry $registry,
        private readonly SubtitleStorage $storage,
        private readonly SubtitleProviderQuotaRepository $quota,
        private readonly ItemRepository $items,
        private readonly ?SettingsRepository $settings = null,
        ?StructuredLogger $logger = null,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Search for candidate subtitles for a media item in one or more languages.
     *
     * Walks the registered sources in effective priority order, skipping any
     * provider currently out of download quota, and aggregates their
     * candidates. For each source the PRIMARY path search runs first; only when
     * it yields nothing AND the item carries an IMDb id does the IMDb-id search
     * run as a fallback (mirroring the contract's best-match-first ordering). A
     * throwing source is logged and skipped — one bad provider never aborts the
     * whole search.
     *
     * @param string       $itemId    Media item UUID.
     * @param list<string> $languages ISO 639 language codes, best-preferred first.
     *
     * @return list<array<string, mixed>> Shaped candidate rows (camelCase-friendly),
     *         best-provider first. Empty when the item is unknown or no source is
     *         registered (RULE-7).
     *
     * @since 0.43.0
     */
    public function search(string $itemId, array $languages): array
    {
        $item = $this->items->findById($itemId);
        if ($item === null) {
            return [];
        }

        $sources = $this->registry->byPriority($this->priorityOrder());
        if ($sources === []) {
            return [];
        }

        $path = is_string($item['path'] ?? null) ? $item['path'] : '';
        $imdbId = $this->imdbId($item);

        $candidates = [];
        foreach ($sources as $source) {
            $name = $source->getName();
            if ($this->quota->isExhausted($name)) {
                continue;
            }

            try {
                $found = $path !== '' ? $source->searchByPath($path, $languages) : [];
                if ($found === [] && $imdbId !== null && $imdbId !== '') {
                    $found = $source->searchByImdbId($imdbId, $languages);
                }
            } catch (Throwable $e) {
                $this->logger->warning('subtitle search failed for source — skipping', [
                    'source' => $name,
                    'item' => $itemId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($found as $candidate) {
                if ($candidate instanceof SubtitleCandidate) {
                    $candidates[] = self::shapeCandidate($candidate);
                }
            }
        }

        return $candidates;
    }

    /**
     * Download one chosen candidate, persist it, and attach it as a track.
     *
     * @param string $itemId          Media item UUID.
     * @param string $provider        Source name that produced the candidate.
     * @param string $downloadId      Opaque provider token identifying the file.
     * @param string $language        ISO 639 language code of the subtitle.
     * @param string $format          Candidate format extension (no dot); default `srt`.
     * @param string $releaseName     Provider release/file name (optional).
     * @param bool   $hearingImpaired Whether this is a hearing-impaired (SDH) subtitle.
     *
     * @return array{
     *     status: string,
     *     track?: array<string, mixed>,
     *     downloadsRemaining?: int|null,
     *     resetTimeUtc?: string|null
     * }
     *         `status` is one of the RESULT_* constants; on RESULT_OK a `track`
     *         (the attached subtitle track, shaped like `subtitle_tracks[]`) is
     *         present; on RESULT_QUOTA_EXCEEDED the reported remaining/reset are
     *         present.
     *
     * @since 0.43.0
     */
    public function download(
        string $itemId,
        string $provider,
        string $downloadId,
        string $language,
        string $format = 'srt',
        string $releaseName = '',
        bool $hearingImpaired = false,
    ): array {
        $item = $this->items->findById($itemId);
        if ($item === null) {
            return ['status' => self::RESULT_ITEM_NOT_FOUND];
        }

        // The candidate's identity fields are contractually non-empty; a caller
        // that omits any of them cannot address a real subtitle.
        if ($provider === '' || $downloadId === '' || $language === '') {
            return ['status' => self::RESULT_PROVIDER_UNAVAILABLE];
        }

        $source = $this->registry->get($provider);
        if ($source === null) {
            return ['status' => self::RESULT_PROVIDER_UNAVAILABLE];
        }

        $candidate = new SubtitleCandidate(
            provider: $provider,
            language: $language,
            downloadId: $downloadId,
            releaseName: $releaseName,
            format: $format !== '' ? $format : 'srt',
            hearingImpaired: $hearingImpaired,
        );

        try {
            $file = $source->download($candidate);
        } catch (QuotaExceeded $e) {
            $this->quota->recordQuotaExceeded(
                $provider,
                $e->getDownloadsRemaining(),
                $e->getResetTimeUtc(),
            );
            $this->logger->warning('subtitle provider download quota exceeded', [
                'source' => $provider,
                'item' => $itemId,
                'downloads_remaining' => $e->getDownloadsRemaining(),
                'reset_time_utc' => $e->getResetTimeUtc(),
            ]);

            return [
                'status' => self::RESULT_QUOTA_EXCEEDED,
                'downloadsRemaining' => $e->getDownloadsRemaining(),
                'resetTimeUtc' => $e->getResetTimeUtc(),
            ];
        } catch (Throwable $e) {
            $this->logger->error('subtitle download failed', [
                'source' => $provider,
                'item' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::RESULT_DOWNLOAD_FAILED];
        }

        $storagePath = $this->storage->store($itemId, $file);
        $streamId = $this->items->addExternalSubtitleStream($itemId, [
            'language' => $file->language,
            'codec' => 'webvtt',
            'title' => $file->releaseName,
            'source' => $file->provider,
            'storage_path' => $storagePath,
            'hearing_impaired' => $file->hearingImpaired,
        ]);

        // A completed download proves the provider has quota — clear any
        // recorded exhaustion so subsequent searches use it again.
        $this->quota->recordSuccess($provider);

        return [
            'status' => self::RESULT_OK,
            'track' => $this->attachedTrack($itemId, $streamId, $file),
        ];
    }

    /**
     * The effective `subtitles.provider_priority` list (config default + any
     * admin override), coerced to a clean `list<string>`.
     *
     * Read LIVE via {@see SettingsRepository::getEffective()} — the shared
     * settings JSON schema does NOT (yet) declare this key, but getEffective
     * resolves it straight from the DB override and the `config/subtitles.php`
     * default without needing a schema entry (surfacing it in the admin UI is a
     * phlix-shared follow-up). Any failure degrades to an empty list, so
     * ordering falls back to each source's intrinsic `getPriority()`.
     *
     * @return list<string>
     */
    private function priorityOrder(): array
    {
        if ($this->settings === null) {
            return [];
        }

        try {
            $value = $this->settings->getEffective('subtitles.provider_priority');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Resolve an item's IMDb id from its hydrated metadata, or null.
     *
     * @param array<string, mixed> $item Hydrated media item.
     */
    private function imdbId(array $item): ?string
    {
        $metadata = $item['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        $externalIds = $metadata['external_ids'] ?? null;
        if (is_array($externalIds) && is_string($externalIds['imdb'] ?? null) && $externalIds['imdb'] !== '') {
            return $externalIds['imdb'];
        }

        $flat = $metadata['imdb_id'] ?? null;

        return is_string($flat) && $flat !== '' ? $flat : null;
    }

    /**
     * Re-shape the item's streams and return the freshly-attached external
     * subtitle track (identified by its stream id), so the endpoint hands back
     * exactly the `subtitle_tracks[]` entry the player will later see in
     * playback-info. Falls back to a minimal shape if the row is not found.
     *
     * @param string       $itemId   Media item UUID.
     * @param string       $streamId The attached media_streams row id.
     * @param SubtitleFile $file     The just-downloaded file (fallback shaping source).
     *
     * @return array<string, mixed>
     */
    private function attachedTrack(string $itemId, string $streamId, SubtitleFile $file): array
    {
        $streams = $this->items->getItemStreams($itemId);
        foreach (StreamTrackShaper::subtitleTracks($streams, $itemId) as $track) {
            if (($track['id'] ?? null) === $streamId) {
                return $track;
            }
        }

        // Defensive fallback: the row was just inserted but the read-back missed
        // it (e.g. a commit-visibility/replication race). Shape a synthetic
        // external row for the SAME stream id through the shaper so the response
        // is still a COMPLETE, signed-URL-bearing track — otherwise the client
        // would get a url-less entry, drop it, and show a "added" toast for a
        // track that never appears. `storage_path` need only be non-empty for
        // the shaper to treat the row as external (it does not read the file).
        $synthetic = [[
            'id' => $streamId,
            'stream_type' => 'subtitle',
            'stream_index' => 0,
            'language' => $file->language,
            'title' => $file->releaseName,
            'source' => $file->provider,
            'codec' => 'webvtt',
            'storage_path' => 'pending',
            'hearing_impaired' => $file->hearingImpaired,
        ]];
        foreach (StreamTrackShaper::subtitleTracks($synthetic, $itemId) as $track) {
            return $track;
        }

        return ['id' => $streamId];
    }

    /**
     * Shape a {@see SubtitleCandidate} into a camelCase-friendly array for the
     * search JSON response.
     *
     * @return array<string, mixed>
     */
    private static function shapeCandidate(SubtitleCandidate $candidate): array
    {
        return [
            'provider' => $candidate->provider,
            'language' => $candidate->language,
            'downloadId' => $candidate->downloadId,
            'releaseName' => $candidate->releaseName,
            'format' => $candidate->format,
            'matchedBy' => $candidate->matchedBy,
            'rating' => $candidate->rating,
            'downloadCount' => $candidate->downloadCount,
            'hearingImpaired' => $candidate->hearingImpaired,
            'fps' => $candidate->fps,
        ];
    }
}
