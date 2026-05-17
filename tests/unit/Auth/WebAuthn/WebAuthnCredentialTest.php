<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Auth\WebAuthn;

use PHPUnit\Framework\TestCase;
use Phlex\Auth\WebAuthn\WebAuthnCredential;

final class WebAuthnCredentialTest extends TestCase
{
    public function test_smoke(): void
    {
        $credentialId = random_bytes(32);
        $userId = '550e8400-e29b-41d4-a716-446655440000';
        $publicKey = random_bytes(65);
        $counter = '42';
        $type = 'public-key';
        $deviceType = 'platform';
        $aaguid = random_bytes(16);
        $registeredAt = time();

        $credential = new WebAuthnCredential(
            credentialId: $credentialId,
            userId: $userId,
            publicKey: $publicKey,
            counter: $counter,
            type: $type,
            deviceType: $deviceType,
            aaguid: $aaguid,
            registeredAt: $registeredAt
        );

        $this->assertSame($credentialId, $credential->credentialId);
        $this->assertSame($userId, $credential->userId);
        $this->assertSame($publicKey, $credential->publicKey);
        $this->assertSame($counter, $credential->counter);
        $this->assertSame($type, $credential->type);
        $this->assertSame($deviceType, $credential->deviceType);
        $this->assertSame($aaguid, $credential->aaguid);
        $this->assertSame($registeredAt, $credential->registeredAt);
    }

    public function test_fromDbRow(): void
    {
        $credentialId = random_bytes(32);
        $userId = '550e8400-e29b-41d4-a716-446655440000';
        $publicKey = random_bytes(65);
        $aaguid = random_bytes(16);
        $registeredAt = time();

        $row = [
            'credential_id' => $credentialId,
            'user_id' => $userId,
            'public_key' => $publicKey,
            'counter' => '42',
            'type' => 'public-key',
            'device_type' => 'cross-platform',
            'aaguid' => $aaguid,
            'registered_at' => $registeredAt,
        ];

        $credential = WebAuthnCredential::fromDbRow($row);

        $this->assertSame($credentialId, $credential->credentialId);
        $this->assertSame($userId, $credential->userId);
        $this->assertSame($publicKey, $credential->publicKey);
        $this->assertSame('42', $credential->counter);
        $this->assertSame('cross-platform', $credential->deviceType);
        $this->assertSame($aaguid, $credential->aaguid);
    }

    public function test_toArray(): void
    {
        $credentialId = random_bytes(32);
        $aaguid = random_bytes(16);
        $registeredAt = time();

        $credential = new WebAuthnCredential(
            credentialId: $credentialId,
            userId: 'user-id',
            publicKey: random_bytes(65),
            counter: '0',
            type: 'public-key',
            deviceType: 'platform',
            aaguid: $aaguid,
            registeredAt: $registeredAt
        );

        $array = $credential->toArray();

        $this->assertIsArray($array);
        $this->assertSame(base64_encode($credentialId), $array['credential_id']);
        $this->assertSame('user-id', $array['user_id']);
        $this->assertSame('public-key', $array['type']);
        $this->assertSame('platform', $array['device_type']);
        $this->assertSame(bin2hex($aaguid), $array['aaguid']);
        $this->assertSame($registeredAt, $array['registered_at']);
    }

    public function test_toArray_with_null_aaguid(): void
    {
        $credential = new WebAuthnCredential(
            credentialId: random_bytes(32),
            userId: 'user-id',
            publicKey: random_bytes(65),
            counter: '0',
            type: 'public-key',
            deviceType: null,
            aaguid: null,
            registeredAt: time()
        );

        $array = $credential->toArray();

        $this->assertNull($array['aaguid']);
    }
}
