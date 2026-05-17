<?php

declare(strict_types=1);

namespace Phlex\Auth\WebAuthn;

final class WebAuthnSettings
{
    public function __construct(
        public readonly string $rpId,
        public readonly string $rpName,
        public readonly string $rpOrigin,
        public readonly bool $attestationRequired = false,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            rpId: $config['rp_id'] ?? 'localhost',
            rpName: $config['rp_name'] ?? 'Phlex Media Server',
            rpOrigin: $config['rp_origin'] ?? 'https://localhost',
            attestationRequired: (bool) ($config['attestation_required'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'rp_id' => $this->rpId,
            'rp_name' => $this->rpName,
            'rp_origin' => $this->rpOrigin,
            'attestation_required' => $this->attestationRequired,
        ];
    }
}
