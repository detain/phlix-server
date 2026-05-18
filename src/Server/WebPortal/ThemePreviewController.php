<?php

declare(strict_types=1);

namespace Phlex\Server\WebPortal;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Theming\Theme;
use Phlex\Theming\ThemeRegistry;

/**
 * Controller for rendering live theme previews in an iframe sandbox.
 *
 * This controller provides a standalone HTML page that applies a specific
 * theme's CSS to demonstrate how the theme looks when applied to the
 * WebPortal interface. It is used by the Themes tab in the admin UI.
 *
 * @package Phlex\Server\WebPortal
 * @since 0.14.0
 */
class ThemePreviewController
{
    /**
     * @var ThemeRegistry Registry for looking up themes
     */
    private ThemeRegistry $registry;

    /**
     * Creates a new ThemePreviewController instance.
     *
     * @param ThemeRegistry $registry Theme registry for looking up themes
     */
    public function __construct(ThemeRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Handles GET /portal/theme-preview requests.
     *
     * Renders a standalone preview page with the specified theme applied.
     * The page includes a sample layout demonstrating common UI components.
     *
     * @param Request $request The incoming HTTP request
     * @param array<string, string> $params Route parameters (expects 'id' => theme ID)
     * @return Response HTML response with the theme preview
     */
    public function handle(Request $request, array $params): Response
    {
        $themeId = $params['id'] ?? null;

        if ($themeId === null) {
            return (new Response())
                ->status(400)
                ->json(['error' => 'Missing theme ID parameter']);
        }

        $theme = $this->registry->getTheme($themeId);

        if ($theme === null) {
            return (new Response())
                ->status(404)
                ->json(['error' => 'Theme not found: ' . $themeId]);
        }

        return (new Response())
            ->html($this->renderPreviewPage($theme));
    }

    /**
     * Renders the HTML for a theme preview page.
     *
     * @param Theme $theme The theme to preview
     * @return string Complete HTML document
     */
    private function renderPreviewPage(Theme $theme): string
    {
        $cssUrl = htmlspecialchars($theme->cssUrl, ENT_QUOTES, 'UTF-8');
        $themeName = htmlspecialchars($theme->name, ENT_QUOTES, 'UTF-8');
        $darkClass = $theme->dark ? 'theme-dark' : 'theme-light';

        return <<<HTML
<!DOCTYPE html>
<html lang="en" class="{$darkClass}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Preview: {$themeName}</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="{$cssUrl}">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 2rem;
            margin: 0;
        }
        .preview-container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1, h2, h3 {
            margin-top: 1.5rem;
        }
        .card {
            border: 1px solid var(--border-color, #ddd);
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            background: var(--bg-secondary, #f5f5f5);
        }
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            margin: 0.25rem;
            cursor: pointer;
        }
        .btn-primary {
            background: var(--btn-primary-bg, #0066cc);
            color: var(--btn-primary-color, #fff);
        }
        .btn-secondary {
            background: var(--btn-secondary-bg, #6c757d);
            color: var(--btn-secondary-color, #fff);
        }
        input, select {
            padding: 0.5rem;
            border: 1px solid var(--input-border, #ccc);
            border-radius: 4px;
            margin: 0.25rem;
            background: var(--input-bg, #fff);
            color: var(--text-color, #333);
        }
        .media-card {
            display: inline-block;
            width: 150px;
            margin: 0.5rem;
            text-align: center;
        }
        .media-card .thumbnail {
            width: 150px;
            height: 225px;
            background: var(--thumbnail-bg, #333);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 3rem;
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <h1>Theme Preview: {$themeName}</h1>
        <p>This preview demonstrates how the "{$themeName}" theme looks when applied to Phlex.</p>

        <div class="card">
            <h3>Typography</h3>
            <h1>Heading 1</h1>
            <h2>Heading 2</h2>
            <h3>Heading 3</h3>
            <p>Regular body text demonstrating the theme's typography settings.</p>
        </div>

        <div class="card">
            <h3>Buttons</h3>
            <a href="#" class="btn btn-primary">Primary Button</a>
            <a href="#" class="btn btn-secondary">Secondary Button</a>
            <button class="btn btn-primary" disabled>Disabled Button</button>
        </div>

        <div class="card">
            <h3>Form Elements</h3>
            <label>
                Text Input:
                <input type="text" placeholder="Enter text...">
            </label>
            <br>
            <label>
                Select:
                <select>
                    <option>Option 1</option>
                    <option>Option 2</option>
                    <option>Option 3</option>
                </select>
            </label>
        </div>

        <div class="card">
            <h3>Media Cards</h3>
            <div class="media-card">
                <div class="thumbnail">🎬</div>
                <div class="title">Movie Title</div>
                <div class="year">2024</div>
            </div>
            <div class="media-card">
                <div class="thumbnail">📺</div>
                <div class="title">TV Show</div>
                <div class="year">2023</div>
            </div>
            <div class="media-card">
                <div class="thumbnail">🎵</div>
                <div class="title">Music</div>
                <div class="year">2024</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
