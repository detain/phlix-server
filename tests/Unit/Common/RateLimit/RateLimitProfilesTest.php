<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\RateLimit;

use Phlix\Common\RateLimit\RateLimitProfiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15(d): the per-surface rate-limit catalogue is a fixed, stable set of
 * exactly the six previously-UNLIMITED server auth surfaces, with the documented
 * container ids, config keys, defaults, and backend classification. `login` is
 * DELIBERATELY absent (it keeps DbLoginRateLimitStore).
 *
 */
final class RateLimitProfilesTest extends TestCase
{
    /**
     * Exactly six surfaces, no more, no fewer — and NO `login` profile.
     */
    public function testCatalogueHasExactlySixSurfaces(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertCount(6, $defaults);

        $ids = array_keys($defaults);
        self::assertContains(RateLimitProfiles::REGISTER, $ids);
        self::assertContains(RateLimitProfiles::REFRESH, $ids);
        self::assertContains(RateLimitProfiles::WEBAUTHN_START, $ids);
        self::assertContains(RateLimitProfiles::WEBAUTHN_FINISH, $ids);
        self::assertContains(RateLimitProfiles::JWKS, $ids);
        self::assertContains(RateLimitProfiles::WS_CONNECT, $ids);

        self::assertNotContains('rate_limiter.login', $ids);
    }

    /**
     * Container ids follow the `rate_limiter.<surface>` convention.
     */
    public function testContainerIdsAreNamespaced(): void
    {
        self::assertSame('rate_limiter.register', RateLimitProfiles::REGISTER);
        self::assertSame('rate_limiter.refresh', RateLimitProfiles::REFRESH);
        self::assertSame('rate_limiter.webauthn_start', RateLimitProfiles::WEBAUTHN_START);
        self::assertSame('rate_limiter.webauthn_finish', RateLimitProfiles::WEBAUTHN_FINISH);
        self::assertSame('rate_limiter.jwks', RateLimitProfiles::JWKS);
        self::assertSame('rate_limiter.ws_connect', RateLimitProfiles::WS_CONNECT);
    }

    /**
     * The `defaults()` shape is stable: every entry is `{key, max, window}` with
     * the documented per-surface thresholds.
     */
    public function testDefaultsShapeAndThresholds(): void
    {
        $expected = [
            RateLimitProfiles::REGISTER        => ['key' => 'register',        'max' => 5,   'window' => 600],
            RateLimitProfiles::REFRESH         => ['key' => 'refresh',         'max' => 30,  'window' => 60],
            RateLimitProfiles::WEBAUTHN_START  => ['key' => 'webauthn_start',  'max' => 10,  'window' => 60],
            RateLimitProfiles::WEBAUTHN_FINISH => ['key' => 'webauthn_finish', 'max' => 10,  'window' => 60],
            RateLimitProfiles::JWKS            => ['key' => 'jwks',            'max' => 120, 'window' => 60],
            RateLimitProfiles::WS_CONNECT      => ['key' => 'ws_connect',      'max' => 30,  'window' => 60],
        ];

        self::assertSame($expected, RateLimitProfiles::defaults());
    }

    /**
     * Each entry's config `key` is unique and matches the id's suffix.
     */
    public function testConfigKeysAreUniqueAndDerivedFromIds(): void
    {
        $keys = [];
        foreach (RateLimitProfiles::defaults() as $id => $spec) {
            $keys[] = $spec['key'];
            // The container id is `rate_limiter.<key>`.
            self::assertSame('rate_limiter.' . $spec['key'], $id);
        }

        self::assertSameSize($keys, array_unique($keys));
    }

    /**
     * The DB-backed subset is exactly the four brute-force / enumeration
     * surfaces; jwks and ws_connect are NOT DB-backed.
     */
    public function testDbBackedSubsetIsTheFourBruteForceSurfaces(): void
    {
        $dbBacked = RateLimitProfiles::dbBacked();

        self::assertSame(
            [
                RateLimitProfiles::REGISTER,
                RateLimitProfiles::REFRESH,
                RateLimitProfiles::WEBAUTHN_START,
                RateLimitProfiles::WEBAUTHN_FINISH,
            ],
            $dbBacked
        );

        // Every DB-backed id is a real profile id.
        foreach ($dbBacked as $id) {
            self::assertArrayHasKey($id, RateLimitProfiles::defaults());
        }
    }

    /**
     * `isDbBacked()` classifies each surface correctly, and an unknown id is
     * treated as in-memory (false).
     */
    public function testIsDbBackedClassification(): void
    {
        self::assertTrue(RateLimitProfiles::isDbBacked(RateLimitProfiles::REGISTER));
        self::assertTrue(RateLimitProfiles::isDbBacked(RateLimitProfiles::REFRESH));
        self::assertTrue(RateLimitProfiles::isDbBacked(RateLimitProfiles::WEBAUTHN_START));
        self::assertTrue(RateLimitProfiles::isDbBacked(RateLimitProfiles::WEBAUTHN_FINISH));

        self::assertFalse(RateLimitProfiles::isDbBacked(RateLimitProfiles::JWKS));
        self::assertFalse(RateLimitProfiles::isDbBacked(RateLimitProfiles::WS_CONNECT));

        self::assertFalse(RateLimitProfiles::isDbBacked('rate_limiter.unknown'));
    }
}
