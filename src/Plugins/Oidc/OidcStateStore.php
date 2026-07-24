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
     *
     * `$context` is an OPTIONAL server-side envelope carried alongside the
     * verifier/nonce and returned verbatim by {@see consume()}. It is written
     * only by the authorize step and is NEVER exposed to the client — the OIDC
     * `state` query parameter only carries the opaque lookup id. S45 account
     * linking uses it to bind the flow to the initiating (already-authenticated)
     * user: `['intent' => 'link', 'link_user_id' => <uuid>]`. Because it lives
     * server-side (keyed by the one-shot `state`), the callback can trust
     * `link_user_id` — a client cannot forge or alter it the way it could a
     * plain query parameter, which is the linchpin against linking an external
     * identity onto a victim's account.
     *
     * @param array<string, mixed>|null $context Optional integrity-protected,
     *                                           server-side context.
     */
    public function put(string $state, string $codeVerifier, string $nonce, ?array $context = null): void;

    /**
     * One-shot lookup of the entry for a given state. Returns null if
     * the state was never issued or has already been consumed.
     *
     * The `context` key is present only when a non-empty context was supplied to
     * {@see put()}; consumers must treat it as optional.
     *
     * @return array{code_verifier: string, nonce: string, context?: array<string, mixed>}|null
     */
    public function consume(string $state): ?array;
}
