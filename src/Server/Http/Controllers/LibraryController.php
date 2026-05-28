<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;

class LibraryController
{
    private LibraryManager $libraryManager;

    private ScanJobRepository $scanJobs;

    private ?AdminMiddleware $adminMiddleware = null;

    public function __construct(LibraryManager $libraryManager, ScanJobRepository $scanJobs)
    {
        $this->libraryManager = $libraryManager;
        $this->scanJobs = $scanJobs;
    }

    /**
     * Set the admin middleware (used for admin-only operations).
     */
    public function setAdminMiddleware(AdminMiddleware $middleware): void
    {
        $this->adminMiddleware = $middleware;
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
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $authResponse = $this->requireAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $libraries = $this->libraryManager->getAllLibraries();
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

        $validTypes = ['movie', 'series', 'music', 'photo', 'book', 'video'];
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

        $libraryId = $this->libraryManager->createLibrary(
            $name,
            $type,
            $stringPaths,
            $options
        );

        return (new Response())->status(201)->json([
            'library_id' => $libraryId,
            'message' => 'Library created successfully',
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
