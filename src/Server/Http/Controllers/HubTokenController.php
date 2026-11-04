<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\JwtHandler;
use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Auth\UserRepository;
use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Hub\RelayIdentityResolver;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Exchanges a hub-issued JWT for a server-internal session token.
 *
 * This controller provides backward compatibility for older clients
 * that do not understand hub JWTs natively. They present their hub JWT
 * and receive a server-issued JWT in exchange, allowing them to use
 * the regular server auth flow.
 *
 * POST /api/v1/auth/hub-token
 * Content-Type: application/json
 * { "hub_token": "eyJ..." }
 *
 * Response 200: { "server_session_token": "..." }
 * Response 400: { "error": "hub_token required" }
 * Response 401: { "error": "hub.jwt_invalid" }
 *
 * The mint is an account-hold gate like every other token-minting surface
 * (SRV-M18 rework, review server__pm): a server account carrying
 * `must_change_password` is refused through the shared
 * {@see PasswordChangeRequiredException::refuseIfHeld()} guard BEFORE the
 * exchange token is minted, whatever freshness policy the hub-side JWT
 * follows. The guard keys the RESOLVED LOCAL identity (SRV-M18 RW2, review
 * round 2): `$claims->userId` is the HUB-side UUID, while the flag lives on
 * this server's own `users.id` row — a key space those ids can never enter.
 * The principal is therefore first resolved hub → server through the same
 * {@see RelayIdentityResolver} link the relay path resolves against
 * (`user_identities`, 'hub' family, rows written only by
 * {@see \Phlix\Server\Http\Controllers\AccountLinkController::linkHub()} from
 * a cryptographically verified hub JWT), and the guard consults that resolved
 * id. An UNMAPPED hub principal has no server row and is passed through —
 * see handle() for the ratified refuse-open semantics and why the mint it
 * produces carries no server-account authority. Until the SRV-M17 boundary
 * mapping lands, the typed refusal answers the router's central 500 (the
 * error log names the exception class) — the same documented interim shape as
 * the passkey finish and quick-connect approve legs.
 *
 * @package Phlix\Server\Http\Controllers
 * @since 0.11.0
 */
final class HubTokenController
{
    /**
     * Creates a new HubTokenController.
     *
     * @param HubJwtValidatorInterface|null $validator      The hub JWT validator.
     * @param JwtHandler     $jwtHandler      The server's JWT handler for issuing session tokens.
     * @param UserRepository $userRepository  Source of truth for the account-hold
     *                                        gate the mint performs (SRV-M18).
     * @param RelayIdentityResolver $identityResolver The hub-principal → local-account
     *                                        link the hold guard resolves through
     *                                        (SRV-M18 RW2) — the same single-source
     *                                        resolver the relay path uses, never a
     *                                        parallel lookup. Required: an unwired
     *                                        guard is exactly the defect this leg
     *                                        had, so the container cannot hand this
     *                                        controller a shape that skips it.
     */
    public function __construct(
        private readonly ?HubJwtValidatorInterface $validator,
        private readonly JwtHandler $jwtHandler,
        private readonly UserRepository $userRepository,
        private readonly RelayIdentityResolver $identityResolver,
    ) {
    }

    /**
     * Handles POST /api/v1/auth/hub-token.
     *
     * @param Request $request Must contain `hub_token` in the JSON body.
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response
     *
     * @throws PasswordChangeRequiredException When the hub principal resolves
     *     to a linked account that carries the `must_change_password` hold —
     *     no server token is minted (the central router answers 500 until
     *     SRV-M17 maps the leg).
     */
    public function handle(Request $request, array $params = []): Response
    {
        if ($this->validator === null) {
            return (new Response())
                ->status(503)
                ->json([
                    'error' => 'Service unavailable',
                    'code' => 'hub.not_enrolled',
                    'message' => 'This server isn\'t enrolled with a Phlix Hub yet. Go to Admin > Hub to enroll it.',
                ]);
        }

        $hubToken = $request->input('hub_token');

        if (!is_string($hubToken) || $hubToken === '') {
            return (new Response())
                ->status(400)
                ->json([
                    'error' => 'Bad Request',
                    'code' => 'hub.token_required',
                    'message' => 'Hub token is required',
                ]);
        }

        $claims = $this->validator->validate($hubToken);

        if ($claims === null) {
            return (new Response())
                ->status(401)
                ->json([
                    'error' => 'Unauthorized',
                    'code' => 'hub.jwt_invalid',
                    'message' => 'Invalid or expired hub token',
                ]);
        }

        // SRV-M18 RW2 (review server__pm round-2 Major): the hold flag lives
        // on this server's OWN users row, keyed by a server-generated UUID;
        // $claims->userId is the HUB-side id that key space can never carry —
        // the separation is exactly why user_identities and the resolver
        // lineage exist. Guarding the raw claim was therefore vacuous in
        // production: the lookup missed, the flag read false, and a HELD
        // LINKED account still received its exchange token. Resolve hub
        // principal → local account through the same single-source
        // RelayIdentityResolver the relay path uses (user_identities 'hub'
        // family, rows written only by AccountLinkController::linkHub() from
        // a cryptographically verified hub JWT), then guard the RESOLVED id.
        // The mint itself keeps the hub-side subject unchanged — this cure
        // moves only the guard's key, never the token's wire identity.
        $localUserId = $this->identityResolver->resolve($claims->userId);

        if ($localUserId !== null) {
            PasswordChangeRequiredException::refuseIfHeld(
                $this->userRepository,
                null,
                $localUserId,
                'hub_token',
            );
        }

        // UNLINKED PRINCIPALS — explicitly ratified REFUSE-OPEN (this leg's
        // deciding rule, pinned by
        // HubTokenControllerTest::test_an_unlinked_hub_principal_mints_without_users_consultation).
        // With no user_identities row there is NO server account for the
        // guard to consult: the hold question has no subject, so skipping the
        // consult enforces nothing vacuously and refusing would enforce a
        // LINKAGE POLICY the estate never ruled (and would need a wire code
        // the contract law keeps frozen). This mirrors the resolver lineage's
        // own S301 class-header law verbatim in effect — "a principal with no
        // row resolves to null … the honest state, not a hole" — so the two
        // consumers of the same mapping rule the same way. The pass-through
        // carries no held-account authority either: the minted token's
        // subject is the hub UUID, and EVERY wired gate on this server
        // applies the users-row liveness law (AuthManager::validateAccessToken
        // rejects a subject with no active row; the WS handshake gate the
        // same), so it authenticates no local account — before or after any
        // later link — while acquiring a link row itself requires a
        // server-authenticated session a held account cannot obtain (its
        // login leg is gated by the very flag).

        $tokenClaims = [
            'hub_user_id' => $claims->userId,
            'server_id' => $claims->serverId,
        ];

        // Include the opaque_token claim if the hub JWT contained one
        if ($claims->token !== '') {
            $tokenClaims['opaque_token'] = $claims->token;
        }

        $serverToken = $this->jwtHandler->createAccessToken($claims->userId, $tokenClaims);

        return (new Response())->json([
            'server_session_token' => $serverToken,
        ]);
    }
}
