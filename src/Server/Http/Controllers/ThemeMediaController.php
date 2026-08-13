<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Middleware\AdminMiddleware;
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
     * @param AdminMiddleware $adminMiddleware Admin gate for the two mutation
     *        endpoints ({@see self::scanThemeMedia()},
     *        {@see self::deleteThemeMedia()}). REQUIRED — see below.
     *
     * ## S323 — the admin gate is a construction-time requirement
     *
     * This used to be `private ?AdminMiddleware $adminMiddleware = null;` filled
     * by an OPTIONAL `setAdminMiddleware()` setter, with each mutation handler
     * wrapping its decision in `if ($this->adminMiddleware !== null)`. That
     * combination **failed OPEN**, and worse than the sibling controllers S282
     * fixed: the check here is INLINE with no `requireAuth()` in front of it, so
     * a controller built without the setter served `POST …/theme-media/scan` and
     * `DELETE …/theme-media` to an **ANONYMOUS** caller, not merely to any
     * logged-in one. The live wiring did call the setter, so the hole was latent
     * — but "latent" is a property of today's wiring, not of the class, and
     * PHP-DI's `autowire()` SKIPS optional parameters, which is how this estate
     * has produced silently-null dependencies before.
     *
     * Making the dependency REQUIRED removes the null state entirely: the
     * handlers have no null branch left to take, and a construction that omits
     * the gate is an `ArgumentCountError` at the `new`, not a security downgrade
     * at request time.
     *
     * Do NOT re-introduce a nullable type, a default value, or a setter.
     * `tests/Unit/Server/Http/Controllers/ThemeMediaControllerAdminGateIsStructuralTest.php`
     * fails on any of the three.
     *
     * @since 0.14.0
     */
    public function __construct(
        private readonly ThemeMediaRepository $repository,
        private readonly ThemeMediaFinder $finder,
        private readonly LibraryManager $libraryManager,
        private readonly AdminMiddleware $adminMiddleware
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
     * Admin-only. S323: there is deliberately NO
     * `if ($this->adminMiddleware !== null)` guard around the check — the
     * middleware is a required constructor dependency, so the gate is
     * unconditional and this handler can only run its body after an admin
     * decision was actually taken. Re-adding a null guard would restore the
     * S323 fail-open, which on this handler is ANONYMOUS access.
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
        $status = $this->adminMiddleware->checkAccess($request);
        if ($status !== null) {
            return (new Response())->status($status)->json([
                'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
                'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
            ]);
        }

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
     * Admin-only. S323: the check is unconditional for the same reason as
     * {@see self::scanThemeMedia()} — see that docblock.
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
        $status = $this->adminMiddleware->checkAccess($request);
        if ($status !== null) {
            return (new Response())->status($status)->json([
                'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
                'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
            ]);
        }

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
