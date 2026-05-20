<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\WebAuthn;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnSettings;
use Phlix\Auth\WebAuthn\WebAuthnCredential;
use Workerman\MySQL\Connection;

final class WebAuthnManagerTest extends TestCase
{
    private WebAuthnManager $manager;
    private UserRepository $userRepo;
    private Connection $db;
    private WebAuthnCredentialRepository $credentialRepo;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->userRepo = new UserRepository($this->db);
        $this->credentialRepo = new WebAuthnCredentialRepository($this->db);

        $settings = new WebAuthnSettings(
            rpId: 'localhost',
            rpName: 'Test RP',
            rpOrigin: 'https://localhost',
            attestationRequired: false
        );

        $this->manager = new WebAuthnManager(
            $this->userRepo,
            $this->db,
            $this->credentialRepo,
            $settings
        );
    }

    public function test_startRegistration_returns_valid_options(): void
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000';
        $username = 'testuser';

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($userId, $username) {
                if (strpos($sql, 'SELECT * FROM users WHERE id = ?') !== false) {
                    return [[
                        'id' => $userId,
                        'username' => $username,
                        'email' => 'test@example.com',
                    ]];
                }
                if (strpos($sql, 'SELECT * FROM webauthn_credentials') !== false) {
                    return [];
                }
                return [];
            });

        $options = $this->manager->startRegistration($userId, $username);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('rp', $options);
        $this->assertArrayHasKey('user', $options);
        $this->assertArrayHasKey('pubKeyCredParams', $options);
        $this->assertArrayHasKey('timeout', $options);
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame($userId, $options['user']['id']);
        $this->assertSame($username, $options['user']['name']);
    }

    public function test_startRegistration_throws_for_unknown_user(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User not found');

        $this->manager->startRegistration('nonexistent-id', 'username');
    }

    public function test_startAuthentication_returns_challenge(): void
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000';
        $username = 'testuser';

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($userId, $username) {
                if (strpos($sql, 'SELECT * FROM users WHERE username = ?') !== false) {
                    return [[
                        'id' => $userId,
                        'username' => $username,
                        'email' => 'test@example.com',
                    ]];
                }
                if (strpos($sql, 'SELECT * FROM webauthn_credentials') !== false) {
                    return [[
                        'id' => 'cred-id-1',
                        'user_id' => $userId,
                        'credential_id' => random_bytes(32),
                        'public_key' => random_bytes(65),
                        'counter' => '0',
                        'type' => 'public-key',
                        'device_type' => 'platform',
                        'aaguid' => null,
                        'registered_at' => time(),
                    ]];
                }
                return [];
            });

        $options = $this->manager->startAuthentication($username);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('challenge', $options);
        $this->assertArrayHasKey('rpId', $options);
        $this->assertArrayHasKey('allowCredentials', $options);
        $this->assertArrayHasKey('timeout', $options);
        $this->assertSame('localhost', $options['rpId']);
    }

    public function test_startAuthentication_throws_for_unknown_user(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User not found');

        $this->manager->startAuthentication('nonexistent');
    }

    public function test_startAuthentication_throws_when_no_credentials(): void
    {
        $username = 'testuser';

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($username) {
                if (strpos($sql, 'SELECT * FROM users WHERE username = ?') !== false) {
                    return [[
                        'id' => 'user-id',
                        'username' => $username,
                        'email' => 'test@example.com',
                    ]];
                }
                if (strpos($sql, 'SELECT * FROM webauthn_credentials') !== false) {
                    return [];
                }
                return [];
            });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No credentials registered for user');

        $this->manager->startAuthentication($username);
    }

    public function test_listCredentials(): void
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000';
        $credentialId = random_bytes(32);

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($userId, $credentialId) {
                if (strpos($sql, 'SELECT * FROM webauthn_credentials') !== false) {
                    return [[
                        'id' => 'cred-1',
                        'user_id' => $userId,
                        'credential_id' => $credentialId,
                        'public_key' => random_bytes(65),
                        'counter' => '10',
                        'type' => 'public-key',
                        'device_type' => 'platform',
                        'aaguid' => null,
                        'registered_at' => time(),
                    ]];
                }
                return [];
            });

        $credentials = $this->manager->listCredentials($userId);

        $this->assertIsArray($credentials);
        $this->assertCount(1, $credentials);
        $this->assertInstanceOf(WebAuthnCredential::class, $credentials[0]);
    }

    public function test_listCredentials_returns_empty_array_for_no_credentials(): void
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000';

        $this->db->method('query')->willReturn([]);

        $credentials = $this->manager->listCredentials($userId);

        $this->assertIsArray($credentials);
        $this->assertEmpty($credentials);
    }

    public function test_deleteCredential_returns_true_on_success(): void
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000';
        $credentialId = base64_encode(random_bytes(32));

        $this->db->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($userId) {
                if (strpos($sql, 'DELETE FROM webauthn_credentials') !== false) {
                    return 1;
                }
                return 0;
            });

        $result = $this->manager->deleteCredential($userId, $credentialId);

        $this->assertTrue($result);
    }

    public function test_deleteCredential_returns_false_for_invalid_credential_id(): void
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000';

        $result = $this->manager->deleteCredential($userId, 'invalid-base64!!!');

        $this->assertFalse($result);
    }
}
