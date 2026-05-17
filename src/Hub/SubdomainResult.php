<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Result of subdomain allocation from the hub.
 *
 * @package Phlex\Hub
 * @since 0.12.0
 */
final class SubdomainResult
{
    /**
     * @param string $subdomain   Subdomain label (e.g. "abc12345").
     * @param string $fqdn       Fully qualified domain name (e.g. "abc12345.phlex.media").
     * @param string $tlsCertPath Path to the TLS certificate file.
     * @param string $tlsKeyPath  Path to the TLS private key file.
     */
    public function __construct(
        public readonly string $subdomain,
        public readonly string $fqdn,
        public readonly string $tlsCertPath,
        public readonly string $tlsKeyPath,
    ) {
    }

    /**
     * Create from hub API response.
     *
     * @param array<string, mixed> $data Raw response data.
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subdomain: is_string($data['subdomain'] ?? null) ? $data['subdomain'] : '',
            fqdn: is_string($data['fqdn'] ?? null) ? $data['fqdn'] : '',
            tlsCertPath: is_string($data['tls_cert_path'] ?? null) ? $data['tls_cert_path'] : '',
            tlsKeyPath: is_string($data['tls_key_path'] ?? null) ? $data['tls_key_path'] : '',
        );
    }

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subdomain' => $this->subdomain,
            'fqdn' => $this->fqdn,
            'tls_cert_path' => $this->tlsCertPath,
            'tls_key_path' => $this->tlsKeyPath,
        ];
    }
}
