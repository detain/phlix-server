<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\RateLimit;

use Phlix\Common\RateLimit\RateLimitProfiles;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15(d): the per-surface rate-limit catalogue is a fixed, stable set of
 * exactly the previously-UNLIMITED server auth surfaces, with the documented
 * container ids, config keys, defaults, and backend classification. `login` is
 * DELIBERATELY absent (it keeps DbLoginRateLimitStore). S81 added the seventh
 * surface, `pin_verify` (the self-service PIN oracle closure); S518 added four
 * more (`quick_connect_initiate` / `_status` / `_approve`, `telemetry_heartbeat`
 * — the AD-25 pairing legs and the AD-27 client tick).
 */
final class RateLimitProfilesTest extends TestCase
{
    /**
     * Exactly eleven surfaces, no more, no fewer — and NO `login` profile.
     */
    public function testCatalogueHasExactlyElevenSurfaces(): void
    {
        $defaults = RateLimitProfiles::defaults();

        self::assertCount(11, $defaults);

        $ids = array_keys($defaults);
        self::assertContains(RateLimitProfiles::REGISTER, $ids);
        self::assertContains(RateLimitProfiles::REFRESH, $ids);
        self::assertContains(RateLimitProfiles::WEBAUTHN_START, $ids);
        self::assertContains(RateLimitProfiles::WEBAUTHN_FINISH, $ids);
        self::assertContains(RateLimitProfiles::JWKS, $ids);
        self::assertContains(RateLimitProfiles::WS_CONNECT, $ids);
        self::assertContains(RateLimitProfiles::PIN_VERIFY, $ids);
        self::assertContains(RateLimitProfiles::QUICK_CONNECT_INITIATE, $ids);
        self::assertContains(RateLimitProfiles::QUICK_CONNECT_STATUS, $ids);
        self::assertContains(RateLimitProfiles::QUICK_CONNECT_APPROVE, $ids);
        self::assertContains(RateLimitProfiles::TELEMETRY_HEARTBEAT, $ids);

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
        self::assertSame('rate_limiter.pin_verify', RateLimitProfiles::PIN_VERIFY);
        self::assertSame(
            'rate_limiter.quick_connect_initiate',
            RateLimitProfiles::QUICK_CONNECT_INITIATE
        );
        self::assertSame('rate_limiter.quick_connect_status', RateLimitProfiles::QUICK_CONNECT_STATUS);
        self::assertSame('rate_limiter.quick_connect_approve', RateLimitProfiles::QUICK_CONNECT_APPROVE);
        self::assertSame('rate_limiter.telemetry_heartbeat', RateLimitProfiles::TELEMETRY_HEARTBEAT);
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
            // S81: a 4-6 digit PIN is a small keyspace — deliberately tight.
            RateLimitProfiles::PIN_VERIFY      => ['key' => 'pin_verify',      'max' => 5,   'window' => 300],
            RateLimitProfiles::JWKS            => ['key' => 'jwks',            'max' => 120, 'window' => 60],
            RateLimitProfiles::WS_CONNECT      => ['key' => 'ws_connect',      'max' => 30,  'window' => 60],
            // S518: pairing legs span two devices/any worker (global budgets
            // mandatory) and telemetry is public; rationale in RateLimitProfiles.
            RateLimitProfiles::QUICK_CONNECT_INITIATE => ['key' => 'quick_connect_initiate', 'max' => 10,  'window' => 3600],
            RateLimitProfiles::QUICK_CONNECT_STATUS   => ['key' => 'quick_connect_status',   'max' => 120, 'window' => 60],
            RateLimitProfiles::QUICK_CONNECT_APPROVE  => ['key' => 'quick_connect_approve',  'max' => 30,  'window' => 3600],
            RateLimitProfiles::TELEMETRY_HEARTBEAT    => ['key' => 'telemetry_heartbeat',    'max' => 20,  'window' => 3600],
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
     * The DB-backed subset is exactly the nine brute-force / enumeration /
     * cross-device surfaces; jwks and ws_connect are NOT DB-backed.
     */
    public function testDbBackedSubsetIsTheBruteForceSurfaces(): void
    {
        $dbBacked = RateLimitProfiles::dbBacked();

        self::assertSame(
            [
                RateLimitProfiles::REGISTER,
                RateLimitProfiles::REFRESH,
                RateLimitProfiles::WEBAUTHN_START,
                RateLimitProfiles::WEBAUTHN_FINISH,
                RateLimitProfiles::PIN_VERIFY,
                RateLimitProfiles::QUICK_CONNECT_INITIATE,
                RateLimitProfiles::QUICK_CONNECT_STATUS,
                RateLimitProfiles::QUICK_CONNECT_APPROVE,
                RateLimitProfiles::TELEMETRY_HEARTBEAT,
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
        self::assertTrue(RateLimitProfiles::isDbBacked(RateLimitProfiles::PIN_VERIFY));

        self::assertFalse(RateLimitProfiles::isDbBacked(RateLimitProfiles::JWKS));
        self::assertFalse(RateLimitProfiles::isDbBacked(RateLimitProfiles::WS_CONNECT));

        self::assertFalse(RateLimitProfiles::isDbBacked('rate_limiter.unknown'));
    }
}
