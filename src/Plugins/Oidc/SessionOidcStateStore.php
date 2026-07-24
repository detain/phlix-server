<?php

/**
 * Phlix media server component: Oidc.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

/**
 * `$_SESSION`-backed implementation of {@see OidcStateStore}.
 *
 * Entries are namespaced by the state value so concurrent OIDC flows
 * from the same session do not clobber one another (e.g. multi-tab).
 *
 * @since 0.16.0
 */
final class SessionOidcStateStore implements OidcStateStore
{
    private const SESSION_KEY = 'oidc_pkce_state';

    public function put(string $state, string $codeVerifier, string $nonce, ?array $context = null): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $entry = [
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
        ];
        if ($context !== null && $context !== []) {
            $entry['context'] = $context;
        }
        $_SESSION[self::SESSION_KEY][$state] = $entry;
    }

    public function consume(string $state): ?array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return null;
        }
        /** @var array<string, array{code_verifier?: string, nonce?: string, context?: array<string, mixed>}> $bucket */
        $bucket = $_SESSION[self::SESSION_KEY];
        if (!isset($bucket[$state]) || !is_array($bucket[$state])) {
            return null;
        }

        $entry = $bucket[$state];
        unset($bucket[$state]);
        $_SESSION[self::SESSION_KEY] = $bucket;

        $verifier = is_string($entry['code_verifier'] ?? null) ? $entry['code_verifier'] : '';
        $nonce = is_string($entry['nonce'] ?? null) ? $entry['nonce'] : '';
        if ($verifier === '') {
            return null;
        }

        $result = [
            'code_verifier' => $verifier,
            'nonce' => $nonce,
        ];
        if (isset($entry['context']) && is_array($entry['context'])) {
            $result['context'] = $entry['context'];
        }

        return $result;
    }
}
