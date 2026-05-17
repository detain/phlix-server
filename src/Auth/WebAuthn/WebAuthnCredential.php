<?php

declare(strict_types=1);

namespace Phlex\Auth\WebAuthn;

final class WebAuthnCredential
{
    public function __construct(
        public readonly string $credentialId,
        public readonly string $userId,
        public readonly string $publicKey,
        public readonly string $counter,
        public readonly string $type,
        public readonly ?string $deviceType,
        public readonly ?string $aaguid,
        public readonly int $registeredAt,
    ) {
    }

    public static function fromDbRow(array $row): self
    {
        return new self(
            credentialId: is_resource($row['credential_id'])
                ? stream_get_contents($row['credential_id'])
                : $row['credential_id'],
            userId: (string) $row['user_id'],
            publicKey: is_resource($row['public_key'])
                ? stream_get_contents($row['public_key'])
                : $row['public_key'],
            counter: (string) $row['counter'],
            type: (string) ($row['type'] ?? 'public-key'),
            deviceType: $row['device_type'] ?? null,
            aaguid: $row['aaguid'] !== null && $row['aaguid'] !== ''
                ? (is_resource($row['aaguid']) ? stream_get_contents($row['aaguid']) : $row['aaguid'])
                : null,
            registeredAt: (int) ($row['registered_at'] ?? time()),
        );
    }

    public function toArray(): array
    {
        return [
            'credential_id' => base64_encode($this->credentialId),
            'user_id' => $this->userId,
            'type' => $this->type,
            'device_type' => $this->deviceType,
            'aaguid' => $this->aaguid !== null ? bin2hex($this->aaguid) : null,
            'registered_at' => $this->registeredAt,
        ];
    }
}
