<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Hub\HubJwtValidatorInterface;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Validates hub-issued JWTs and populates $request->hubUser on success.
 *
 * This middleware is applied alongside the existing authentication layer
 * on routes that support both server-direct login and hub-mediated login.
 * It extracts the bearer token from the Authorization header and validates
 * it using the hub's JWKS.
 *
 * Behaviour:
 * - No Authorization header → returns null (allows other auth to try).
 * - Valid hub JWT → sets `$request->hubUser` with HubUserClaims, returns null.
 * - Invalid/expired hub JWT → returns 401 Unauthorized JSON.
 *
 * @package Phlix\Server\Http\Middleware
 * @since 0.11.0
 */
final class HubJwtMiddleware
{
    /**
     * Creates a new HubJwtMiddleware.
     *
     * @param HubJwtValidatorInterface|null $validator The hub JWT validator instance.
     */
    public function __construct(
        private readonly ?HubJwtValidatorInterface $validator,
    ) {
    }

    /**
     * Runs the middleware against a request.
     *
     * @param Request $request The incoming request. On success, $request->hubUser
     *                         will be populated with HubUserClaims.
     *
     * @return Response|null 401 on invalid hub JWT, null to continue.
     */
    public function __invoke(Request $request): ?Response
    {
        if ($this->validator === null) {
            return null;
        }

        $token = $request->getBearerToken();

        if ($token === null) {
            return null;
        }

        $claims = $this->validator->validate($token);

        if ($claims === null) {
            return (new Response())
                ->status(401)
                ->json([
                    'error' => 'Unauthorized',
                    'code' => 'hub.jwt_invalid',
                ]);
        }

        $request->hubUser = $claims;

        return null;
    }
}
