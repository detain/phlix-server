<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

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
     * Creates a new HubJwksController.
     *
     * @param HubClient $hubClient The hub client instance.
     */
    public function __construct(HubClient $hubClient)
    {
        $this->hubClient = $hubClient;
    }

    /**
     * Handles GET /.well-known/jwks.json.
     *
     * Returns a JSON document containing the server's Ed25519 public key(s)
     * in JWK format. This document is cacheable for up to 1 hour.
     *
     * @param Request $request The HTTP request (unused).
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response JSON JWKS document.
     */
    public function handle(Request $request, array $params): Response
    {
        $keys = $this->hubClient->getPublicKeysJwk();

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=3600')
            ->json(['keys' => $keys]);
    }
}
