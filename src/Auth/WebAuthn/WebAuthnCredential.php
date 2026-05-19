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

    /**
     * @param array<string, mixed> $row Database row
     */
    public static function fromDbRow(array $row): self
    {
        return new self(
            credentialId: self::readBinaryField($row, 'credential_id'),
            userId: self::readStringField($row, 'user_id'),
            publicKey: self::readBinaryField($row, 'public_key'),
            counter: self::readStringField($row, 'counter', '0'),
            type: self::readStringField($row, 'type', 'public-key'),
            deviceType: is_string($row['device_type'] ?? null) ? $row['device_type'] : null,
            aaguid: self::readOptionalBinaryField($row, 'aaguid'),
            registeredAt: self::readIntField($row, 'registered_at', time()),
        );
    }

    /**
     * @return array{
     *     credential_id: string,
     *     user_id: string,
     *     type: string,
     *     device_type: string|null,
     *     aaguid: string|null,
     *     registered_at: int
     * }
     */
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

    /**
     * @param array<string, mixed> $row
     */
    private static function readBinaryField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            return $contents === false ? '' : $contents;
        }
        if (is_string($value)) {
            return $value;
        }
        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function readOptionalBinaryField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            return $contents === false ? null : $contents;
        }
        if (is_string($value)) {
            return $value;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function readStringField(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function readIntField(array $row, string $key, int $default): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return $default;
    }
}
