<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Theming\ThemeMediaFinder;
use Phlix\Theming\ThemeMediaRepository;

/**
 * ThemeMediaController handles theme media API endpoints.
 *
 * Provides REST endpoints for getting, scanning, and deleting theme media
 * associated with a library.
 *
 * @since 0.14.0
 */
class ThemeMediaController
{
    /**
     * @param ThemeMediaRepository $repository Theme media repository for caching
     * @param ThemeMediaFinder $finder Theme media finder for filesystem scanning
     * @param LibraryManager $libraryManager Library manager for library data
     *
     * @since 0.14.0
     */
    public function __construct(
        private readonly ThemeMediaRepository $repository,
        private readonly ThemeMediaFinder $finder,
        private readonly LibraryManager $libraryManager
    ) {
    }

    /**
     * Get theme media for a library.
     *
     * GET /api/v1/libraries/{id}/theme-media
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'id' (library ID)
     *
     * @return Response JSON response with theme media or empty object
     *
     * @since 0.14.0
     */
    public function getThemeMedia(Request $request, array $params): Response
    {
        $libraryId = $params['id'] ?? '';

        if (empty($libraryId)) {
            return (new Response())->status(400)->json([
                'error' => 'Library ID is required',
            ]);
        }

        // Check library exists
        $library = $this->libraryManager->getLibrary($libraryId);
        if ($library === null) {
            return (new Response())->status(404)->json([
                'error' => 'Library not found',
            ]);
        }

        // Try to get cached theme media
        $themeMedia = $this->repository->findByLibraryId($libraryId);

        if ($themeMedia === null) {
            return (new Response())->json([
                'library_id' => $libraryId,
                'audio' => null,
                'video' => null,
                'has_theme' => false,
            ]);
        }

        return (new Response())->json([
            'library_id' => $libraryId,
            'audio' => $themeMedia->audio?->toArray(),
            'video' => $themeMedia->video?->toArray(),
            'has_theme' => $themeMedia->hasAudio() || $themeMedia->hasVideo(),
            'scanned_at' => $themeMedia->scannedAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Trigger a rescan of theme media for a library.
     *
     * POST /api/v1/libraries/{id}/theme-media/scan
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'id' (library ID)
     *
     * @return Response JSON response with scan result
     *
     * @since 0.14.0
     */
    public function scanThemeMedia(Request $request, array $params): Response
    {
        $libraryId = $params['id'] ?? '';

        if (empty($libraryId)) {
            return (new Response())->status(400)->json([
                'error' => 'Library ID is required',
            ]);
        }

        // Check library exists and get paths
        $library = $this->libraryManager->getLibrary($libraryId);
        if ($library === null) {
            return (new Response())->status(404)->json([
                'error' => 'Library not found',
            ]);
        }

        // Scan each library path for theme media
        $foundAudio = false;
        $foundVideo = false;

        /** @var mixed $paths */
        $paths = $library['paths'];
        if (is_array($paths)) {
            foreach ($paths as $libraryPath) {
                if (!is_string($libraryPath) || !is_dir($libraryPath)) {
                    continue;
                }

                $themeMedia = $this->finder->findForLibrary($libraryId, $libraryPath);

                if ($themeMedia !== null) {
                    // Cache the result
                    $this->repository->upsert($themeMedia);

                    if ($themeMedia->hasAudio()) {
                        $foundAudio = true;
                    }
                    if ($themeMedia->hasVideo()) {
                        $foundVideo = true;
                    }
                }
            }
        }

        // If no theme media was found, delete any cached entry
        if (!$foundAudio && !$foundVideo) {
            $this->repository->deleteByLibraryId($libraryId);
        }

        return (new Response())->json([
            'library_id' => $libraryId,
            'audio_found' => $foundAudio,
            'video_found' => $foundVideo,
            'has_theme' => $foundAudio || $foundVideo,
        ]);
    }

    /**
     * Delete theme media cache for a library.
     *
     * DELETE /api/v1/libraries/{id}/theme-media
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters including 'id' (library ID)
     *
     * @return Response JSON response confirming deletion
     *
     * @since 0.14.0
     */
    public function deleteThemeMedia(Request $request, array $params): Response
    {
        $libraryId = $params['id'] ?? '';

        if (empty($libraryId)) {
            return (new Response())->status(400)->json([
                'error' => 'Library ID is required',
            ]);
        }

        // Check library exists
        $library = $this->libraryManager->getLibrary($libraryId);
        if ($library === null) {
            return (new Response())->status(404)->json([
                'error' => 'Library not found',
            ]);
        }

        $this->repository->deleteByLibraryId($libraryId);

        return (new Response())->json([
            'library_id' => $libraryId,
            'deleted' => true,
        ]);
    }
}
