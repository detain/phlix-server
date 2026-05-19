<?php

declare(strict_types=1);

namespace Phlex\Auth;

use Phlex\Auth\WebAuthn\WebAuthnManager;
use Phlex\Shared\Auth\AuthResult;
use Phlex\Shared\Auth\ProviderInterface;
use Phlex\Shared\Auth\UserInfo;

final class WebAuthnProvider implements ProviderInterface
{
    private WebAuthnManager $webauthnManager;

    public function __construct(WebAuthnManager $webauthnManager)
    {
        $this->webauthnManager = $webauthnManager;
    }

    public function name(): string
    {
        return 'webauthn';
    }

    public function supportsAuthentication(array $credentials): bool
    {
        return isset($credentials['username'])
            && isset($credentials['challenge'])
            && isset($credentials['credential']);
    }

    public function authenticate(array $credentials): AuthResult
    {
        try {
            $username = $credentials['username'] ?? null;
            $challenge = $credentials['challenge'] ?? null;
            $credential = $credentials['credential'] ?? null;

            if (
                !is_string($username) || $username === ''
                || !is_string($challenge) || $challenge === ''
                || !is_array($credential)
            ) {
                return new AuthResult(
                    success: false,
                    error: 'missing_required_fields'
                );
            }

            $result = $this->webauthnManager->finishAuthentication(
                $username,
                $credential,
                $challenge
            );

            return $result;
        } catch (\InvalidArgumentException $e) {
            return new AuthResult(
                success: false,
                error: $e->getMessage()
            );
        } catch (\Throwable $e) {
            return new AuthResult(
                success: false,
                error: 'webauthn_authentication_failed'
            );
        }
    }

    public function getUserInfo(string $externalId): ?UserInfo
    {
        $parts = explode(':', $externalId, 2);
        if (count($parts) !== 2 || $parts[0] !== 'webauthn') {
            return null;
        }

        return null;
    }

    public function linkAccount(string $localUserId, array $externalIds): void
    {
    }
}
