<?php

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

    public function put(string $state, string $codeVerifier, string $nonce): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $_SESSION[self::SESSION_KEY][$state] = [
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
        ];
    }

    public function consume(string $state): ?array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return null;
        }
        /** @var array<string, array{code_verifier?: string, nonce?: string}> $bucket */
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

        return [
            'code_verifier' => $verifier,
            'nonce' => $nonce,
        ];
    }
}
