<?php

/**
 * Phlix media server component: Controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Github\Controller;

use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\Github\Plugin;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin API controller for GitHub OAuth provider settings.
 *
 * Reads/writes the DB-backed `plugin_settings` store via {@see Plugin}. Mirrors
 * {@see \Phlix\Plugins\Oidc\Controller\OidcAdminController}: the client secret is
 * never echoed back, and an empty secret on save keeps the existing one.
 *
 * @package Phlix\Plugins\Github\Controller
 * @since 0.102.0
 */
final class GithubAdminController
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * GET current GitHub settings (secret redacted).
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function getSettings(Request $request, array $params): Response
    {
        $settings = $this->plugin->maskSecrets($this->plugin->getSettings());

        return (new Response())->json([
            'client_id' => is_string($settings['client_id'] ?? null) ? $settings['client_id'] : '',
            'scopes' => is_string($settings['scopes'] ?? null) && $settings['scopes'] !== ''
                ? $settings['scopes']
                : GithubOAuthProvider::DEFAULT_SCOPES,
            'configured' => isset($settings['client_id']) && $settings['client_id'] !== '',
        ]);
    }

    /**
     * POST replacement GitHub settings.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function saveSettings(Request $request, array $params): Response
    {
        $body = $request->body;

        $clientId = is_string($body['client_id'] ?? null) ? trim($body['client_id']) : '';
        $clientSecret = is_string($body['client_secret'] ?? null) ? $body['client_secret'] : '';
        $scopes = is_string($body['scopes'] ?? null) && trim($body['scopes']) !== ''
            ? trim($body['scopes'])
            : GithubOAuthProvider::DEFAULT_SCOPES;

        if ($clientId === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_client_id',
                'message' => 'Client ID is required',
            ]);
        }

        $settings = [
            'client_id' => $clientId,
            'scopes' => $scopes,
        ];

        if ($clientSecret !== '') {
            $settings['client_secret'] = $clientSecret;
        }

        // Keep the existing secret when the operator saves without re-entering it.
        $existing = $this->plugin->getSettings();
        if ($clientSecret === '' && isset($existing['client_secret']) && is_string($existing['client_secret'])) {
            $settings['client_secret'] = $existing['client_secret'];
        }

        $this->plugin->saveSettings($settings);

        return (new Response())->json([
            'message' => 'Settings saved successfully',
            'configured' => true,
        ]);
    }

    /**
     * GET the settings JSON schema for the admin UI.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function getSchema(Request $request, array $params): Response
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'GitHub OAuth Provider Configuration',
            'description' => 'Configuration for the GitHub OAuth2 authentication provider',
            'type' => 'object',
            'properties' => [
                'client_id' => [
                    'type' => 'string',
                    'description' => 'The OAuth App client ID from your GitHub developer settings',
                ],
                'client_secret' => [
                    'type' => 'string',
                    'description' => 'The OAuth App client secret (leave empty to keep existing)',
                    'writeOnly' => true,
                ],
                'scopes' => [
                    'type' => 'string',
                    'description' => 'Space-separated OAuth scopes to request',
                    'default' => GithubOAuthProvider::DEFAULT_SCOPES,
                ],
            ],
            'required' => ['client_id'],
        ];

        return (new Response())->json(['schema' => $schema]);
    }
}
