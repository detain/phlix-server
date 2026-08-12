<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\SignedUrl;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Starts on-demand HLS transcode jobs and reports their progress.
 *
 * The web player calls {@see self::start()} when a media item can't be
 * direct-played (incompatible container/codec); it returns the HLS master
 * playlist URL the player feeds to hls.js. {@see self::status()} backs the
 * client's readiness polling while the detached FFmpeg encode warms up.
 */
class TranscodeController
{
    private TranscodeManager $transcodeManager;

    /**
     * Shared parental-control access gate. Null in legacy/no-container contexts,
     * in which case every gate check is a strict no-op (owner-safe).
     */
    private ?RatingGate $ratingGate;

    public function __construct(TranscodeManager $transcodeManager, ?RatingGate $ratingGate = null)
    {
        $this->transcodeManager = $transcodeManager;
        $this->ratingGate = $ratingGate;
    }

    /**
     * Resolve the active profile's parental cap for the current request, or null
     * (the permissive no-op) when the gate is unwired or the profile/account is
     * not capped (owner-safe).
     *
     * S235: an UNIDENTIFIED request is not permissive here — the gate answers
     * {@see RatingGate::denyAll()} for an empty userId, so the guards below fail
     * closed. Both routes that reach this are AuthMiddleware-gated anyway, so
     * that state is unreachable in production; it is the correct default should
     * either ever be made public.
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}|null
     */
    private function resolveRatingFilter(Request $request): ?array
    {
        if ($this->ratingGate === null) {
            return null;
        }

        return $this->ratingGate->resolveFilterForUser($request->userId ?? '');
    }

    /**
     * POST /api/v1/media/{id}/transcode — start (or reuse) an HLS transcode.
     *
     * Accepts an optional `profile` query param (device profile). When the
     * caller passes an explicit non-empty `profile`, it wins (back-compat for
     * clients that still send `?profile=web`). Otherwise the profile is derived
     * from the `X-Phlix-Device-Type` header — thin `@phlix/ui` clients send the
     * header but no `?profile=`, so this gives a Tizen/Roku TV `tv-4k`, mobile
     * `mobile-high`, etc. instead of always defaulting to `web` (1080p). Returns
     * the job id, the HLS master playlist URL, the current status and whether an
     * existing job was reused.
     *
     * SV-3.3: Also accepts `X-Phlix-Client-Capabilities` header (JSON-encoded
     * client decoder capabilities) to force audio transcode when the client
     * cannot decode certain codecs (e.g., no E-AC-3 support).
     *
     * @param array<string, string> $params
     */
    public function start(Request $request, array $params): Response
    {
        $mediaId = $params['id'] ?? '';
        if ($mediaId === '') {
            return (new Response())->status(400)->json(['error' => 'media id is required']);
        }

        // Parental cap (Finding 1a): a capped profile requesting an over-cap item
        // (by EFFECTIVE rating — episodes inherit their series) gets a 404 BEFORE
        // any transcode job is created or any signed HLS/DASH URL is minted, so no
        // playable stream is ever disclosed. No-op for the owner / un-capped
        // profile; fail-CLOSED for an unidentified caller (S235). Mirrors
        // MediaItemController's gate.
        $filter = $this->resolveRatingFilter($request);
        if ($filter !== null && $this->ratingGate !== null && !$this->ratingGate->isAllowed($mediaId, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $explicit = $request->queryString('profile');
        if (is_string($explicit) && $explicit !== '') {
            $profile = $explicit;
        } else {
            $profile = $this->mapDeviceTypeToProfile($request->getHeader('X-Phlix-Device-Type') ?? '');
        }

        // SV-3.3: Parse client capabilities for audio codec decisioning.
        $clientCapsHeader = $request->getHeader('X-Phlix-Client-Capabilities');
        $clientCapabilities = \Phlix\Media\Streaming\ClientCapabilities::fromJson($clientCapsHeader);
        $options = [];
        if ($clientCapabilities->hasExplicitCapabilities()) {
            $options['client_capabilities'] = $clientCapabilities;
        }

        try {
            $job = $this->transcodeManager->ensureHlsJob($mediaId, $profile, $options);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(404)->json(['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            // Concurrency exhausted / launch failure — retryable.
            return (new Response())->status(503)->json(['error' => $e->getMessage()]);
        }

        // Sign the streaming URLs: the player feeds these straight to hls.js /
        // <video>, which can't attach a Bearer header to the initial manifest
        // request. The token is prefix-scoped to the per-job directory, so it
        // authorises every variant playlist and segment too.
        $signer = SignedUrl::fromEnv();
        $sign = static fn (mixed $url): mixed => is_string($url) && $url !== '' ? $signer->mint($url) : $url;

        // Advertise the playable quality ladder so clients can build a picker
        // (D6). Null for a legacy `variants IS NULL` job (explicit key so a client
        // can reliably check `!= null` rather than guess from key absence). Each
        // variant `url` is signed exactly like master_url/hls_url.
        $variants = $this->transcodeManager->getJobVariants($job['job_id']);

        return (new Response())->json([
            'job_id' => $job['job_id'],
            'master_url' => $sign($job['master_url']),
            'hls_url' => $sign($job['hls_url']),
            // S59 restores what S11 removed. It is NOT the unconditional literal
            // S11 deleted: the manager returns null unless the job actually
            // published a `manifest.mpd`, so an mpegts job still advertises no
            // DASH endpoint — and $sign passes null through untouched rather than
            // minting a signature for nothing.
            //
            // ⚠ S60 made `fmp4` the shipped default, and every fMP4 job publishes
            // a manifest. So this key is now POPULATED on an untouched install
            // where it used to be null, and null is left for the
            // `transcoding.segment_format = mpegts` rollback and for jobs created
            // before the flip. That is a visible response change for any client
            // branching on `dash_url != null`.
            'dash_url' => $sign($job['dash_url']),
            'status' => $job['status'],
            'reused' => $job['reused'],
            'subtitles' => self::signSubtitleUrls($job['subtitles'], $sign),
            'variants' => $variants === null ? null : self::signVariantUrls($variants, $sign),
        ]);
    }

    /**
     * Maps an `X-Phlix-Device-Type` header value to a transcode quality profile.
     *
     * Thin `@phlix/ui` clients advertise their platform via the header but don't
     * pass an explicit `?profile=`; this picks a sensible default profile per
     * platform. The lookup is case-insensitive. Anything unknown or empty falls
     * back to `web` (the historical default). Every arm resolves to a profile
     * defined by {@see \Phlix\Media\Streaming\QualitySelector} (generic,
     * mobile-low, mobile-high, web, tv-4k); any arm added later must keep that
     * invariant — the controller test asserts each mapped profile is known.
     *
     * Mapping:
     *   samsung-tizen, tizen, roku → tv-4k
     *   android, ios               → mobile-high
     *   windows                    → generic
     *   (anything else / missing)  → web
     *
     * @param string $deviceType The raw header value (may be empty).
     *
     * @return string A profile name QualitySelector understands.
     */
    private function mapDeviceTypeToProfile(string $deviceType): string
    {
        return match (strtolower(trim($deviceType))) {
            'samsung-tizen', 'tizen', 'roku' => 'tv-4k',
            'android', 'ios' => 'mobile-high',
            'windows' => 'generic',
            default => 'web',
        };
    }

    /**
     * GET /api/v1/transcode/{jobId}/status — report job readiness.
     *
     * @param array<string, string> $params
     */
    public function status(Request $request, array $params): Response
    {
        $jobId = $params['jobId'] ?? ($params['job_id'] ?? '');
        if ($jobId === '') {
            return (new Response())->status(400)->json(['error' => 'job id is required']);
        }

        // Parental cap (Finding 1a): re-resolve the job's media item and deny a
        // capped profile polling an over-cap job — so status() never leaks the
        // signed master/hls/dash/variant/subtitle URLs for content the active
        // profile may not watch. No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        if ($filter !== null && $this->ratingGate !== null) {
            $mediaId = $this->transcodeManager->getJobMediaItemId($jobId);
            if ($mediaId !== null && !$this->ratingGate->isAllowed($mediaId, $filter)) {
                return (new Response())->status(404)->json(['error' => 'Job not found']);
            }
        }

        $readiness = $this->transcodeManager->getJobReadiness($jobId);
        if ($readiness['status'] === 'not_found') {
            return (new Response())->status(404)->json(['error' => 'Job not found']);
        }

        // Signed, prefix-scoped streaming URLs (see start()).
        $signer = SignedUrl::fromEnv();
        $sign = static fn (mixed $url): mixed => is_string($url) && $url !== '' ? $signer->mint($url) : $url;

        // Same quality-ladder advertisement as start() (D6): null for a legacy job,
        // else each variant's own media playlist url signed like master_url.
        $variants = $this->transcodeManager->getJobVariants($readiness['job_id']);

        return (new Response())->json([
            'job_id' => $readiness['job_id'],
            'status' => $readiness['status'],
            'segments' => $readiness['segments'],
            'playlist_ready' => $readiness['playlist_ready'],
            'progress' => $readiness['progress'],
            'master_url' => $sign("/hls/{$readiness['job_id']}/master.m3u8"),
            // Same gate as start(): null unless this job published a manifest.
            'dash_url' => $sign($this->transcodeManager->dashManifestUrl($readiness['job_id'])),
            'subtitles' => self::signSubtitleUrls($readiness['subtitles'], $sign),
            'variants' => $variants === null ? null : self::signVariantUrls($variants, $sign),
        ]);
    }

    /**
     * Signs the `url` of each subtitle track in a subtitle list.
     *
     * Sidecar VTTs are served from the per-job directory under `/hls/{job}/`,
     * which is now gated; the player loads them via a `<track>` element that
     * can't attach a Bearer header. Signing each URL (prefix-scoped to the same
     * job) lets the track load. The list shape is otherwise preserved.
     *
     * @param mixed    $subtitles The raw subtitle list from the transcode manager.
     * @param \Closure $sign      Per-URL signer: `fn(mixed $url): mixed`.
     *
     * @return mixed The subtitle list with each `url` signed.
     */
    private static function signSubtitleUrls(mixed $subtitles, \Closure $sign): mixed
    {
        if (!is_array($subtitles)) {
            return $subtitles;
        }

        return array_map(static function (mixed $track) use ($sign): mixed {
            if (is_array($track) && isset($track['url'])) {
                $track['url'] = $sign($track['url']);
            }

            return $track;
        }, $subtitles);
    }

    /**
     * Signs the `url` of each variant in a playable-variant list.
     *
     * Each variant's `url` is a per-variant media playlist under `/hls/{job}/`
     * (unsigned, relative — {@see TranscodeManager::getJobVariants()} leaves the
     * signing to the controller). hls.js fetches these without a Bearer header,
     * so each is signed with the SAME prefix-scoped signer as `master_url`; the
     * rest of the flat Rendition shape (`id`/`label`/`height`/`bitrate`/`codecs`/…)
     * is preserved untouched.
     *
     * @param list<array<string, mixed>> $variants The unsigned variant list.
     * @param \Closure                   $sign     Per-URL signer: `fn(mixed $url): mixed`.
     *
     * @return list<array<string, mixed>> The variant list with each `url` signed.
     */
    private static function signVariantUrls(array $variants, \Closure $sign): array
    {
        return array_map(static function (array $variant) use ($sign): array {
            if (isset($variant['url'])) {
                $variant['url'] = $sign($variant['url']);
            }

            return $variant;
        }, $variants);
    }
}
