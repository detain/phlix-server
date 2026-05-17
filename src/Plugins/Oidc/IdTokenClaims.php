<?php

declare(strict_types=1);

namespace Phlex\Plugins\Oidc;

/**
 * Immutable value object representing validated ID token claims.
 *
 * @package Phlex\Plugins\Oidc
 * @since 0.11.0
 */
final readonly class IdTokenClaims
{
    /** @var string Subject (unique user identifier) */
    public string $sub;

    /** @var string Issuer */
    public string $iss;

    /** @var string|array<string> Audience */
    public string|array $aud;

    /** @var int Expiration time */
    public int $exp;

    /** @var int Issued at time */
    public int $iat;

    /** @var string|null Nonce (if present) */
    public ?string $nonce;

    /** @var string|null User's email */
    public ?string $email;

    /** @var bool Whether email is verified */
    public bool $emailVerified;

    /** @var string|null User's display name */
    public ?string $name;

    /** @var string|null User's given name */
    public ?string $givenName;

    /** @var string|null User's family name */
    public ?string $familyName;

    /** @var string|null User's profile picture URL */
    public ?string $picture;

    /** @var string|null User's locale */
    public ?string $locale;

    /** @var array<string, mixed> All raw claims */
    public array $rawClaims;

    /**
     * @param array<string, mixed> $claims
     */
    public static function fromArray(array $claims): self
    {
        $sub = isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : '';
        $iss = isset($claims['iss']) && is_string($claims['iss']) ? $claims['iss'] : '';
        $audRaw = $claims['aud'] ?? '';
        if (is_array($audRaw)) {
            /** @var array<string> $aud */
            $aud = array_values(array_filter($audRaw, 'is_string'));
        } elseif (is_string($audRaw)) {
            $aud = $audRaw;
        } else {
            $aud = '';
        }
        $exp = isset($claims['exp']) && is_numeric($claims['exp']) ? (int) $claims['exp'] : 0;
        $iat = isset($claims['iat']) && is_numeric($claims['iat']) ? (int) $claims['iat'] : 0;
        $nonce = isset($claims['nonce']) && is_string($claims['nonce']) ? $claims['nonce'] : null;
        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null;
        $emailVerified = isset($claims['email_verified']) && $claims['email_verified'] === true;
        $name = isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null;
        $givenName = isset($claims['given_name']) && is_string($claims['given_name']) ? $claims['given_name'] : null;
        $familyName = isset($claims['family_name']) && is_string($claims['family_name']) ? $claims['family_name'] : null;
        $picture = isset($claims['picture']) && is_string($claims['picture']) ? $claims['picture'] : null;
        $locale = isset($claims['locale']) && is_string($claims['locale']) ? $claims['locale'] : null;

        return new self(
            sub: $sub,
            iss: $iss,
            aud: $aud,
            exp: $exp,
            iat: $iat,
            nonce: $nonce,
            email: $email,
            emailVerified: $emailVerified,
            name: $name,
            givenName: $givenName,
            familyName: $familyName,
            picture: $picture,
            locale: $locale,
            rawClaims: $claims,
        );
    }

    /**
     * @param string|array<string> $aud
     * @param array<string, mixed> $rawClaims
     */
    private function __construct(
        string $sub,
        string $iss,
        string|array $aud,
        int $exp,
        int $iat,
        ?string $nonce,
        ?string $email,
        bool $emailVerified,
        ?string $name,
        ?string $givenName,
        ?string $familyName,
        ?string $picture,
        ?string $locale,
        array $rawClaims,
    ) {
        $this->sub = $sub;
        $this->iss = $iss;
        $this->aud = $aud;
        $this->exp = $exp;
        $this->iat = $iat;
        $this->nonce = $nonce;
        $this->email = $email;
        $this->emailVerified = $emailVerified;
        $this->name = $name;
        $this->givenName = $givenName;
        $this->familyName = $familyName;
        $this->picture = $picture;
        $this->locale = $locale;
        $this->rawClaims = $rawClaims;
    }

    /**
     * Get a claim by name.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getClaim(string $name, mixed $default = null): mixed
    {
        return $this->rawClaims[$name] ?? $default;
    }

    /**
     * Check if the token has a specific claim.
     *
     * @param string $name
     * @return bool
     */
    public function hasClaim(string $name): bool
    {
        return isset($this->rawClaims[$name]);
    }
}
