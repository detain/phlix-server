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
 * Server-side store for a plain-OAuth2 Authorization Code + PKCE flow's state.
 *
 * For each in-flight authorize request the controller persists the
 * `(state, code_verifier)` pair. On callback the matching state is consumed
 * (one-shot) so the verifier can be replayed to the token endpoint.
 *
 * This is the OIDC-free sibling of {@see \Phlix\Plugins\Oidc\OidcStateStore}: no
 * `nonce` (there is no `id_token` to replay-protect in plain OAuth2).
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
interface OAuth2StateStore
{
    /**
     * Persist a PKCE verifier keyed by the `state` value echoed back through the
     * provider redirect.
     *
     * `$context` is an OPTIONAL server-side envelope carried alongside the
     * verifier and returned verbatim by {@see consume()}. It is written only by
     * the authorize step and is NEVER exposed to the client — the `state` query
     * parameter only carries the opaque lookup id. Account linking uses it to
     * bind the flow to the initiating (already-authenticated) user:
     * `['intent' => 'link', 'link_user_id' => <uuid>]`. Because it lives
     * server-side (keyed by the one-shot `state`), the callback can trust
     * `link_user_id` — a client cannot forge or alter it the way it could a plain
     * query parameter.
     *
     * @param array<string, mixed>|null $context Optional integrity-protected,
     *                                           server-side context.
     */
    public function put(string $state, string $codeVerifier, ?array $context = null): void;

    /**
     * One-shot lookup of the entry for a given state. Returns null if the state
     * was never issued or has already been consumed.
     *
     * The `context` key is present only when a non-empty context was supplied to
     * {@see put()}; consumers must treat it as optional.
     *
     * @return array{code_verifier: string, context?: array<string, mixed>}|null
     */
    public function consume(string $state): ?array;
}
