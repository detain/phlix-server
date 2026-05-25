<?php

declare(strict_types=1);

namespace Phlix\Server\WebPortal\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Media\Metadata\ExifProvider;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\PageRenderer;

/**
 * PhotoPageController renders the photo web-portal HTML pages.
 *
 * Serves the browser-facing photo section (date-grouped album grid, album
 * view, single-photo view with EXIF, and the slideshow) using the Smarty
 * templates under `public/templates/photo/`. Thumbnails and full-size images
 * are served by the JSON {@see \Phlix\Server\Http\Controllers\PhotoController}.
 *
 * Albums are synthetic date buckets keyed by `md5(dateKey)` and are scoped to a
 * single library; the album/slideshow routes therefore require a `library_id`
 * query parameter, while the index aggregates across all photo libraries.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Renders photo portal pages (albums/album/photo/slideshow)
 *
 * @see PhotoLibraryManager For date-grouped photo aggregation
 * @see PageRenderer::renderTemplate() For Smarty rendering
 */
class PhotoPageController
{
    /** @var ItemRepository Provides access to media items. */
    private ItemRepository $itemRepo;

    /** @var PhotoLibraryManager Groups photos by date taken. */
    private PhotoLibraryManager $photoManager;

    /** @var ExifProvider Supplies EXIF metadata for a photo. */
    private ExifProvider $exifProvider;

    /** @var LibraryManager Enumerates configured libraries. */
    private LibraryManager $libraryManager;

    /** @var string Absolute path to the Smarty template root. */
    private string $templateDir;

    /**
     * @param ItemRepository      $itemRepo       Media item repository.
     * @param PhotoLibraryManager $photoManager   Date-grouping manager.
     * @param ExifProvider        $exifProvider   EXIF metadata provider.
     * @param LibraryManager      $libraryManager Library enumeration manager.
     * @param string              $templateDir    Absolute path to templates.
     */
    public function __construct(
        ItemRepository $itemRepo,
        PhotoLibraryManager $photoManager,
        ExifProvider $exifProvider,
        LibraryManager $libraryManager,
        string $templateDir
    ) {
        $this->itemRepo = $itemRepo;
        $this->photoManager = $photoManager;
        $this->exifProvider = $exifProvider;
        $this->libraryManager = $libraryManager;
        $this->templateDir = $templateDir;
    }

    /**
     * Renders the date-grouped album grid.
     *
     * GET /photo/albums  (aggregates every photo library)
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the albums page.
     */
    public function albums(Request $request, array $params): Response
    {
        $albums = [];
        foreach ($this->photoLibraryIds() as $libraryId) {
            foreach ($this->photoManager->getPhotosGroupedByDate($libraryId) as $dateKey => $photos) {
                if ($photos === []) {
                    continue;
                }
                $albums[] = [
                    'id' => md5((string) $dateKey),
                    'date' => (string) $dateKey,
                    'photo_count' => count($photos),
                    'cover_photo' => $photos[0],
                    'photos' => $photos,
                ];
            }
        }

        usort($albums, static fn(array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return $this->render('photo/albums.tpl', [
            'current_page' => 'photos',
            'albums' => $albums,
        ]);
    }

    /**
     * Renders a single date album.
     *
     * GET /photo/album/{id}?library_id=
     *
     * @param Request              $request The HTTP request (library_id query).
     * @param array<string,string> $params  Route parameters including 'id'.
     * @return Response HTML response with the album page, 400, or 404.
     */
    public function album(Request $request, array $params): Response
    {
        $albumId = $params['id'] ?? '';
        $libraryId = $request->queryString('library_id', '') ?? '';
        if ($albumId === '' || $libraryId === '') {
            return (new Response())->status(400)->html('<h1>400 — album id and library_id are required</h1>');
        }

        foreach ($this->photoManager->getPhotosGroupedByDate($libraryId) as $dateKey => $photos) {
            if (md5((string) $dateKey) !== $albumId) {
                continue;
            }
            return $this->render('photo/album.tpl', [
                'current_page' => 'photos',
                'album' => [
                    'id' => $albumId,
                    'date' => (string) $dateKey,
                    'photo_count' => count($photos),
                    'photos' => $photos,
                ],
            ]);
        }

        return (new Response())->status(404)->html('<h1>404 — album not found</h1>');
    }

    /**
     * Renders the single-photo view with its EXIF panel.
     *
     * GET /photo/photo/{id}?album_id=&library_id=
     *
     * @param Request              $request The HTTP request (album_id/library_id query).
     * @param array<string,string> $params  Route parameters including 'id'.
     * @return Response HTML response with the photo page, or 404.
     */
    public function photo(Request $request, array $params): Response
    {
        $photoId = $params['id'] ?? '';
        if ($photoId === '') {
            return (new Response())->status(400)->html('<h1>400 — photo id is required</h1>');
        }

        $item = $this->itemRepo->findById($photoId);
        if (!is_array($item) || ($item['type'] ?? '') !== 'photo') {
            return (new Response())->status(404)->html('<h1>404 — photo not found</h1>');
        }

        $exif = $this->exifProvider->getPhotoMetadata($photoId);

        return $this->render('photo/photo.tpl', [
            'current_page' => 'photos',
            'photo' => [
                'id' => $item['id'] ?? $photoId,
                'name' => $item['name'] ?? '',
                'path' => $item['path'] ?? '',
                'metadata' => $exif,
                'exif' => $exif,
            ],
            'album_id' => $request->queryString('album_id', '') ?? '',
            'library_id' => $request->queryString('library_id', '') ?? '',
        ]);
    }

    /**
     * Renders the slideshow page.
     *
     * GET /photo/slideshow?library_id=&album_id=&interval=
     *
     * @param Request              $request The HTTP request (library_id required).
     * @param array<string,string> $params  Route parameters (unused).
     * @return Response HTML response with the slideshow page, or 400.
     */
    public function slideshow(Request $request, array $params): Response
    {
        $libraryId = $request->queryString('library_id', '') ?? '';
        if ($libraryId === '') {
            return (new Response())->status(400)->html('<h1>400 — library_id is required</h1>');
        }
        $albumId = $request->queryString('album_id', '') ?? '';
        $interval = max(1, min(300, $request->queryInt('interval', 5)));

        $photos = [];
        if ($albumId !== '') {
            foreach ($this->photoManager->getPhotosGroupedByDate($libraryId) as $dateKey => $groupPhotos) {
                if (md5((string) $dateKey) === $albumId) {
                    $photos = $groupPhotos;
                    break;
                }
            }
        } else {
            foreach ($this->itemRepo->getByLibrary($libraryId, 10000, 0) as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'photo') {
                    $photos[] = $item;
                }
            }
        }

        $slideshow = [];
        foreach ($photos as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $photoId = is_string($photo['id'] ?? null) ? $photo['id'] : '';
            $metadata = is_array($photo['metadata'] ?? null) ? $photo['metadata'] : [];
            $name = is_string($photo['name'] ?? null) ? $photo['name'] : '';
            $caption = is_string($metadata['camera_model'] ?? null) ? $metadata['camera_model'] : $name;
            if (isset($metadata['date_taken_unix']) && is_numeric($metadata['date_taken_unix'])) {
                $caption .= ' - ' . date('Y-m-d H:i', (int) $metadata['date_taken_unix']);
            }

            $slideshow[] = [
                'id' => $photoId,
                'url' => '/photo/photos/' . $photoId . '/full',
                'thumbnail_url' => '/photo/photos/' . $photoId . '/thumbnail?w=400&h=400&fit=cover',
                'caption' => $caption,
            ];
        }

        return $this->render('photo/slideshow.tpl', [
            'current_page' => 'photos',
            'slideshow' => $slideshow,
            'interval' => $interval,
        ]);
    }

    /**
     * Collects the IDs of all photo-type libraries.
     *
     * @return list<string> Photo library IDs.
     */
    private function photoLibraryIds(): array
    {
        $ids = [];
        foreach ($this->libraryManager->getAllLibraries() as $library) {
            if (!is_array($library) || ($library['type'] ?? null) !== 'photo') {
                continue;
            }
            $id = $library['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Renders a template to an HTML response.
     *
     * @param string              $template Template path relative to the root.
     * @param array<string,mixed> $vars     Variables to assign.
     * @return Response HTML response.
     */
    private function render(string $template, array $vars): Response
    {
        $vars['user'] = $vars['user'] ?? ['display_name' => 'Guest'];
        $html = PageRenderer::renderTemplate($this->templateDir, $template, $vars);
        return (new Response())->html($html);
    }
}
