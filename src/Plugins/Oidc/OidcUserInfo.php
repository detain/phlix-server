<?php

declare(strict_types=1);

namespace Phlex\Plugins\Oidc;

use Phlex\Shared\Auth\UserInfo as SharedUserInfo;

/**
 * OIDC-specific user information.
 *
 * Carries additional OIDC-specific claims alongside the standard
 * user info fields. Provides a toUserInfo() method to convert to
 * the shared UserInfo type.
 *
 * @package Phlex\Plugins\Oidc
 * @since 0.11.0
 */
final class OidcUserInfo
{
    /** @var string Provider-specific unique identifier (sub claim) */
    private string $externalId;

    /** @var string|null User's email address */
    private ?string $email;

    /** @var string|null Human-readable display name */
    private ?string $displayName;

    /** @var string|null URL to user's avatar */
    private ?string $avatarUrl;

    /** @var array<string, mixed> All provider-returned claims */
    private array $rawAttributes;

    /** @var string|null The subject claim */
    private ?string $subject;

    /** @var bool Whether email is verified */
    private bool $emailVerified;

    /** @var string|null User's locale */
    private ?string $locale;

    /**
     * @param string $externalId Provider-specific unique identifier
     * @param string|null $email User's email address
     * @param string|null $displayName Human-readable display name
     * @param string|null $avatarUrl URL to user's avatar
     * @param array<string, mixed> $rawAttributes All provider-returned claims
     * @param string|null $subject The OIDC subject claim
     * @param bool $emailVerified Whether email is verified
     * @param string|null $locale User's locale
     */
    public function __construct(
        string $externalId,
        ?string $email = null,
        ?string $displayName = null,
        ?string $avatarUrl = null,
        array $rawAttributes = [],
        ?string $subject = null,
        bool $emailVerified = false,
        ?string $locale = null,
    ) {
        $this->externalId = $externalId;
        $this->email = $email;
        $this->displayName = $displayName;
        $this->avatarUrl = $avatarUrl;
        $this->rawAttributes = $rawAttributes;
        $this->subject = $subject;
        $this->emailVerified = $emailVerified;
        $this->locale = $locale;
    }

    /**
     * Create from ID token claims.
     *
     * @param IdTokenClaims $claims
     * @return self
     */
    public static function fromIdTokenClaims(IdTokenClaims $claims): self
    {
        $displayName = $claims->name;
        if ($displayName === null) {
            $givenName = $claims->givenName ?? '';
            $familyName = $claims->familyName ?? '';
            if ($givenName !== '' || $familyName !== '') {
                $displayName = trim($givenName . ' ' . $familyName);
            }
        }

        return new self(
            externalId: $claims->sub,
            email: $claims->email,
            displayName: $displayName,
            avatarUrl: $claims->picture,
            rawAttributes: $claims->rawClaims,
            subject: $claims->sub,
            emailVerified: $claims->emailVerified,
            locale: $claims->locale,
        );
    }

    /**
     * Convert to the shared UserInfo type.
     *
     * @return SharedUserInfo
     */
    public function toUserInfo(): SharedUserInfo
    {
        return new SharedUserInfo(
            externalId: $this->externalId,
            email: $this->email,
            displayName: $this->displayName,
            avatarUrl: $this->avatarUrl,
            rawAttributes: $this->rawAttributes,
        );
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawAttributes(): array
    {
        return $this->rawAttributes;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function hasEmail(): bool
    {
        return $this->email !== null;
    }

    public function hasDisplayName(): bool
    {
        return $this->displayName !== null;
    }

    public function hasAvatarUrl(): bool
    {
        return $this->avatarUrl !== null;
    }

    /**
     * Get a claim from rawAttributes.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getClaim(string $name, mixed $default = null): mixed
    {
        return $this->rawAttributes[$name] ?? $default;
    }
}
