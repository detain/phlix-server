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
 * follows. Until the SRV-M17 boundary mapping lands, this typed refusal
 * answers the router's central 500 (the error log names the exception
 * class) — the same documented interim shape as the passkey finish and
 * quick-connect approve legs.
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
     */
    public function __construct(
        private readonly ?HubJwtValidatorInterface $validator,
        private readonly JwtHandler $jwtHandler,
        private readonly UserRepository $userRepository,
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
     * @throws PasswordChangeRequiredException When the mapped account carries
     *     the `must_change_password` hold — no server token is minted (the
     *     central router answers 500 until SRV-M17 maps the leg).
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

        // SRV-M18 rework (review server__pm): this mint is a session-shaped
        // token like any other — the shared hold-guard refuses a held account
        // before the exchange token exists. No cookies and no token are ever
        // produced; the typed refusal reaches the router's central 500 until
        // SRV-M17 maps it.
        PasswordChangeRequiredException::refuseIfHeld(
            $this->userRepository,
            null,
            $claims->userId,
            'hub_token',
        );

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
