<?php

/**
 * Phlix media server component: Controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc\Controller;

use Phlix\Plugins\OAuth2\CallbackUrl;
use Phlix\Plugins\Oidc\Plugin;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin API controller for OIDC provider settings.
 *
 * Handles saving and loading OIDC configuration.
 *
 * @package Phlix\Plugins\Oidc\Controller
 * @since 0.11.0
 */
final class OidcAdminController
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Get the current OIDC settings.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function getSettings(Request $request, array $params): Response
    {
        $settings = $this->plugin->getSettings();

        unset($settings['client_secret']);

        return (new Response())->json([
            'provider_url' => $settings['provider_url'] ?? '',
            'client_id' => $settings['client_id'] ?? '',
            'scopes' => $settings['scopes'] ?? 'openid profile email',
            // S48 review r1 Finding 1 — the ABSOLUTE callback URL registered with
            // the IdP. Empty = derive it from the request's scheme + Host.
            'redirect_uri' => $settings['redirect_uri'] ?? '',
            'configured' => isset($settings['provider_url']) && isset($settings['client_id']),
        ]);
    }

    /**
     * Save OIDC settings.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function saveSettings(Request $request, array $params): Response
    {
        $body = $request->body;

        $providerUrl = is_string($body['provider_url'] ?? null) ? $body['provider_url'] : '';
        $clientId = is_string($body['client_id'] ?? null) ? $body['client_id'] : '';
        $clientSecret = is_string($body['client_secret'] ?? null) ? $body['client_secret'] : '';
        $scopes = is_string($body['scopes'] ?? null) ? $body['scopes'] : 'openid profile email';

        if ($providerUrl === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_provider_url',
                'message' => 'Provider URL is required',
            ]);
        }

        if ($clientId === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_client_id',
                'message' => 'Client ID is required',
            ]);
        }

        if (!str_starts_with($providerUrl, 'https://') && !str_starts_with($providerUrl, 'http://localhost')) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_provider_url',
                'message' => 'Provider URL must use HTTPS (or localhost for development)',
            ]);
        }

        $existingSettings = $this->plugin->getSettings();

        // S48 review r1 Finding 1 — optional operator-configured ABSOLUTE
        // redirect_uri. It must exactly match a redirect URI registered with the
        // IdP, so a relative path is refused outright rather than silently
        // producing a `redirect_uri_mismatch` at the authorize page.
        //
        // A save is a wholesale replace, so an ABSENT key PRESERVES the stored
        // value (a client that predates this field must not wipe it); an
        // explicitly EMPTY value clears it back to host-derivation.
        $redirectUri = is_string($existingSettings['redirect_uri'] ?? null)
            ? $existingSettings['redirect_uri']
            : '';
        if (array_key_exists('redirect_uri', $body)) {
            $redirectUri = is_string($body['redirect_uri']) ? trim($body['redirect_uri']) : '';
        }
        if ($redirectUri !== '' && !CallbackUrl::isAbsolute($redirectUri)) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_redirect_uri',
                'message' => 'redirect_uri must be an absolute http(s) URL, '
                    . 'e.g. https://phlix.example/auth/oidc/callback',
            ]);
        }

        $settings = [
            'provider_url' => rtrim($providerUrl, '/'),
            'client_id' => $clientId,
            'scopes' => $scopes,
            'redirect_uri' => $redirectUri,
        ];

        if ($clientSecret !== '') {
            $settings['client_secret'] = $clientSecret;
        }

        if (isset($existingSettings['client_secret']) && $clientSecret === '') {
            $settings['client_secret'] = $existingSettings['client_secret'];
        }

        $this->plugin->saveSettings($settings);

        return (new Response())->json([
            'message' => 'Settings saved successfully',
            'configured' => true,
        ]);
    }

    /**
     * Get the settings schema for the admin UI.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return Response
     */
    public function getSchema(Request $request, array $params): Response
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'OIDC Provider Configuration',
            'description' => 'Configuration for the OIDC/OAuth2 authentication provider',
            'type' => 'object',
            'properties' => [
                'provider_url' => [
                    'type' => 'string',
                    'description' => 'The base URL of your OIDC provider (e.g., https://your-provider.com)',
                    'format' => 'uri',
                ],
                'client_id' => [
                    'type' => 'string',
                    'description' => 'The client ID from your OIDC provider',
                ],
                'client_secret' => [
                    'type' => 'string',
                    'description' => 'The client secret from your OIDC provider (leave empty to keep existing)',
                    'writeOnly' => true,
                ],
                'scopes' => [
                    'type' => 'string',
                    'description' => 'OAuth scopes to request',
                    'default' => 'openid profile email',
                ],
                'redirect_uri' => [
                    'type' => 'string',
                    'description' => 'Absolute callback URL registered with the IdP '
                        . '(e.g. https://phlix.example/auth/oidc/callback). '
                        . 'Leave empty to derive it from the request host.',
                    'format' => 'uri',
                ],
            ],
            'required' => ['provider_url', 'client_id'],
        ];

        return (new Response())->json(['schema' => $schema]);
    }
}
