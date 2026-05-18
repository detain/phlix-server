<?php

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Library\LibraryManager;

class LibraryController
{
    private LibraryManager $libraryManager;

    public function __construct(LibraryManager $libraryManager)
    {
        $this->libraryManager = $libraryManager;
    }

    public function index(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();
        return (new Response())->json(['libraries' => $libraries]);
    }

    public function show(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }
        return (new Response())->json(['library' => $library]);
    }

    public function create(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['name']) || empty($data['type']) || empty($data['paths'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: name, type, paths',
            ]);
        }

        $validTypes = ['movie', 'series', 'music', 'photo', 'book', 'video'];
        if (!in_array($data['type'], $validTypes)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid library type',
                'valid_types' => $validTypes,
            ]);
        }

        $libraryId = $this->libraryManager->createLibrary(
            $data['name'],
            $data['type'],
            $data['paths'],
            $data['options'] ?? []
        );

        return (new Response())->status(201)->json([
            'library_id' => $libraryId,
            'message' => 'Library created successfully',
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $data = $request->body;
        $library = $this->libraryManager->getLibrary($params['id']);

        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->updateLibrary($params['id'], $data);

        return (new Response())->json(['message' => 'Library updated successfully']);
    }

    public function delete(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->deleteLibrary($params['id']);

        return (new Response())->json(['message' => 'Library deleted successfully']);
    }

    public function scan(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->scanLibrary($params['id']);

        return (new Response())->json(['message' => 'Library scan started']);
    }

    public function rescan(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->rescanLibrary($params['id']);

        return (new Response())->json(['message' => 'Library rescan started']);
    }
}
