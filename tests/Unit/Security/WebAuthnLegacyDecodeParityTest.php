<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Security;

use Phlix\Auth\WebAuthn\WebAuthnManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Wave-S1 r1 CRITICAL-1 differential pin: the WebAuthn strict base64url decode
 * seam must behave EXACTLY like the ratified pre-adoption body (strtr →
 * explicit str_pad to a multiple of 4 → strict base64_decode → throw on
 * false/empty) on every shape — including the malformed-shape corpus the
 * adversarial review proved divergent when the seam was routed through the
 * shared Base64Url strict arm (which never pads, and whose strict decode
 * strips embedded whitespace before validating).
 *
 * Both directions are asserted over every vector: the legacy reference and
 * the live seam must return identical bytes OR throw identical ceremony
 * messages — a zero-divergence demand in [ok|throw, value] space, not just
 * happy-path acceptance. The named reviewer examples ('PQ=',
 * 'QMfGWkxws_NpmQn7CLi8auLgJw=', "p0\n", 'rKonUf5WjuO27GZZSy_\ntCo') are
 * pinned first-class; the shared arm is contrasted in
 * {@see test_sharedArmStillDiverges_whereTheRatifiedLawSits()} so the banked
 * v0.57 modernization proposal keeps a measured basis instead of folklore.
 * All expected bytes were measured from execution, never hand-computed.
 */
final class WebAuthnLegacyDecodeParityTest extends TestCase
{
    /** Message used for every seam call — parity includes the throw text. */
    private const CEREMONY_MESSAGE = 'Invalid credential ID encoding';

    /**
     * The ratified pre-adoption body, restored byte-identical. Reference
     * implementation for the differential — a copy locked here by test, not
     * an import (the live class IS the subject under test).
     */
    private static function legacyDecode(string $encoded): array
    {
        $normalized = strtr($encoded, '-_', '+/');
        $padded = str_pad($normalized, (int) (ceil(strlen($normalized) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false || $decoded === '') {
            return ['throw', self::CEREMONY_MESSAGE];
        }
        return ['ok', $decoded];
    }

    /**
     * Run the LIVE private static seam and classify the outcome identically.
     */
    private static function seamDecode(string $encoded): array
    {
        $method = new ReflectionMethod(WebAuthnManager::class, 'base64UrlDecode');
        try {
            $decoded = $method->invoke(null, $encoded, self::CEREMONY_MESSAGE);
            return ['ok', $decoded];
        } catch (\InvalidArgumentException $e) {
            return ['throw', $e->getMessage()];
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedShapeCorpus(): array
    {
        $vectors = [
            'reviewer-truncated-PQ' => 'PQ=',
            'reviewer-truncated-fido-like' => 'QMfGWkxws_NpmQn7CLi8auLgJw=',
            'reviewer-newline-short' => "p0\n",
            'reviewer-newline-fido-like' => 'rKonUf5WjuO27GZZSy_' . "\n" . 'tCo',
            'empty' => '',
            'single-char' => 'A',
            'two-chars' => 'QQ',
            'three-chars' => 'QQ=',
            'four-chars' => 'QQ==',
            'bangs' => '!!!!',
            'trailing-bang' => 'Zm9v!!!!',
            'embedded-null' => "\x00\x00\x00\x00",
            'utf8-acute' => "Zm9\xc3\xa9",
            'double-equals-head' => '==QQ',
            'equals-inside' => 'Q=Q=',
            'plus-slash-alphabet' => 'Zm9+v/w=',
        ];

        $bodyChars = ['A', 'Q', 'Z', 'm', '0', '9', '_', '-'];
        $tailChars = ['A', 'Q', '9', '_', '-', '=', '!', ' ', "\n", "\t", '+', '/'];

        // Exhaustive length-1..6 shapes: 2-char base64url body × tail classes,
        // covering every len%4 ∈ {0,1,2,3} edge incl single-'=' truncations
        // and embedded-whitespace forms (both reviewer divergence classes).
        foreach ($bodyChars as $b1) {
            foreach ($bodyChars as $b2) {
                foreach ($tailChars as $tail) {
                    $vectors['t1-' . $b1 . $b2 . $tail] = $b1 . $b2 . $tail;
                    $vectors['t2-' . $b1 . $b2 . $tail . $tail] = $b1 . $b2 . $tail . $tail;
                    $vectors['t3-' . $b1 . $b2 . $b2 . $tail] = $b1 . $b2 . $b2 . $tail;
                }
                $vectors['b-' . $b1 . $b2] = $b1 . $b2;
                $vectors['bbb-' . $b1 . $b2 . $b2] = $b1 . $b2 . $b2;
            }
        }

        // Whitespace-injection into canonical unpadded encodings of real key
        // material (the relaxation direction the reviewer measured at 2,264
        // vectors: embedded whitespace must be REJECTED, legacy-style).
        $seeds = [];
        for ($i = 0; $i < 60; $i++) {
            $seeds[] = random_bytes(1 + ($i % 24));
        }
        foreach ($seeds as $s => $bytes) {
            $canonical = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            $len = strlen($canonical);
            if ($len === 0) {
                continue;
            }
            foreach (["\n", ' ', "\t", "\r"] as $w => $ws) {
                $pos = ($s + $w) % $len;
                $vectors['inj-' . $s . '-' . $w] = substr($canonical, 0, $pos) . $ws . substr($canonical, $pos);
            }
        }

        // Canonical controls: padded and unpadded encodings of the same seeds
        // (the 450k-vector zero-diff class — must be byte-identical here too).
        foreach ($seeds as $s => $bytes) {
            $vectors['canon-unpadded-' . $s] = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            $vectors['canon-padded-' . $s] = strtr(base64_encode($bytes), '+/', '-_');
        }

        // PHPUnit datasets must be argument ARRAYS; the corpus is maintained as
        // name=>input above for readability and lifted once here.
        return array_map(static fn (string $v): array => [$v], $vectors);
    }

    /**
     * @dataProvider malformedShapeCorpus
     */
    public function test_live_seam_matches_ratified_legacy_body_both_directions(string $encoded): void
    {
        self::assertSame(
            self::legacyDecode($encoded),
            self::seamDecode($encoded),
            'Divergence on input ' . json_encode($encoded)
        );
    }

    /**
     * The shared strict arm still diverges from the ratified law exactly where
     * review r1 measured (both directions), so this seam stays un-delegated
     * until the v0.57 modernization ruling. Non-parity here is the REASON,
     * pinned with executed expectations (never hand-computed bytes).
     */
    public function test_sharedArmStillDiverges_whereTheRatifiedLawSits(): void
    {
        $shared = static fn (string $in): string => \Phlix\Shared\Security\Base64Url::decode($in, true);

        // (a) legacy-decodes / shared-throws: single-'=' truncation decodes to
        // a byte under legacy (measured: 'PQ=' -> 0x3D), empty under shared.
        self::assertSame(['ok', "\x3d"], self::legacyDecode('PQ='));
        self::assertSame('', $shared('PQ='));
        self::assertSame(
            ['ok', hex2bin('40c7c65a4c70b3f3699909fb08b8bc6ae2e027')],
            self::legacyDecode('QMfGWkxws_NpmQn7CLi8auLgJw=')
        );
        self::assertSame('', $shared('QMfGWkxws_NpmQn7CLi8auLgJw='));

        // (b) legacy-throws / shared-decodes: embedded whitespace is rejected
        // by the pad-then-strict legacy body, accepted (stripped) by shared.
        // Measured: both reviewer examples.
        self::assertSame(['throw', self::CEREMONY_MESSAGE], self::legacyDecode("p0\n"));
        self::assertSame("\xa7", $shared("p0\n"));
        self::assertSame(['throw', self::CEREMONY_MESSAGE], self::legacyDecode("Zm9v\n"));
        self::assertSame('foo', $shared("Zm9v\n"));

        // Canonical wire bytes agree (the never-at-stake class):
        self::assertSame(['ok', 'foo'], self::legacyDecode('Zm9v'));
        self::assertSame('foo', $shared('Zm9v'));
        self::assertSame(['ok', 'fo'], self::legacyDecode('Zm9'));
        self::assertSame('fo', $shared('Zm9'));
    }
}
