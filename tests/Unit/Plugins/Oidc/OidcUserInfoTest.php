<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Oidc;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Oidc\IdTokenClaims;
use Phlix\Plugins\Oidc\OidcUserInfo;

/**
 * @covers \Phlix\Plugins\Oidc\OidcUserInfo
 */
final class OidcUserInfoTest extends TestCase
{
    public function test_from_id_token_claims(): void
    {
        $claims = IdTokenClaims::fromArray([
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'email' => 'test@example.com',
            'email_verified' => true,
            'name' => 'Test User',
            'given_name' => 'Test',
            'family_name' => 'User',
            'picture' => 'https://example.com/photo.jpg',
            'locale' => 'en-US',
        ]);

        $userInfo = OidcUserInfo::fromIdTokenClaims($claims);

        $this->assertSame('user123', $userInfo->getExternalId());
        $this->assertSame('test@example.com', $userInfo->getEmail());
        $this->assertSame('Test User', $userInfo->getDisplayName());
        $this->assertSame('https://example.com/photo.jpg', $userInfo->getAvatarUrl());
        $this->assertSame('user123', $userInfo->getSubject());
        $this->assertTrue($userInfo->isEmailVerified());
        $this->assertSame('en-US', $userInfo->getLocale());
    }

    public function test_from_id_token_claims_builds_display_name_from_given_and_family_name(): void
    {
        $claims = IdTokenClaims::fromArray([
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]);

        $userInfo = OidcUserInfo::fromIdTokenClaims($claims);

        $this->assertSame('John Doe', $userInfo->getDisplayName());
    }

    public function test_get_raw_attributes(): void
    {
        $claims = IdTokenClaims::fromArray([
            'sub' => 'user123',
            'iss' => 'https://example.com',
            'aud' => 'client-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'custom' => 'value',
        ]);

        $userInfo = OidcUserInfo::fromIdTokenClaims($claims);

        $this->assertSame('value', $userInfo->getRawAttributes()['custom']);
        $this->assertArrayHasKey('sub', $userInfo->getRawAttributes());
    }

    public function test_constructor_with_all_parameters(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'oidc.user123',
            email: 'test@example.com',
            displayName: 'Test User',
            avatarUrl: 'https://example.com/avatar.png',
            rawAttributes: ['sub' => 'user123', 'email' => 'test@example.com'],
            subject: 'user123',
            emailVerified: true,
            locale: 'en',
        );

        $this->assertSame('oidc.user123', $userInfo->getExternalId());
        $this->assertSame('test@example.com', $userInfo->getEmail());
        $this->assertSame('Test User', $userInfo->getDisplayName());
        $this->assertSame('https://example.com/avatar.png', $userInfo->getAvatarUrl());
        $this->assertSame('user123', $userInfo->getSubject());
        $this->assertTrue($userInfo->isEmailVerified());
        $this->assertSame('en', $userInfo->getLocale());
    }

    public function test_user_info_has_email(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'user123',
            email: 'test@example.com',
        );

        $this->assertTrue($userInfo->hasEmail());
    }

    public function test_user_info_without_email(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'user123',
        );

        $this->assertFalse($userInfo->hasEmail());
    }

    public function test_user_info_has_display_name(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'user123',
            displayName: 'Test User',
        );

        $this->assertTrue($userInfo->hasDisplayName());
    }

    public function test_user_info_without_display_name(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'user123',
        );

        $this->assertFalse($userInfo->hasDisplayName());
    }

    public function test_user_info_has_avatar_url(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'user123',
            avatarUrl: 'https://example.com/avatar.png',
        );

        $this->assertTrue($userInfo->hasAvatarUrl());
    }

    public function test_user_info_without_avatar_url(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'user123',
        );

        $this->assertFalse($userInfo->hasAvatarUrl());
    }

    public function test_get_claim_from_raw_attributes(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'user123',
            rawAttributes: ['custom_claim' => 'custom_value'],
        );

        $this->assertSame('custom_value', $userInfo->getClaim('custom_claim'));
        $this->assertSame('default', $userInfo->getClaim('nonexistent', 'default'));
    }

    public function test_to_user_info(): void
    {
        $userInfo = new OidcUserInfo(
            externalId: 'oidc.user123',
            email: 'test@example.com',
            displayName: 'Test User',
            avatarUrl: 'https://example.com/avatar.png',
            rawAttributes: ['sub' => 'user123'],
        );

        $sharedUserInfo = $userInfo->toUserInfo();

        $this->assertSame('oidc.user123', $sharedUserInfo->externalId);
        $this->assertSame('test@example.com', $sharedUserInfo->email);
        $this->assertSame('Test User', $sharedUserInfo->displayName);
        $this->assertSame('https://example.com/avatar.png', $sharedUserInfo->avatarUrl);
    }
}
