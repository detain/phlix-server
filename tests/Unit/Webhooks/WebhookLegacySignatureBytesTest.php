<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use DateTimeImmutable;
use Phlix\Shared\Security\Hmac;
use Phlix\Webhooks\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * Wave-S1 leg 1.10 (design M-07) characterization pins. The LEGACY webhook
 * signature scheme is frozen wire contract for already-deployed receivers, so
 * its exact bytes are pinned here INDEPENDENT of the implementation: literal
 * digests captured at the parent (hand-rolled `hash_hmac`) build. The v0.56.0
 * adoption routes the two legacy sites through
 * {@see Hmac::compute()}/Hmac::verify() with SEPARATOR_EQUALS; these pins are
 * green before AND after the swap — that is exactly the law they encode — and
 * they redden permanently if prefix, separator, hex casing, or HMAC input
 * derivation ever drifts.
 *
 * The V2 `t=…,v1=…` construction intentionally stays raw hash_hmac: shared
 * Hmac models the whole `sha256<sep><hex>` header form, not a bare v1 field.
 */
final class WebhookLegacySignatureBytesTest extends TestCase
{
    private const T0 = '2026-01-01T00:00:00+00:00';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function legacyVectorProvider(): array
    {
        return [
            'ascii payload' => [
                '{"a":1}',
                'sk-1',
                'sha256=fc7cff07c2b79e8fbee0d7080d549f97a58a49b11bc9127058052a5d1e8f6aa8',
            ],
            'utf-8 payload' => [
                '{"évent":"ünïcode payload"}',
                's3cr3t-rotate',
                'sha256=5336009c2a216500b1de3d186c3fa8a43edc4501aa1365997c4e14e623085328',
            ],
            'empty payload' => [
                '',
                'sk-1',
                'sha256=1a9e3c34b64bf2ecd8bc33f4ceedfa4288f1876fff30cf73e23f7a3e507b1182',
            ],
        ];
    }

    /**
     * @dataProvider legacyVectorProvider
     */
    public function testVerifyAcceptsTheFrozenLegacyBytes(string $body, string $secret, string $signature): void
    {
        self::assertTrue(
            WebhookEvent::verify($secret, $body, null, $signature),
            'frozen legacy header must verify',
        );
    }

    public function testVerifyRejectsTamperedLegacyBytes(): void
    {
        $secret = 'sk-1';
        $good = 'sha256=fc7cff07c2b79e8fbee0d7080d549f97a58a49b11bc9127058052a5d1e8f6aa8';

        self::assertFalse(WebhookEvent::verify($secret, '{"a":2}', null, $good), 'payload tamper');
        self::assertFalse(WebhookEvent::verify($secret, '{"a":1}', null, strtoupper($good)), 'hex case flips to reject');
        self::assertFalse(
            WebhookEvent::verify($secret, '{"a":1}', null, 'sha256:' . substr($good, 7)),
            'colon separator flips to reject',
        );
    }

    public function testSharedHmacWithEqualsSeparatorReproducesTheFrozenFormula(): void
    {
        foreach (self::legacyVectorProvider() as $case) {
            [$body, $secret, $signature] = $case;
            self::assertSame(
                'sha256=' . hash_hmac('sha256', $body, $secret),
                Hmac::compute($body, $secret, Hmac::SEPARATOR_EQUALS),
                $case['0'] ?? 'formula',
            );
            self::assertSame($signature, Hmac::compute($body, $secret, Hmac::SEPARATOR_EQUALS));
            self::assertTrue(Hmac::verify($body, $signature, $secret, Hmac::SEPARATOR_EQUALS));
        }
    }

    /**
     * The public producer path: getSignature() must emit exactly the frozen
     * literal for a constructed event (its serializedPayload is the HMAC input).
     */
    public function testGetSignatureEmitsFrozenLegacyLiteral(): void
    {
        $event = new WebhookEvent(
            'media.item.updated',
            ['a' => 1],
            new DateTimeImmutable(self::T0),
        );
        $body = $event->serializedPayload();
        $secret = 'sk-1';

        self::assertSame(
            'sha256=' . hash_hmac('sha256', $body, $secret),
            $event->getSignature($secret),
        );
        self::assertTrue(
            WebhookEvent::verify($secret, $body, null, $event->getSignature($secret)),
            'producer/consumer legacy round trip',
        );
        self::assertTrue(
            Hmac::verify($body, $event->getSignature($secret), $secret, Hmac::SEPARATOR_EQUALS),
        );
    }
}
