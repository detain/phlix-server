<?php

/**
 * Phlix media server component: Controller.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Github\Controller;

use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\Github\GithubOAuthProvider;
use Phlix\Plugins\Github\Plugin;
use Phlix\Plugins\OAuth2\CallbackUrl;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin API controller for GitHub OAuth provider settings.
 *
 * Reads/writes the DB-backed `plugin_settings` store via {@see Plugin}. Mirrors
 * {@see \Phlix\Plugins\Oidc\Controller\OidcAdminController}: the client secret is
 * never echoed back, and an empty secret on save keeps the existing one.
 *
 * A successful save immediately REFRESHES the live provider in this worker
 * ({@see AuthProviderBootstrapper::refresh()}); every other worker notices on its
 * next request-path {@see AuthProviderBootstrapper::ensureProviderRegistered()}
 * because that compares the persisted settings fingerprint against the one it
 * built from (review r1 Finding 3).
 *
 * @package Phlix\Plugins\Github\Controller
 * @since 0.102.0
 */
final class GithubAdminController
{
    private Plugin $plugin;

    /**
     * Optional (production DI binds it) so the settings save can rebuild the live
     * provider instead of leaving this worker serving the stale credentials.
     */
    private ?AuthProviderBootstrapper $bootstrapper;

    public function __construct(Plugin $plugin, ?AuthProviderBootstrapper $bootstrapper = null)
    {
        $this->plugin = $plugin;
        $this->bootstrapper = $bootstrapper;
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
        $stored = $this->plugin->getSettings();
        $settings = $this->plugin->maskSecrets($stored);

        return (new Response())->json([
            'client_id' => is_string($settings['client_id'] ?? null) ? $settings['client_id'] : '',
            'scopes' => self::defaultedScopes($settings['scopes'] ?? null),
            // Review r1 Finding 1 — the ABSOLUTE callback URL registered with the
            // GitHub OAuth App. Empty = derive it from the request scheme + Host.
            'redirect_uri' => is_string($settings['redirect_uri'] ?? null) ? $settings['redirect_uri'] : '',
            // Review r1 Finding 12 — `configured` MUST mean the same thing here as
            // in AuthProviderBootstrapper::buildGithubProvider(), otherwise the UI
            // says "configured" and then POST .../github/enable answers 409. A
            // GitHub OAuth App is a confidential client: id AND secret are needed.
            // Computed from the UNMASKED settings (maskSecrets() drops the secret).
            'configured' => self::isConfigured($stored),
        ]);
    }

    /**
     * Normalise a scopes value: a non-blank string wins, anything else falls back
     * to {@see GithubOAuthProvider::DEFAULT_SCOPES}.
     */
    private static function defaultedScopes(mixed $scopes): string
    {
        return is_string($scopes) && trim($scopes) !== ''
            ? trim($scopes)
            : GithubOAuthProvider::DEFAULT_SCOPES;
    }

    /**
     * Whether the stored settings are sufficient to build a live provider —
     * byte-identical to {@see AuthProviderBootstrapper::buildGithubProvider()}'s
     * requirement (Finding 12).
     *
     * @param array<string, mixed> $settings Unmasked stored settings.
     */
    private static function isConfigured(array $settings): bool
    {
        $clientId = is_string($settings['client_id'] ?? null) ? $settings['client_id'] : '';
        $clientSecret = is_string($settings['client_secret'] ?? null) ? $settings['client_secret'] : '';

        return $clientId !== '' && $clientSecret !== '';
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

        if ($clientId === '') {
            return (new Response())->status(400)->json([
                'error' => 'missing_client_id',
                'message' => 'Client ID is required',
            ]);
        }

        $existing = $this->plugin->getSettings();

        // Review r2 NEW-3 — same wholesale-replace trap as `redirect_uri` below: an
        // ABSENT `scopes` key must PRESERVE the operator's custom scopes rather than
        // silently reset them to the default (an older/partial client would
        // otherwise wipe them). Explicitly empty (or non-string) = reset to default.
        $scopes = self::defaultedScopes(
            array_key_exists('scopes', $body) ? $body['scopes'] : ($existing['scopes'] ?? null),
        );

        // Review r1 Finding 1 — an operator-configured redirect_uri must be
        // ABSOLUTE: GitHub compares its scheme/host/port with the OAuth App's
        // registered callback URL, so a relative path always fails there. Refuse
        // it here rather than storing a value that can only produce
        // `redirect_uri_mismatch`.
        //
        // A save is a wholesale replace, so an ABSENT key must PRESERVE the stored
        // value (a client that does not know about this field must not silently
        // wipe it); an explicitly EMPTY value clears it back to host-derivation.
        $redirectUri = is_string($existing['redirect_uri'] ?? null) ? $existing['redirect_uri'] : '';
        if (array_key_exists('redirect_uri', $body)) {
            $redirectUri = is_string($body['redirect_uri']) ? trim($body['redirect_uri']) : '';
        }
        if ($redirectUri !== '' && !CallbackUrl::isAbsolute($redirectUri)) {
            return (new Response())->status(400)->json([
                'error' => 'invalid_redirect_uri',
                'message' => 'redirect_uri must be an absolute http(s) URL, '
                    . 'e.g. https://phlix.example/auth/github/callback',
            ]);
        }

        $settings = [
            'client_id' => $clientId,
            'scopes' => $scopes,
            'redirect_uri' => $redirectUri,
        ];

        if ($clientSecret !== '') {
            $settings['client_secret'] = $clientSecret;
        }

        // Keep the existing secret when the operator saves without re-entering it.
        if ($clientSecret === '' && isset($existing['client_secret']) && is_string($existing['client_secret'])) {
            $settings['client_secret'] = $existing['client_secret'];
        }

        $this->plugin->saveSettings($settings);

        // Review r1 Finding 3 — the registry is per-worker in-memory state and
        // registerProvider() early-returns on hasProvider(), so without this the
        // worker that handled the save would keep authenticating with the OLD
        // credentials until a restart. refresh() drops + rebuilds it here; other
        // workers rebuild on their next ensureProviderRegistered() via the
        // settings-fingerprint check. Never fail an already-persisted save because
        // re-registration hiccuped.
        try {
            $this->bootstrapper?->refresh(AuthProviderBootstrapper::GITHUB);
        } catch (\Throwable $e) {
            LoggerFactory::get(LogChannels::AUTH)->error('GitHub provider refresh after save failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return (new Response())->json([
            'message' => 'Settings saved successfully',
            'configured' => self::isConfigured($settings),
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
                'redirect_uri' => [
                    'type' => 'string',
                    'description' => 'Absolute callback URL registered with the GitHub OAuth App '
                        . '(e.g. https://phlix.example/auth/github/callback). '
                        . 'Leave empty to derive it from the request host.',
                    'format' => 'uri',
                ],
            ],
            'required' => ['client_id', 'client_secret'],
        ];

        return (new Response())->json(['schema' => $schema]);
    }
}
