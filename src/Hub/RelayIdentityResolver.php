<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Auth\UserIdentityRepository;

use function is_string;

/**
 * Resolves the authenticated relay principal to a server-side user (S301).
 *
 * ## What the relay principal IS (measured)
 *
 * The hub authenticates the relay session itself (the server opens the tunnel
 * presenting its enrollment JWT — {@see RelayConfig} — which the hub's relay
 * worker validates before any frame can flow) and, per proxied request,
 * authenticates the HUB user and verifies they own the paired server. It then
 * stamps the validated owner on the inbound `x-phlix-relay-user` header
 * (stripping any client-supplied copy on the way in — see phlix-hub's
 * `ServerProxyController::STRIPPED_REQUEST_HEADERS` / `buildForwardHeaders()`).
 * That stamped value is the AUTHENTICATED RELAY PRINCIPAL: a hub user UUID that
 * no client can forge on this tunnel.
 *
 * ## The mapping (the coordinator's identity direction, 2026-08-27)
 *
 * The profile identity does NOT cross the tunnel as a client-supplied claim —
 * the hub sends no profile marker and the server accepts none. Instead the
 * server resolves hub-user → server user → profile ENTIRELY from its own rows:
 * this resolver looks the principal up in the `user_identities` table
 * (migration 092) under the `hub` provider family, where a row is written only
 * by {@see \Phlix\Server\Http\Controllers\AccountLinkController::linkHub()} —
 * a server-authenticated user proving control of their hub identity by
 * presenting a hub JWT that the server VERIFIES cryptographically
 * ({@see HubJwtValidator}: Ed25519 signature, `iss`/`aud`/`server_id`/`exp`).
 *
 * A principal with no row resolves to null: the request keeps the hub UUID for
 * auth-presence and log attribution (exactly as before S301) and the
 * server-side protections that resolve against its OWN user rows — the
 * parental {@see \Phlix\Media\Library\RatingGate}, per-profile stream limits,
 * per-user lookups — stay inert for it. That is the honest state, not a hole:
 * there is no server-side identity to enforce against, and
 * `checkStreamLimit()`'s `profile_not_found` refusal names it.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
final class RelayIdentityResolver
{
    /**
     * The `user_identities.provider` family under which hub principals are
     * linked. The hub is a single-instance identity, so `provider_instance`
     * stays the '' default sentinel (S47).
     */
    public const PROVIDER_FAMILY = 'hub';

    /**
     * @param UserIdentityRepository $identities The `user_identities` store
     *                                           (migration 092) the server-side
     *                                           linkage lives in.
     */
    public function __construct(private readonly UserIdentityRepository $identities)
    {
    }

    /**
     * Resolve an authenticated relay principal to a server user id, or null
     * when the server holds no linkage for it.
     *
     * The lookup is keyed on the exact value the hub stamped — never on
     * anything the client supplied separately. A profile claim never crosses
     * the tunnel, and nothing but a `provider = 'hub'` row (written from a
     * cryptographically verified hub JWT) can yield a server identity.
     *
     * @param string $relayPrincipal The `x-phlix-relay-user` value from the
     *                               relay envelope (may be '' when the hub
     *                               stamped none).
     *
     * @return string|null The linked server user id, or null when unmapped.
     */
    public function resolve(string $relayPrincipal): ?string
    {
        if ($relayPrincipal === '') {
            return null;
        }

        $row = $this->identities->findByProviderExternalId(
            self::PROVIDER_FAMILY,
            '',
            $relayPrincipal,
        );

        $userId = is_array($row) ? ($row['user_id'] ?? null) : null;

        return is_string($userId) && $userId !== '' ? $userId : null;
    }
}
