<?php

declare(strict_types=1);

namespace Phlex\Hub;

use InvalidArgumentException;

/**
 * Value object representing a cryptographic key pair for Hub authentication.
 *
 * Encapsulates the Ed25519 keypair used for server-to-hub authentication.
 * The secret key is 64 bytes (libsodium expanded format) and the public
 * key is 32 bytes.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
final class KeyPair
{
    /**
     * @param string $secretKey 64-byte Ed25519 expanded secret key (libsodium format)
     * @param string $publicKey 32-byte Ed25519 public key
     *
     * @throws InvalidArgumentException If keys are not the expected lengths
     */
    public function __construct(
        public readonly string $secretKey,
        public readonly string $publicKey,
    ) {
        if (strlen($secretKey) !== 64) {
            throw new InvalidArgumentException(
                sprintf('Secret key must be exactly 64 bytes, got %d bytes', strlen($secretKey))
            );
        }

        if (strlen($publicKey) !== 32) {
            throw new InvalidArgumentException(
                sprintf('Public key must be exactly 32 bytes, got %d bytes', strlen($publicKey))
            );
        }
    }
}
