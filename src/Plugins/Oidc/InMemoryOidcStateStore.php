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
 * In-memory fallback implementation of {@see OidcStateStore}.
 *
 * Used only when neither an explicit state store nor a database connection
 * is available. This should only occur in test scenarios or when DI is
 * not properly configured.
 *
 * WARNING: Do not use in production - this does not persist state across
 * requests and will cause race conditions under Workerman.
 *
 * @internal Not for production use.
 * @since 0.16.0
 */
final class InMemoryOidcStateStore implements OidcStateStore
{
    /** @var array<string, array{code_verifier: string, nonce: string, context?: array<string, mixed>}> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier, string $nonce, ?array $context = null): void
    {
        $entry = [
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
        ];
        if ($context !== null && $context !== []) {
            $entry['context'] = $context;
        }
        $this->entries[$state] = $entry;
    }

    public function consume(string $state): ?array
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $entry = $this->entries[$state];
        unset($this->entries[$state]);

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
