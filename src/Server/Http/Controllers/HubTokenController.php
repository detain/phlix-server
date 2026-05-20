<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\JwtHandler;
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
 * @package Phlix\Server\Http\Controllers
 * @since 0.11.0
 */
final class HubTokenController
{
    /**
     * Creates a new HubTokenController.
     *
     * @param HubJwtValidatorInterface|null $validator The hub JWT validator.
     * @param JwtHandler     $jwtHandler The server's JWT handler for issuing session tokens.
     */
    public function __construct(
        private readonly ?HubJwtValidatorInterface $validator,
        private readonly JwtHandler $jwtHandler,
    ) {
    }

    /**
     * Handles POST /api/v1/auth/hub-token.
     *
     * @param Request $request Must contain `hub_token` in the JSON body.
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response
     */
    public function handle(Request $request, array $params = []): Response
    {
        if ($this->validator === null) {
            return (new Response())
                ->status(503)
                ->json([
                    'error' => 'Service Unavailable',
                    'code' => 'hub.not_enrolled',
                    'message' => 'Server is not enrolled with a hub',
                ]);
        }

        $hubToken = $request->input('hub_token');

        if (!is_string($hubToken) || $hubToken === '') {
            return (new Response())
                ->status(400)
                ->json([
                    'error' => 'Bad Request',
                    'code' => 'hub.token_required',
                    'message' => 'hub_token is required in request body',
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

        $serverToken = $this->jwtHandler->createAccessToken($claims->userId, [
            'hub_user_id' => $claims->userId,
            'server_id' => $claims->serverId,
        ]);

        return (new Response())->json([
            'server_session_token' => $serverToken,
        ]);
    }
}
