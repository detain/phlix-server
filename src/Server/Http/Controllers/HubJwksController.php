<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\RateLimitException;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\HubClient;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Serves the server's JWKS document at `/.well-known/jwks.json`.
 *
 * This endpoint exposes the server's Ed25519 public key(s) to the hub
 * so that the hub can verify JWTs signed by this server. The JWKS
 * document is self-hosted rather than proxied through the hub.
 *
 * @package Phlix\Server\Http\Controllers
 * @since 0.11.0
 * @see HubClient::getPublicKeysJwk() For the JWK structure.
 */
final class HubJwksController
{
    /** @var HubClient The hub client instance. */
    private HubClient $hubClient;

    /**
     * Per-surface rate limiter for the public JWKS endpoint (SV-4.15(g)); the
     * worker-local in-memory {@see \Phlix\Common\RateLimit\RateLimitProfiles::JWKS}
     * instance in production. Null only in the degraded no-container fallback,
     * where it is a no-op.
     *
     * In-memory / per-worker is deliberate here: JWKS is a public, low-value,
     * cache-frontable DoS surface (not a brute-force / credential-enumeration
     * one), so a soft per-worker budget is acceptable and no shared DB backend is
     * required.
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $limiter;

    /**
     * Creates a new HubJwksController.
     *
     * The limiter is optional so the degraded no-container fallback in
     * {@see \Phlix\Server\Core\Application::getHubJwksController()} keeps working;
     * the DI factory binds it explicitly to the
     * {@see \Phlix\Common\RateLimit\RateLimitProfiles::JWKS} container id (PHP-DI
     * skips optional ctor params during autowiring, so an unbound limiter would
     * silently stay null and leave the surface unlimited).
     *
     * @param HubClient               $hubClient The hub client instance.
     * @param RateLimiterInterface|null $limiter  Limiter guarding the endpoint.
     */
    public function __construct(HubClient $hubClient, ?RateLimiterInterface $limiter = null)
    {
        $this->hubClient = $hubClient;
        $this->limiter = $limiter;
    }

    /**
     * Handles GET /.well-known/jwks.json.
     *
     * Returns a JSON document containing the server's Ed25519 public key(s)
     * in JWK format. This document is cacheable for up to 1 hour.
     *
     * @param Request $request The HTTP request (used for the rate-limit key).
     * @param array<string, string> $params Path parameters (unused).
     *
     * @throws RateLimitException When the client IP exceeds the JWKS window
     *                            budget — the central mapping (SV-4.15(c)) turns
     *                            it into a 429 + `Retry-After` response.
     *
     * @return Response JSON JWKS document.
     */
    public function handle(Request $request, array $params): Response
    {
        // Public DoS guard keyed on the real client IP (X-Forwarded-For aware).
        // A trip throws RateLimitException -> central 429 mapping (SV-4.15(c)).
        // The route is an inline closure in Application that delegates to this
        // method, so the throw propagates through the same dispatch path the
        // central mapping catches.
        if ($this->limiter !== null) {
            $state = $this->limiter->hit('jwks:' . $request->getClientIp());
            if ($state->limited) {
                throw new RateLimitException($state->resetAt, $state->remaining);
            }
        }

        $keys = $this->hubClient->getPublicKeysJwk();

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=3600')
            ->json(['keys' => $keys]);
    }
}
