<?php

declare(strict_types=1);

namespace Phlix\Auth\WebAuthn;

final class WebAuthnSettings
{
    public function __construct(
        public readonly string $rpId,
        public readonly string $rpName,
        public readonly string $rpOrigin,
        public readonly bool $attestationRequired = false,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            rpId: is_string($config['rp_id'] ?? null) ? $config['rp_id'] : 'localhost',
            rpName: is_string($config['rp_name'] ?? null) ? $config['rp_name'] : 'Phlix Media Server',
            rpOrigin: is_string($config['rp_origin'] ?? null) ? $config['rp_origin'] : 'https://localhost',
            attestationRequired: (bool) ($config['attestation_required'] ?? false),
        );
    }

    /**
     * @return array<string, string|bool>
     */
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
