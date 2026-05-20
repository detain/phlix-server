<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Library\LibraryManager;

class LibraryController
{
    private LibraryManager $libraryManager;

    private ?AdminMiddleware $adminMiddleware = null;

    public function __construct(LibraryManager $libraryManager)
    {
        $this->libraryManager = $libraryManager;
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
     * @param array<string, string> $params
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

        $this->libraryManager->scanLibrary($params['id']);

        return (new Response())->json(['message' => 'Library scan started']);
    }

    /**
     * @param array<string, string> $params
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

        $this->libraryManager->rescanLibrary($params['id']);

        return (new Response())->json(['message' => 'Library rescan started']);
    }
}
