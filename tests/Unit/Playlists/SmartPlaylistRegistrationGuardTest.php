<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Playlists;

use PHPUnit\Framework\TestCase;

use function file_get_contents;

/**
 * PHANTOM-SURFACE guard: /api/v1/smart-playlists has no live HTTP surface,
 * and nothing in src/ may advertise one.
 *
 * {@see \Phlix\Server\Http\Router::smartPlaylists()} is a registration helper
 * no shipped boot path ever calls, and
 * {@see \Phlix\Playlists\SmartPlaylistController} is DI-bound but unrouted.
 * Both carried docblocks enumerating the six routes as if they were served —
 * the exact shape of the defect that wasted clients against a phantom API
 * (review server__pm M5). The smart story that SHIPPED lives under
 * collections: a collection carrying a smart_playlist_id is refreshed by
 * POST /api/v1/collections/{id}/refresh (owner-or-admin scoped) and by the
 * scan-time SmartPlaylistRefreshSubscriber.
 *
 * These assertions are deliberate source-text scans (the precedent is
 * {@see SmartPlaylistRefreshWiringTest}, which guards the opposite failure —
 * "the call was never ADDED"): the failure guarded here is "a phantom
 * advertise crept back", which no runtime test in this suite can observe.
 * Registering the surface for real is a gated decision — the controller has
 * no per-actor scoping and smart_playlists (migrations/004) no created_by
 * column, so the helper call-site below must only ever appear alongside the
 * migration, openapi/WireGuard cascade, and the flipping of these pins.
 */
final class SmartPlaylistRegistrationGuardTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../..';

    private function source(string $relativePath): string
    {
        $source = file_get_contents(self::REPO_ROOT . '/' . $relativePath);
        $this->assertIsString($source, $relativePath . ' must be readable');

        return $source;
    }

    /**
     * @return array<string, string> every PHP file under src/ keyed by path
     *         relative to the repo root, plus start.php
     */
    private function servedCode(): array
    {
        $root = realpath(self::REPO_ROOT);
        $this->assertIsString($root, 'repo root must resolve');

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = $this->source(
                    'src/' . ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/src'))), '/')
                );
            }
        }
        $this->assertGreaterThan(0, count($files), 'src/ scan must see files (fail loud on path drift)');
        $files[$root . '/start.php'] = $this->source('start.php');

        return $files;
    }

    /**
     * The helper exists to be (someday, lawfully) called — but no boot path
     * calls it. Match the INVOCATION (`->smartPlaylists(`), not a mention:
     * definitions and {@see ...::smartPlaylists()} prose must not count.
     */
    public function test_the_smart_playlists_registration_helper_has_no_call_site(): void
    {
        foreach ($this->servedCode() as $path => $contents) {
            $this->assertStringNotContainsString(
                '->smartPlaylists(',
                $contents,
                'Router::smartPlaylists() is unrouted: registering it is a gated decision '
                . '(created_by migration + per-actor scoping + openapi/WireGuard cascade), '
                . 'found an invocation in ' . $path
            );
        }
    }

    public function test_the_controller_advertises_no_served_routes_and_marks_itself_unrouted(): void
    {
        $source = $this->source('src/Playlists/SmartPlaylistController.php');

        $this->assertStringNotContainsString(
            '/api/v1/smart-playlists',
            $source,
            'The unrouted controller must not enumerate phantom endpoints'
        );
        $this->assertStringContainsString(
            'UNROUTED',
            $source,
            'The controller docblock must state its unrouted status'
        );
    }

    public function test_the_refresh_handler_advertises_no_served_preview_route(): void
    {
        $source = $this->source('src/Playlists/SmartPlaylistRefreshHandler.php');

        $this->assertStringNotContainsString(
            '/api/v1/smart-playlists',
            $source,
            'The scan-time handler must not cite a phantom HTTP preview endpoint'
        );
        $this->assertStringContainsString(
            'unrouted',
            $source,
            'The handler docblock names preview() — it must mark it unrouted'
        );
    }

    public function test_the_refresh_subscriber_marks_its_preview_reference_unrouted(): void
    {
        $source = $this->source('src/Playlists/SmartPlaylistRefreshSubscriber.php');

        $this->assertStringContainsString(
            'unrouted',
            $source,
            'The subscriber must not present preview() as the live on-request evaluator'
        );
    }

    public function test_the_provider_binding_is_marked_unrouted(): void
    {
        $source = $this->source('src/Common/Container/Providers/MediaServicesProvider.php');

        $this->assertMatchesRegularExpression(
            '/UNROUTED(?:[^\n]*\n){1,5}\s*SmartPlaylistController::class/',
            $source,
            'The DI binding of the unrouted controller must be annotated directly above it'
        );
    }

    public function test_the_openapi_spec_advertises_no_smart_playlist_paths(): void
    {
        $source = $this->source('openapi.yaml');

        $this->assertStringNotContainsString(
            'smart-playlists',
            $source,
            'openapi.yaml is the exact bijection of served routes; a smart-playlists path '
            . 'there means a phantom was promoted without the collections-scoping law'
        );
    }
}
