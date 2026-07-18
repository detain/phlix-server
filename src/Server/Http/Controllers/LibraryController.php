<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Metadata\ImageType;

class LibraryController
{
    private LibraryManager $libraryManager;

    private ScanJobRepository $scanJobs;

    /**
     * Counts items per library so the listing can be enriched with
     * `item_count`. Optional/nullable for back-compat with callers (and unit
     * tests) constructed before this dependency existed; when null the
     * `index()` listing omits `item_count` rather than faking a value.
     */
    private ?ItemRepository $itemRepository;

    private ?AdminMiddleware $adminMiddleware = null;

    public function __construct(
        LibraryManager $libraryManager,
        ScanJobRepository $scanJobs,
        ?ItemRepository $itemRepository = null
    ) {
        $this->libraryManager = $libraryManager;
        $this->scanJobs = $scanJobs;
        $this->itemRepository = $itemRepository;
    }

    /**
     * Set the admin middleware (used for admin-only operations).
     */
    public function setAdminMiddleware(AdminMiddleware $middleware): void
    {
        $this->adminMiddleware = $middleware;
    }

    /**
     * Coerce a loosely-typed request value to a strict boolean.
     *
     * Accepts real bools, ints (1/0), and the strings "1"/"true"/"yes"/"on"
     * (case-insensitive) as true; everything else is false.
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /**
     * Validate and apply a per-library `metadata_priority` override onto the
     * options blob being persisted.
     *
     * A null value or an explicit empty map CLEARS the override — the
     * `metadata_priority` key is removed from `$options` so the library falls
     * back to the global `metadata.provider_priority` default. A well-formed map
     * (media-type string => ordered list of non-empty source-name strings) is
     * sanitised (types/sources trimmed, empty lists dropped) and stored under
     * `metadata_priority`; if every type sanitised away, the key is removed
     * (equivalent to clearing). Source names are NOT restricted — the source
     * list is dynamic (plugins register their own), so only the SHAPE is checked.
     *
     * Returns a `400` {@see Response} when the value is present but malformed
     * (not a map, or a value that is not a list of source-name strings); returns
     * null when applied successfully (including the clear case).
     *
     * @param array<string, mixed> $options Options blob to mutate in place.
     * @param mixed                $raw     Raw `metadata_priority` value from the body.
     */
    private function applyMetadataPriority(array &$options, mixed $raw): ?Response
    {
        // Null / explicit empty map → clear the override (fall back to global).
        if ($raw === null || $raw === []) {
            unset($options['metadata_priority']);
            return null;
        }

        if (!is_array($raw)) {
            return $this->metadataPriorityError();
        }

        $clean = [];
        foreach ($raw as $type => $order) {
            if (!is_string($type) || trim($type) === '') {
                return $this->metadataPriorityError();
            }
            if (!is_array($order)) {
                return $this->metadataPriorityError();
            }
            $list = [];
            foreach ($order as $source) {
                if (!is_string($source)) {
                    return $this->metadataPriorityError();
                }
                $trimmed = trim($source);
                if ($trimmed === '') {
                    return $this->metadataPriorityError();
                }
                $list[] = $trimmed;
            }
            // A type whose list sanitised to empty is dropped (falls back to
            // the global list for that type) rather than stored as empty.
            if ($list !== []) {
                $clean[trim($type)] = $list;
            }
        }

        if ($clean === []) {
            // Everything sanitised away → clear the override entirely.
            unset($options['metadata_priority']);
            return null;
        }

        $options['metadata_priority'] = $clean;
        return null;
    }

    /**
     * The canonical 400 response for a malformed `metadata_priority` value.
     */
    private function metadataPriorityError(): Response
    {
        return (new Response())->status(400)->json([
            'error' => 'metadata_priority must be a map of media type to an ordered list of source names',
        ]);
    }

    /**
     * Validate and apply a per-library `image_types` selection (M5) onto the
     * options blob being persisted.
     *
     * Accepts EITHER a `{type: bool}` map or a `list<string>` of enabled type
     * names; unknown types are dropped by {@see ImageType::normalize()}. The
     * result is stored under `options.image_types` as the canonical
     * `{type: bool}` storage map (every known type present with an explicit
     * on/off state — see {@see ImageType} docs). A `null` value CLEARS the
     * selection (the key is removed → the library falls back to
     * {@see ImageType::defaults()}); an empty list/map is a VALID selection that
     * disables every type (stored, not cleared).
     *
     * Returns a `400` {@see Response} when the value is present but malformed
     * (not an array); returns null when applied successfully (including clear).
     *
     * @param array<string, mixed> $options Options blob to mutate in place.
     * @param mixed                $raw     Raw `image_types` value from the body.
     */
    private function applyImageTypes(array &$options, mixed $raw): ?Response
    {
        // Null → clear the override (fall back to the default image-type set).
        if ($raw === null) {
            unset($options['image_types']);
            return null;
        }

        if (!is_array($raw)) {
            return (new Response())->status(400)->json([
                'error' => 'image_types must be a map of type to boolean, or a list of enabled type names',
            ]);
        }

        // Store the canonical {type: bool} map (every known type present).
        $options['image_types'] = ImageType::toStorageMap($raw);
        return null;
    }

    /**
     * Require authentication for the request.
     */
    private function requireAuth(Request $request): ?Response
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }
        return null;
    }

    /**
     * Require admin access for the request.
     */
    private function requireAdmin(Request $request): ?Response
    {
        // First require auth
        $authResponse = $this->requireAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        // Then check admin status
        if ($this->adminMiddleware !== null) {
            $status = $this->adminMiddleware->checkAccess($request);
            if ($status !== null) {
                return (new Response())->status($status)->json([
                    'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
                    'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
                ]);
            }
        }

        return null;
    }

    /**
     * List all libraries, each enriched with a per-library `item_count`.
     *
     * Mirrors {@see \Phlix\Server\WebPortal\WebPortalRouter::getLibraries()} so
     * both dispatch surfaces return the SAME shape: the Workerman daemon routes
     * `GET /api/v1/libraries` here, while the CGI `public/index.php` path routes
     * it to WebPortalRouter. `item_count` is the number of media items in the
     * library, counted via the item repository when it is wired; when no
     * {@see ItemRepository} was injected (legacy construction) the field is
     * omitted rather than faked.
     *
     * @param array<string, string> $params Route params (unused).
     *
     * @return Response `200` `{ libraries: [ { id, name, type, item_count, ... } ] }`
     *                  · `401` auth-required.
     */
    public function index(Request $request, array $params): Response
    {
        $authResponse = $this->requireAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $libraries = $this->libraryManager->getAllLibraries();

        // Enrich each library with item_count, mirroring
        // WebPortalRouter::getLibraries() exactly (same is_string guards) so the
        // daemon and CGI dispatch paths return an identical shape.
        if ($this->itemRepository !== null) {
            foreach ($libraries as &$lib) {
                $libId = is_string($lib['id'] ?? null) ? $lib['id'] : '';
                $libType = is_string($lib['type'] ?? null) ? $lib['type'] : '';
                $lib['item_count'] = $this->itemRepository->countByType($libId, $libType);
            }
            unset($lib);
        }

        return (new Response())->json(['libraries' => $libraries]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $authResponse = $this->requireAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }
        return (new Response())->json(['library' => $library]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $data = $request->body;

        $name = $data['name'] ?? null;
        $type = $data['type'] ?? null;
        $paths = $data['paths'] ?? null;

        $isValidRequest = is_string($name) && $name !== ''
            && is_string($type) && $type !== ''
            && is_array($paths) && $paths !== [];
        if (!$isValidRequest) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: name, type, paths',
            ]);
        }

        $validTypes = ['movie', 'series', 'music', 'audiobook', 'photo', 'book', 'video'];
        if (!in_array($type, $validTypes, true)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid library type',
                'valid_types' => $validTypes,
            ]);
        }

        $stringPaths = [];
        foreach ($paths as $path) {
            if (is_string($path)) {
                $stringPaths[] = $path;
            }
        }

        $optionsRaw = $data['options'] ?? [];
        $options = [];
        if (is_array($optionsRaw)) {
            foreach ($optionsRaw as $optKey => $optVal) {
                if (is_string($optKey)) {
                    $options[$optKey] = $optVal;
                }
            }
        }

        // `series_per_directory` (boolean, series libraries only): when true the
        // scanner/matcher treat each top-level subdirectory as one series and use
        // the directory name as the authoritative title/year. Accept it at the
        // top level of the body as well as inside `options`, coerce to a real
        // bool, and persist it into the options blob. Ignored for non-series.
        if (array_key_exists('series_per_directory', $data)) {
            $options['series_per_directory'] = $this->toBool($data['series_per_directory']);
        } elseif (array_key_exists('series_per_directory', $options)) {
            $options['series_per_directory'] = $this->toBool($options['series_per_directory']);
        }
        if ($type !== 'series') {
            unset($options['series_per_directory']);
        }

        // `metadata_priority` (per-library provider-priority override): a map of
        // media-type => ordered source-name list, layered OVER the global
        // `metadata.provider_priority` default at match time. Accept it at the
        // body top level OR nested inside `options`, validate the SHAPE (source
        // names are dynamic incl. plugins, so we do NOT restrict which names are
        // allowed), and persist it into the options blob. An explicit null or
        // empty map CLEARS the override (the key is removed → falls back to the
        // global default). The top-level value (when present) wins over a nested
        // one, mirroring series_per_directory.
        if (array_key_exists('metadata_priority', $data)) {
            $priorityError = $this->applyMetadataPriority($options, $data['metadata_priority']);
            if ($priorityError !== null) {
                return $priorityError;
            }
        } elseif (array_key_exists('metadata_priority', $options)) {
            $priorityError = $this->applyMetadataPriority($options, $options['metadata_priority']);
            if ($priorityError !== null) {
                return $priorityError;
            }
        }

        // `image_types` (M5, per-library artwork selection): accept it at the body
        // top level OR nested inside `options`, normalise against the canonical
        // catalogue, and persist the `{type: bool}` storage map into the options
        // blob. Absent → the library falls back to ImageType::defaults() at scan
        // time (no key stored). The top-level value wins over a nested one,
        // mirroring series_per_directory / metadata_priority.
        if (array_key_exists('image_types', $data)) {
            $imageTypesError = $this->applyImageTypes($options, $data['image_types']);
            if ($imageTypesError !== null) {
                return $imageTypesError;
            }
        } elseif (array_key_exists('image_types', $options)) {
            $imageTypesError = $this->applyImageTypes($options, $options['image_types']);
            if ($imageTypesError !== null) {
                return $imageTypesError;
            }
        }

        $libraryId = $this->libraryManager->createLibrary(
            $name,
            $type,
            $stringPaths,
            $options
        );

        // Run the initial scan in the BACKGROUND (the phlix-library-scan worker
        // picks up queued jobs) so create returns immediately instead of
        // blocking the admin form for the whole scan. The UI polls scan-status
        // to show progress.
        $jobId = $this->scanJobs->enqueue($libraryId, 'scan');

        return (new Response())->status(201)->json([
            'library_id' => $libraryId,
            'job_id' => $jobId,
            'status' => 'scanning',
            'message' => 'Library created; initial scan started in the background.',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $data = $request->body;
        $library = $this->libraryManager->getLibrary($params['id']);

        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        // Surface `series_per_directory` on update too, SYMMETRICALLY with
        // create(): accept it at the body top level OR nested inside `options`,
        // coerce to a real bool, and merge it into the existing options blob so
        // the rest of the options are preserved. Only meaningful for series
        // libraries. The raw top-level body value (when present) takes
        // precedence over a nested one, mirroring create().
        $bodyOptions = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        $hasTopLevelFlag = array_key_exists('series_per_directory', $data);
        $hasNestedFlag = array_key_exists('series_per_directory', $bodyOptions);

        if ($hasTopLevelFlag || $hasNestedFlag) {
            $libraryType = is_string($library['type'] ?? null) ? $library['type'] : '';
            if ($libraryType === 'series') {
                $existingOptions = is_array($library['options'] ?? null) ? $library['options'] : [];
                $mergedOptions = [];
                foreach ($existingOptions as $optKey => $optVal) {
                    if (is_string($optKey)) {
                        $mergedOptions[$optKey] = $optVal;
                    }
                }
                // An explicit `options` in the body still wins as the base.
                foreach ($bodyOptions as $optKey => $optVal) {
                    if (is_string($optKey)) {
                        $mergedOptions[$optKey] = $optVal;
                    }
                }
                $rawFlag = $hasTopLevelFlag
                    ? $data['series_per_directory']
                    : $bodyOptions['series_per_directory'];
                $mergedOptions['series_per_directory'] = $this->toBool($rawFlag);
                $data['options'] = $mergedOptions;
            } else {
                // Non-series: strip a nested flag too so a stray value is never
                // stored un-coerced for a library type that ignores it.
                if (isset($data['options']) && is_array($data['options'])) {
                    unset($data['options']['series_per_directory']);
                }
            }
            unset($data['series_per_directory']);
        }

        // Unified options-merge pass for `metadata_priority` (SYMMETRICALLY with
        // create()) and `image_types` (M5). Each may arrive at the body top level
        // OR nested inside `options`. We rebuild the options blob ONCE (existing
        // row overlaid with any explicit body `options`) and then apply BOTH
        // edits to that single blob — so clearing one (null/empty) while editing
        // the other can't resurrect the cleared key from the original row. Each
        // edit: an explicit null / empty map CLEARS its key (falls back to the
        // global default / type defaults). Applies to every library type.
        $bodyOptions = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
        $hasTopLevelPriority = array_key_exists('metadata_priority', $data);
        $hasNestedPriority = array_key_exists('metadata_priority', $bodyOptions);
        $hasTopLevelImages = array_key_exists('image_types', $data);
        $hasNestedImages = array_key_exists('image_types', $bodyOptions);

        if ($hasTopLevelPriority || $hasNestedPriority || $hasTopLevelImages || $hasNestedImages) {
            $existingOptions = is_array($library['options'] ?? null) ? $library['options'] : [];
            $mergedOptions = [];
            foreach ($existingOptions as $optKey => $optVal) {
                if (is_string($optKey)) {
                    $mergedOptions[$optKey] = $optVal;
                }
            }
            // An explicit `options` in the body still wins as the base.
            foreach ($bodyOptions as $optKey => $optVal) {
                if (is_string($optKey)) {
                    $mergedOptions[$optKey] = $optVal;
                }
            }

            if ($hasTopLevelPriority || $hasNestedPriority) {
                $rawPriority = $hasTopLevelPriority
                    ? $data['metadata_priority']
                    : $bodyOptions['metadata_priority'];
                $priorityError = $this->applyMetadataPriority($mergedOptions, $rawPriority);
                if ($priorityError !== null) {
                    return $priorityError;
                }
                unset($data['metadata_priority']);
            }

            if ($hasTopLevelImages || $hasNestedImages) {
                $rawImages = $hasTopLevelImages
                    ? $data['image_types']
                    : $bodyOptions['image_types'];
                $imageTypesError = $this->applyImageTypes($mergedOptions, $rawImages);
                if ($imageTypesError !== null) {
                    return $imageTypesError;
                }
                unset($data['image_types']);
            }

            $data['options'] = $mergedOptions;
        }

        $this->libraryManager->updateLibrary($params['id'], $data);

        return (new Response())->json(['message' => 'Library updated successfully']);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->deleteLibrary($params['id']);

        return (new Response())->json(['message' => 'Library deleted successfully']);
    }

    /**
     * Enqueue an incremental scan for a library (async; Step 1.1b).
     *
     * The scan no longer runs inline on the request — it is queued in
     * `library_scan_jobs` and drained off the HTTP path by
     * {@see \Phlix\Media\Library\LibraryScanWorker}. Returns `202 Accepted`
     * with the job id so the caller can poll {@see self::scanStatus()}.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function scan(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'scan');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Library scan queued',
        ]);
    }

    /**
     * Enqueue a full rescan for a library (async; Step 1.1b).
     *
     * Like {@see self::scan()} but enqueues a `rescan` job (purge + rescan). The
     * work is performed off the HTTP path by the scan worker; returns `202`.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function rescan(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'rescan');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Library rescan queued',
        ]);
    }

    /**
     * Enqueue a background metadata match for a library.
     *
     * Mirrors {@see self::scan()} but enqueues a `metadata` job, which the async
     * {@see \Phlix\Media\Library\LibraryScanWorker} drains off the HTTP path by
     * running {@see \Phlix\Media\Metadata\LibraryMetadataMatcher::matchLibrary()}.
     * The job is recorded in the same `library_scan_jobs` queue, so the existing
     * scan-status badge/polling shows progress unchanged.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function matchMetadata(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'metadata');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Metadata match queued',
        ]);
    }

    /**
     * Enqueue a FORCED background metadata re-match for a library.
     *
     * Mirrors {@see self::matchMetadata()} but enqueues a `metadata_refresh` job,
     * which the async {@see \Phlix\Media\Library\LibraryScanWorker} runs with
     * force-refresh enabled — re-fetching metadata for items that were ALREADY
     * matched (e.g. to backfill newly added fields like per-episode stills). A
     * plain `metadata` job skips already-matched items; this endpoint is the
     * additional force option, not a replacement.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function refreshMetadata(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'metadata_refresh');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Metadata refresh queued',
        ]);
    }

    /**
     * Enqueue a non-destructive prune for a library (`prune` job).
     *
     * Runs ONLY {@see \Phlix\Media\Library\LibraryManager::pruneRemovedItems()}
     * (via {@see \Phlix\Media\Library\LibraryManager::pruneLibrary()}) off the
     * HTTP path — dropping items whose source file is gone, with every per-root
     * presence safety guard intact — WITHOUT a full rescan. Mirrors
     * {@see self::scan()}; returns `202`.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function prune(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'prune');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Library prune queued',
        ]);
    }

    /**
     * Enqueue a metadata reset for a library (`clear_metadata` job).
     *
     * Resets every item to its filesystem-derived basics (NULLs
     * `metadata_refreshed_at`, strips provider-fetched `metadata_json` fields and
     * clears the materialized `content_rating`) while PRESERVING item rows, their
     * path/filename-derived title/type/hierarchy, and all user data / watch
     * history — so a later `metadata` / `metadata_refresh` job re-fetches cleanly.
     * Mirrors {@see self::scan()}; returns `202`.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function clearMetadata(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'clear_metadata');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Metadata clear queued',
        ]);
    }

    /**
     * Enqueue an artwork-cache purge for a library (`clear_artwork` job).
     *
     * Deletes the locally cached artwork for the library's items (freeing disk;
     * the next match re-downloads), leaving user data AND metadata text
     * untouched. Mirrors {@see self::scan()}; returns `202`.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function clearArtwork(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'clear_artwork');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Artwork clear queued',
        ]);
    }

    /**
     * Enqueue a DESTRUCTIVE full item wipe for a library (`delete_all` job).
     *
     * Removes EVERY item in the library (`DELETE FROM media_items WHERE
     * library_id = ?`), which cascades through the `ON DELETE CASCADE` foreign
     * keys into `user_item_data` (watch progress, favorites, ratings) and the
     * watch-history tables — that cascade is the intended, explicit meaning of
     * this op. Because it is irreversible, this endpoint REQUIRES an explicit
     * `confirm` flag (body or query, truthy) and returns `400` without it; the
     * library row itself is kept (only its items are removed).
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `202` `{ job_id, status:"queued", message }` · `400`
     *                  confirmation-missing · `404` library-missing · `401`/`403` auth.
     */
    public function deleteAll(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        // Destructive: require an explicit confirmation (body OR query) so a
        // stray/mis-fired request can never wipe a library. Accept the same
        // truthy tokens as every other boolean flag on this controller.
        $confirm = $this->toBool($request->input('confirm', $request->queryString('confirm')));
        if (!$confirm) {
            return (new Response())->status(400)->json([
                'error' => 'Destructive operation requires explicit confirmation',
                'code' => 'library.delete_all.confirm_required',
                'hint' => 'Resend with confirm=true to delete every item in this library.',
            ]);
        }

        $jobId = $this->scanJobs->enqueue($params['id'], 'delete_all');

        return (new Response())->status(202)->json([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => 'Delete-all items queued (destructive)',
        ]);
    }

    /**
     * Return the latest scan job for a library (Step 1.1b).
     *
     * Powers `GET /api/v1/libraries/{id}/scan-status`. Admin-gated
     * (least-privilege — the job row's `current_path` is a server filesystem
     * path, and the 1.1c progress page is admin-only). A library that has never
     * been scanned yields a valid `200` with `scan_status: null` (NOT a 404 —
     * the library exists, it simply has no jobs yet).
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `200` `{ scan_status: <job row|null> }` · `404`
     *                  library-missing · `401`/`403` auth.
     */
    public function scanStatus(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $job = $this->scanJobs->getLatestForLibrary($params['id']);

        return (new Response())->json(['scan_status' => $job]);
    }

    /**
     * Return the recent scan-job history for a library (Step 1.1b).
     *
     * Powers `GET /api/v1/libraries/{id}/scan-history?limit=N`. Admin-gated for
     * the same reasons as {@see self::scanStatus()}. `limit` defaults to 20 and
     * is clamped to `[1, 100]` by {@see ScanJobRepository::getHistoryForLibrary()}.
     *
     * @param array<string, string> $params Route params; `id` is the library UUID.
     *
     * @return Response `200` `{ history: [<job row>, ...] }` (newest first) ·
     *                  `404` library-missing · `401`/`403` auth.
     */
    public function scanHistory(Request $request, array $params): Response
    {
        $authResponse = $this->requireAdmin($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $limit = $request->queryInt('limit', 20);
        $history = $this->scanJobs->getHistoryForLibrary($params['id'], $limit);

        return (new Response())->json(['history' => $history]);
    }
}
