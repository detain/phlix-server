<?php

declare(strict_types=1);

namespace Phlix\Theming;

use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * HTTP middleware that injects active theme CSS/JS into WebPortal responses.
 *
 * This middleware operates on HTML responses rendered by Smarty. It reads
 * the X-Phlix-User-Id header (set by auth middleware earlier in the pipeline)
 * to determine the user's active theme, then injects theme assets by replacing
 * the {$theme_css|raw} and {$theme_js|raw} placeholders in the HTML body.
 *
 * Only HTML responses are modified; other content types pass through unchanged.
 *
 * @package Phlix\Theming
 * @since 0.14.0
 */
class ThemeMiddleware
{
    /**
     * Header name for the authenticated user ID (set by auth middleware).
     */
    public const USER_ID_HEADER = 'X-Phlix-User-Id';

    /**
     * @var ThemeRegistry Registry for looking up theme metadata
     */
    private ThemeRegistry $registry;

    /**
     * @var UserProfileManager For retrieving user's active profile
     */
    private UserProfileManager $profiles;

    /**
     * Creates a new ThemeMiddleware instance.
     *
     * @param ThemeRegistry $registry Theme registry for looking up themes
     * @param UserProfileManager $profiles Profile manager for user preferences
     */
    public function __construct(ThemeRegistry $registry, UserProfileManager $profiles)
    {
        $this->registry = $registry;
        $this->profiles = $profiles;
    }

    /**
     * Intercepts HTTP requests to inject theme assets into HTML responses.
     *
     * @param Request $request The incoming HTTP request
     * @param callable $next The next middleware/handler in the chain
     * @return Response The response, potentially modified with theme assets
     */
    public function onHttpRequest(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Only process HTML responses
        if (!$this->isHtmlResponse($response)) {
            return $response;
        }

        // Get user ID from header (set by auth middleware)
        $userId = $request->headers[self::USER_ID_HEADER] ?? null;

        if ($userId !== null) {
            // Authenticated user - get their active profile's theme
            $theme = $this->getThemeForUser($userId);
        } else {
            // Unauthenticated - use default theme
            $theme = $this->registry->getTheme(ThemeRegistry::DEFAULT_THEME_ID);
        }

        if ($theme === null) {
            return $response;
        }

        // Inject theme assets by replacing Smarty placeholders
        $body = $response->body;

        // Replace CSS placeholder: {$theme_css|raw}
        $cssTag = sprintf(
            '<link rel="stylesheet" href="%s">',
            htmlspecialchars($theme->cssUrl, ENT_QUOTES, 'UTF-8')
        );
        $body = str_replace('{$theme_css|raw}', $cssTag, $body);

        // Replace JS placeholder: {$theme_js|raw}
        if ($theme->jsUrl !== null) {
            $jsTag = sprintf(
                '<script src="%s"></script>',
                htmlspecialchars($theme->jsUrl, ENT_QUOTES, 'UTF-8')
            );
            $body = str_replace('{$theme_js|raw}', $jsTag, $body);
        } else {
            $body = str_replace('{$theme_js|raw}', '', $body);
        }

        $response->body = $body;

        return $response;
    }

    /**
     * Determines if the response contains HTML content.
     *
     * @param Response $response The response to check
     * @return bool True if Content-Type is text/html or application/xhtml+xml
     */
    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers['Content-Type'] ?? '';
        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml');
    }

    /**
     * Gets the active theme for a user based on their active profile.
     *
     * @param string $userId The user identifier
     * @return Theme The active theme for the user's active profile
     */
    private function getThemeForUser(string $userId): Theme
    {
        $profile = $this->profiles->getActiveProfile($userId);

        if (
            $profile !== null
            && isset($profile['active_theme_id'])
            && is_string($profile['active_theme_id'])
        ) {
            $theme = $this->registry->getTheme($profile['active_theme_id']);
            if ($theme !== null) {
                return $theme;
            }
        }

        /** @var Theme */
        $defaultTheme = $this->registry->getTheme(ThemeRegistry::DEFAULT_THEME_ID);

        return $defaultTheme;
    }
}
