<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Subtitles\SubtitleFetchService;
use Phlix\Media\Subtitles\SubtitleStorage;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * ON-DEMAND remote (provider-plugin) subtitle search, download and serving
 * (Wave 3 / F3).
 *
 * A companion to {@see SubtitleController} (which lists + extracts the item's
 * EMBEDDED text tracks). These endpoints reach out to enabled subtitle-source
 * plugins via {@see SubtitleFetchService}:
 *
 *  - `GET  /api/v1/media/{id}/subtitles/search?lang=xx` — list candidates found
 *    across the enabled providers (search consumes NO download quota).
 *  - `POST /api/v1/media/{id}/subtitles/download` — download ONE chosen
 *    candidate, persist it under the subtitle storage root, and attach it as an
 *    external subtitle track; returns that track.
 *  - `GET  /api/v1/media/{id}/subtitles/external/{streamId}` — serve a stored
 *    downloaded subtitle as `text/vtt` (fetched by a `<track>` element, so this
 *    is behind the signed-URL gate like the embedded-track endpoint).
 *
 * ON-DEMAND ONLY: nothing here runs automatically on playback — the client
 * decides when to call search/download (typically when an item has no track in
 * the desired language), so a provider's metered download quota is only ever
 * spent on a subtitle a user actually asked for.
 */
class RemoteSubtitleController
{
    public function __construct(
        private SubtitleFetchService $fetchService,
        private ItemRepository $itemRepository,
        private SubtitleStorage $storage,
    ) {
    }

    /**
     * GET /api/v1/media/{id}/subtitles/search?lang=xx[,yy] — candidate list.
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $itemId = $params['id'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Missing media id']);
        }

        $languages = $this->parseLanguages($request->queryString('lang'));
        $candidates = $this->fetchService->search($itemId, $languages);

        return (new Response())->json(['candidates' => $candidates]);
    }

    /**
     * POST /api/v1/media/{id}/subtitles/download — download + attach one candidate.
     *
     * Body: `{provider, downloadId, language, format?, releaseName?, hearingImpaired?}`.
     *
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $itemId = $params['id'] ?? '';
        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Missing media id']);
        }

        $provider = $this->stringInput($request, 'provider');
        $downloadId = $this->stringInput($request, 'downloadId');
        $language = $this->stringInput($request, 'language');
        if ($provider === '' || $downloadId === '' || $language === '') {
            return (new Response())->status(400)->json([
                'error' => 'provider, downloadId and language are required',
            ]);
        }

        $format = $this->stringInput($request, 'format');
        $releaseName = $this->stringInput($request, 'releaseName');
        $hearingImpaired = (bool) $request->input('hearingImpaired', false);

        $result = $this->fetchService->download(
            $itemId,
            $provider,
            $downloadId,
            $language,
            $format !== '' ? $format : 'srt',
            $releaseName,
            $hearingImpaired,
        );

        return $this->mapDownloadResult($result);
    }

    /**
     * GET /api/v1/media/{id}/subtitles/external/{streamId} — serve stored WebVTT.
     *
     * @param array<string, string> $params
     */
    public function serveExternal(Request $request, array $params): Response
    {
        $itemId = $params['id'] ?? '';
        $streamId = $params['streamId'] ?? '';
        if ($itemId === '' || $streamId === '') {
            return (new Response())->status(404)->json(['error' => 'Subtitle not found']);
        }

        $row = $this->itemRepository->getStreamById($streamId);
        if (
            !is_array($row)
            || ($row['media_item_id'] ?? null) !== $itemId
            || !is_string($row['storage_path'] ?? null)
            || $row['storage_path'] === ''
        ) {
            return (new Response())->status(404)->json(['error' => 'Subtitle not found']);
        }

        $vtt = $this->storage->read($row['storage_path']);
        if ($vtt === null) {
            return (new Response())->status(404)->json(['error' => 'Subtitle not available']);
        }

        return (new Response())
            ->header('Content-Type', 'text/vtt; charset=utf-8')
            ->body($vtt);
    }

    /**
     * Map a {@see SubtitleFetchService} download result to an HTTP response.
     *
     * @param array{
     *     status: string,
     *     track?: array<string, mixed>,
     *     downloadsRemaining?: int|null,
     *     resetTimeUtc?: string|null
     * } $result
     */
    private function mapDownloadResult(array $result): Response
    {
        return match ($result['status'] ?? '') {
            SubtitleFetchService::RESULT_OK =>
                (new Response())->json(['track' => $result['track'] ?? []]),
            SubtitleFetchService::RESULT_ITEM_NOT_FOUND =>
                (new Response())->status(404)->json(['error' => 'Item not found']),
            SubtitleFetchService::RESULT_PROVIDER_UNAVAILABLE =>
                (new Response())->status(404)->json(['error' => 'Subtitle provider not available']),
            SubtitleFetchService::RESULT_QUOTA_EXCEEDED =>
                (new Response())->status(429)->json([
                    'error' => 'Subtitle provider download quota exceeded',
                    'downloadsRemaining' => $result['downloadsRemaining'] ?? null,
                    'resetTimeUtc' => $result['resetTimeUtc'] ?? null,
                ]),
            default =>
                (new Response())->status(502)->json(['error' => 'Subtitle download failed']),
        };
    }

    /**
     * Parse a `lang` query value (`en` or `en,es`) into a clean language list.
     *
     * @return list<string>
     */
    private function parseLanguages(?string $lang): array
    {
        if ($lang === null || trim($lang) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $lang) as $code) {
            $code = trim($code);
            if ($code !== '') {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * Read a request body field as a trimmed string ('' when absent/non-scalar).
     */
    private function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
