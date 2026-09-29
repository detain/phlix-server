<?php

/**
 * Phlix media server component: WebAuthn.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth\WebAuthn;

final class WebAuthnSettings
{
    /**
     * @param string[] $allowedOrigins Exact origins a ceremony's clientDataJSON
     *                                 `origin` may carry (M-2b, security audit
     *                                 2026-09-29 — the verifier previously never
     *                                 checked origin at all). Empty list falls
     *                                 back to the single {@see $rpOrigin}, so
     *                                 every pre-existing construction site keeps
     *                                 its behaviour with zero changes.
     */
    public function __construct(
        public readonly string $rpId,
        public readonly string $rpName,
        public readonly string $rpOrigin,
        public readonly bool $attestationRequired = false,
        public readonly array $allowedOrigins = [],
    ) {
    }

    /**
     * The effective allowed-origin set: the explicit list when configured,
     * else the legacy single rpOrigin.
     *
     * @return string[]
     */
    public function effectiveOrigins(): array
    {
        return $this->allowedOrigins !== [] ? $this->allowedOrigins : [$this->rpOrigin];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $rpOriginRaw = $config['rp_origin'] ?? null;
        // M-2b: `rp_origin` accepts a string (legacy shape, unchanged) or a
        // list of strings (multi-origin deployments: reverse-proxy alias, LAN
        // + WAN hostnames). The first entry doubles as the legacy rpOrigin.
        $origins = [];
        if (is_array($rpOriginRaw)) {
            foreach ($rpOriginRaw as $origin) {
                if (is_string($origin) && $origin !== '') {
                    $origins[] = $origin;
                }
            }
        } elseif (is_string($rpOriginRaw) && $rpOriginRaw !== '') {
            $origins = [$rpOriginRaw];
        }

        return new self(
            rpId: is_string($config['rp_id'] ?? null) ? $config['rp_id'] : 'localhost',
            rpName: is_string($config['rp_name'] ?? null) ? $config['rp_name'] : 'Phlix Media Server',
            rpOrigin: $origins !== [] ? $origins[0] : 'https://localhost',
            attestationRequired: (bool) ($config['attestation_required'] ?? false),
            allowedOrigins: $origins,
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
