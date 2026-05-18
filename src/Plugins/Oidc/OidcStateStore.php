<?php

declare(strict_types=1);

namespace Phlex\Plugins\Oidc;

/**
 * Server-side store for the OIDC Authorization Code + PKCE flow state.
 *
 * For each in-flight authorize request the controller persists the
 * `(state, code_verifier, nonce)` triple. On callback the matching
 * state is consumed (one-shot) so the verifier can be replayed to the
 * token endpoint and the nonce checked against the ID token.
 *
 * Implementations should bind entries to whichever notion of "user"
 * the host has available — pre-auth that is usually the PHP session
 * cookie or a short-lived UUID handed back to the client via state.
 *
 * @since 0.16.0
 */
interface OidcStateStore
{
    /**
     * Persist a PKCE verifier and the matching nonce keyed by the
     * `state` value that will be echoed back through the OIDC redirect.
     */
    public function put(string $state, string $codeVerifier, string $nonce): void;

    /**
     * One-shot lookup of the entry for a given state. Returns null if
     * the state was never issued or has already been consumed.
     *
     * @return array{code_verifier: string, nonce: string}|null
     */
    public function consume(string $state): ?array;
}
