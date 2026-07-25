<?php

/**
 * Phlix media server component: OAuth2.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\OAuth2;

/**
 * Process-local {@see OAuth2StateStore} for tests and for the direct-construction
 * fallback when no DB connection is available.
 *
 * NOT for production under Workerman: a per-worker in-memory store loses state
 * across the resident workers and concurrent requests (see CLAUDE.md "Workerman
 * Session Model"). Production wires {@see DbOAuth2StateStore}.
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
final class InMemoryOAuth2StateStore implements OAuth2StateStore
{
    /** @var array<string, array{code_verifier: string, context?: array<string, mixed>}> */
    private array $entries = [];

    public function put(string $state, string $codeVerifier, ?array $context = null): void
    {
        $entry = ['code_verifier' => $codeVerifier];
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

        return $entry;
    }
}
