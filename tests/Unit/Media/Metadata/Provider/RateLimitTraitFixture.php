<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Provider;

use Phlix\Media\Metadata\Provider\MusicMetadataProviderTrait;

/**
 * SV-4.5 test double that composes {@see MusicMetadataProviderTrait}, exposes
 * its protected/private seams, and defines a BASE_URL so host derivation
 * resolves to a stable bucket. Used by {@see MusicMetadataRateLimitTest}.
 */
final class RateLimitTraitFixture
{
    use MusicMetadataProviderTrait;

    /** @var string Target API base — host drives the rate-limit bucket key. */
    private const BASE_URL = 'https://ratelimit.test/api/v1';

    public function applyRateLimit(float $seconds): void
    {
        $this->rateLimit($seconds);
    }

    public function bucketKey(): string
    {
        $method = new \ReflectionMethod($this, 'rateLimitBucket');
        $method->setAccessible(true);

        return (string) $method->invoke($this);
    }

    public static function hostCap(): int
    {
        return self::RATE_LIMIT_HOST_CAP;
    }

    public static function resetRateLimiterState(): void
    {
        self::$hostLastRequestTime = [];
    }

    /**
     * @param array<string, float> $state
     */
    public static function setRateLimiterState(array $state): void
    {
        self::$hostLastRequestTime = $state;
    }

    /**
     * @return array<string, float>
     */
    public static function getRateLimiterState(): array
    {
        return self::$hostLastRequestTime;
    }
}
