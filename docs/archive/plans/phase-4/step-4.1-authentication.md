# Step 4.1: Authentication System

**Phase:** 4 - Authentication & Session Management  
**Plan File:** step-4.1-authentication.md  
**Objective:** Implement JWT authentication, password hashing, and user registration/login

---

## Overview

This step implements the core authentication system with JWT tokens, secure password handling, and user management.

**Prerequisites:** Phase 1 must be completed first.

---

## Tasks

### 4.1.1 Create JWT Handler

Create `src/Auth/JwtHandler.php`:
```php
<?php

namespace Phlex\Auth;

class JwtHandler
{
    private string $secretKey;
    private string $algorithm;
    private int $ttl;
    private int $refreshTtl;

    public function __construct(
        string $secretKey,
        string $algorithm = 'HS256',
        int $ttl = 3600,
        int $refreshTtl = 604800
    ) {
        $this->secretKey = $secretKey;
        $this->algorithm = $algorithm;
        $this->ttl = $ttl;
        $this->refreshTtl = $refreshTtl;
    }

    public function createAccessToken(string $userId, array $claims = []): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iss' => 'phlex',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'type' => 'access',
        ]);

        return $this->encode($payload);
    }

    public function createRefreshToken(string $userId): string
    {
        $now = time();
        $payload = [
            'iss' => 'phlex',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->refreshTtl,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)),
        ];

        return $this->encode($payload);
    }

    public function validateToken(string $token): ?array
    {
        try {
            $payload = $this->decode($token);
            
            // Verify expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            // Verify issuer
            if (($payload['iss'] ?? '') !== 'phlex') {
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getUserIdFromToken(string $token): ?string
    {
        $payload = $this->validateToken($token);
        return $payload['sub'] ?? null;
    }

    public function isAccessToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return ($payload['type'] ?? '') === 'access';
    }

    public function isRefreshToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return ($payload['type'] ?? '') === 'refresh';
    }

    private function encode(array $payload): string
    {
        $header = ['alg' => $this->algorithm, 'typ' => 'JWT'];
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac(
            'sha256',
            "{$headerEncoded}.{$payloadEncoded}",
            $this->secretKey,
            true
        );
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    private function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid token format');
        }

        [$headerEncoded, $payloadEncoded, $signature] = $parts;

        // Verify signature
        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $this->secretKey, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \InvalidArgumentException('Invalid signature');
        }

        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
```

### 4.1.2 Create User Model and Repository

Create `src/Auth/UserRepository.php`:
```php
<?php

namespace Phlex\Auth;

use Phlex\Common\Database\Connection;

class UserRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findById(string $id): ?array
    {
        $result = $this->db->query("SELECT * FROM users WHERE id = ?", [$id]);
        return $result[0] ?? null;
    }

    public function findByUsername(string $username): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM users WHERE username = ?",
            [$this->db->escape($username)]
        );
        return $result[0] ?? null;
    }

    public function findByEmail(string $email): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM users WHERE email = ?",
            [$this->db->escape($email)]
        );
        return $result[0] ?? null;
    }

    public function create(array $data): string
    {
        $id = $this->generateUuid();
        $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID);

        $this->db->query(
            "INSERT INTO users (id, username, email, password_hash, display_name) VALUES (?, ?, ?, ?, ?)",
            [
                $id,
                $data['username'],
                $data['email'],
                $passwordHash,
                $data['display_name'] ?? $data['username'],
            ]
        );

        // Create default settings
        $this->db->query(
            "INSERT INTO user_settings (user_id) VALUES (?)",
            [$id]
        );

        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        if (isset($data['display_name'])) {
            $sets[] = 'display_name = ?';
            $values[] = $data['display_name'];
        }

        if (isset($data['email'])) {
            $sets[] = 'email = ?';
            $values[] = $data['email'];
        }

        if (isset($data['password'])) {
            $sets[] = 'password_hash = ?';
            $values[] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;
        $this->db->query(
            "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    public function updateLastLogin(string $id): void
    {
        $this->db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$id]);
    }

    public function verifyPassword(string $id, string $password): bool
    {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }

        return password_verify($password, $user['password_hash']);
    }

    public function emailExists(string $email): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM users WHERE email = ?",
            [$this->db->escape($email)]
        );
        return !empty($result);
    }

    public function usernameExists(string $username): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM users WHERE username = ?",
            [$this->db->escape($username)]
        );
        return !empty($result);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

### 4.1.3 Create Auth Manager

Create `src/Auth/AuthManager.php`:
```php
<?php

namespace Phlex\Auth;

use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\AuditLogger;

class AuthManager
{
    private UserRepository $userRepository;
    private JwtHandler $jwtHandler;
    private AuditLogger $auditLogger;
    private StructuredLogger $logger;

    public function __construct(
        UserRepository $userRepository,
        JwtHandler $jwtHandler,
        AuditLogger $auditLogger
    ) {
        $this->userRepository = $userRepository;
        $this->jwtHandler = $jwtHandler;
        $this->auditLogger = $auditLogger;
        $this->logger = LoggerFactory::get(LogChannels::AUTH);
    }

    public function register(string $username, string $email, string $password): array
    {
        // Validate
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new \InvalidArgumentException('Username must be 3-50 characters');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        // Check uniqueness
        if ($this->userRepository->usernameExists($username)) {
            throw new \InvalidArgumentException('Username already taken');
        }

        if ($this->userRepository->emailExists($email)) {
            throw new \InvalidArgumentException('Email already registered');
        }

        // Create user
        $userId = $this->userRepository->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $username,
        ]);

        $this->logger->info('User registered', ['user_id' => $userId, 'username' => $username]);

        return $this->createAuthResponse($userId);
    }

    public function login(string $username, string $password, string $deviceId): array
    {
        $user = $this->userRepository->findByUsername($username);

        if (!$user || !$this->userRepository->verifyPassword($user['id'], $password)) {
            $this->auditLogger->logFailedAuth('invalid_credentials', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new \InvalidArgumentException('Invalid username or password');
        }

        // Update last login
        $this->userRepository->updateLastLogin($user['id']);

        $this->auditLogger->logLogin($user['id'], $deviceId, true);

        $this->logger->info('User logged in', ['user_id' => $user['id'], 'device_id' => $deviceId]);

        return $this->createAuthResponse($user['id']);
    }

    public function refreshToken(string $refreshToken): array
    {
        if (!$this->jwtHandler->isRefreshToken($refreshToken)) {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        $payload = $this->jwtHandler->validateToken($refreshToken);
        if (!$payload) {
            throw new \InvalidArgumentException('Expired refresh token');
        }

        $userId = $payload['sub'];

        return $this->createAuthResponse($userId);
    }

    public function validateAccessToken(string $token): ?array
    {
        if (!$this->jwtHandler->isAccessToken($token)) {
            return null;
        }

        $payload = $this->jwtHandler->validateToken($token);
        if (!$payload) {
            return null;
        }

        return [
            'user_id' => $payload['sub'],
            'expires_at' => $payload['exp'],
        ];
    }

    public function getUser(string $userId): ?array
    {
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }

    private function createAuthResponse(string $userId): array
    {
        $accessToken = $this->jwtHandler->createAccessToken($userId);
        $refreshToken = $this->jwtHandler->createRefreshToken($userId);
        $user = $this->getUser($userId);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => $user,
        ];
    }
}
```

### 4.1.4 Create Auth Controller

Create `src/Server/Http/Controllers/AuthController.php`:
```php
<?php

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Auth\AuthManager;

class AuthController
{
    private AuthManager $authManager;

    public function __construct(AuthManager $authManager)
    {
        $this->authManager = $authManager;
    }

    public function register(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: username, email, password',
            ]);
        }

        try {
            $result = $this->authManager->register(
                $data['username'],
                $data['email'],
                $data['password']
            );
            return (new Response())->status(201)->json($result);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    public function login(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['username']) || empty($data['password'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: username, password',
            ]);
        }

        $deviceId = $request->getHeader('X-Device-Id') ?? 'unknown';

        try {
            $result = $this->authManager->login(
                $data['username'],
                $data['password'],
                $deviceId
            );
            return (new Response())->json($result);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
        }
    }

    public function refresh(Request $request, array $params): Response
    {
        $data = $request->body;

        if (empty($data['refresh_token'])) {
            return (new Response())->status(400)->json([
                'error' => 'refresh_token is required',
            ]);
        }

        try {
            $result = $this->authManager->refreshToken($data['refresh_token']);
            return (new Response())->json($result);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
        }
    }

    public function me(Request $request, array $params): Response
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $user = $this->authManager->getUser($userId);
        if (!$user) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        return (new Response())->json(['user' => $user]);
    }
}
```

### 4.1.5 Create Unit Tests

Create `tests/unit/Auth/JwtHandlerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlex\Auth\JwtHandler;

class JwtHandlerTest extends TestCase
{
    private JwtHandler $jwtHandler;

    protected function setUp(): void
    {
        $this->jwtHandler = new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
    }

    public function testCreateAccessToken(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-123');
        
        $this->assertIsString($token);
        $this->assertCount(3, explode('.', $token));
    }

    public function testValidateValidToken(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-123');
        $payload = $this->jwtHandler->validateToken($token);
        
        $this->assertIsArray($payload);
        $this->assertEquals('user-123', $payload['sub']);
        $this->assertEquals('access', $payload['type']);
    }

    public function testValidateInvalidToken(): void
    {
        $payload = $this->jwtHandler->validateToken('invalid.token.here');
        
        $this->assertNull($payload);
    }

    public function testIsAccessToken(): void
    {
        $accessToken = $this->jwtHandler->createAccessToken('user-123');
        $refreshToken = $this->jwtHandler->createRefreshToken('user-123');
        
        $this->assertTrue($this->jwtHandler->isAccessToken($accessToken));
        $this->assertFalse($this->jwtHandler->isAccessToken($refreshToken));
    }

    public function testGetUserIdFromToken(): void
    {
        $token = $this->jwtHandler->createAccessToken('user-456');
        $userId = $this->jwtHandler->getUserIdFromToken($token);
        
        $this->assertEquals('user-456', $userId);
    }
}
```

---

## Verification

After completing all tasks:

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Auth/ --testdox
```

2. Verify classes exist:
```bash
ls -la /home/sites/phlex/src/Auth/
```

---

## Git Workflow

```bash
cd /home/sites/phlex
git checkout -b step-4.1-authentication
git add .
git commit -m "Step 4.1: Implement JWT authentication system"
unset GITHUB_TOKEN
gh pr create --title "Step 4.1: Authentication System" --body "Implements JWT authentication with JwtHandler, UserRepository, AuthManager, and AuthController."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 4.2: Session Management** (`plans/phase-4/step-4.2-session-management.md`).
