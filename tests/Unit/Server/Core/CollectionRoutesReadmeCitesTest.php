<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review server__docmaint SRV-M2: the README HTTP-endpoints table promises
 * "each registration's exact line is cited" for the collection routes, yet
 * all ten numeric cites sat ~13 lines above the real registrations — a
 * single insertion above loadCollectionRoutes() had shifted the block and
 * nothing re-anchored. This test IS that anchor: every citation in the
 * collection/playlist table rows must land on the exact `$r->…` line it
 * names. Any future insertion into Application.php that moves these routes
 * reddens this pin, forcing the README to re-anchor in the same change.
 *
 * @package Phlix\Tests\Unit\Server\Core
 */
final class CollectionRoutesReadmeCitesTest extends TestCase
{
    private const README = __DIR__ . '/../../../../README.md';

    private const APPLICATION = __DIR__ . '/../../../../src/Server/Core/Application.php';

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function citedRouteRows(): array
    {
        return [
            'show' => ['| GET | `/api/v1/collections/{id}` |', [
                "\$r->get('/api/v1/collections/{id}', [\$controller, 'show'])",
            ]],
            'for-library' => ['| GET | `/api/v1/libraries/{libraryId}/collections` |', [
                "\$r->get('/api/v1/libraries/{libraryId}/collections', [\$controller, 'forLibrary'])",
            ]],
            'create+alias' => ['| POST | `/api/v1/collections` |', [
                "\$r->post('/api/v1/collections', [\$controller, 'create'])",
                "\$r->post('/api/v1/playlists', [\$controller, 'create'])",
            ]],
            'update' => ['| PUT | `/api/v1/collections/{id}` |', [
                "\$r->put('/api/v1/collections/{id}', [\$controller, 'update'])",
            ]],
            'delete' => ['| DELETE | `/api/v1/collections/{id}` |', [
                "\$r->delete('/api/v1/collections/{id}', [\$controller, 'delete'])",
            ]],
            'add-item' => ['| POST | `/api/v1/collections/{id}/items/{mediaItemId}` |', [
                "\$r->post('/api/v1/collections/{id}/items/{mediaItemId}', [\$controller, 'addItem'])",
            ]],
            'remove-item' => ['| DELETE | `/api/v1/collections/{id}/items/{mediaItemId}` |', [
                "\$r->delete('/api/v1/collections/{id}/items/{mediaItemId}', [\$controller, 'removeItem'])",
            ]],
            'bulk-add' => ['| POST | `/api/v1/collections/{id}/bulk-add` |', [
                "\$r->post('/api/v1/collections/{id}/bulk-add', [\$controller, 'bulkAdd'])",
            ]],
            'refresh' => ['| POST | `/api/v1/collections/{id}/refresh` |', [
                "\$r->post('/api/v1/collections/{id}/refresh', [\$controller, 'refresh'])",
            ]],
        ];
    }

    /**
     * @param array<int, string> $fragments
     */
    #[DataProvider('citedRouteRows')]
    public function test_the_cited_lines_are_the_named_registrations(string $needle, array $fragments): void
    {
        $readme = (string) file_get_contents(self::README);
        $appLines = explode("\n", (string) file_get_contents(self::APPLICATION));

        $row = null;
        foreach (explode("\n", $readme) as $line) {
            if (str_contains($line, $needle)) {
                self::assertNull($row, "README table row needle '{$needle}' is not unique; the pin would go blind.");
                $row = $line;
            }
        }
        self::assertNotNull(
            $row,
            "README collection-route row '{$needle}' vanished — re-point this pin deliberately."
        );

        preg_match_all('/\(`:(\d+)`\)/', $row, $matches);
        $cites = array_map('intval', $matches[1]);
        self::assertSame(
            count($fragments),
            count($cites),
            "row '{$needle}' must cite exactly " . count($fragments)
            . ' registration line(s), in order.'
        );

        foreach ($fragments as $i => $fragment) {
            $line = $cites[$i];
            self::assertArrayHasKey(
                $line - 1,
                $appLines,
                "cite :{$line} for '{$fragment}' is past the end of Application.php"
            );
            self::assertStringContainsString(
                $fragment,
                $appLines[$line - 1],
                "README cites :{$line} for {$fragment} but that line holds something else — "
                . 'the table drifted; re-anchor the cite (SRV-M2 law).'
            );
        }
    }
}
