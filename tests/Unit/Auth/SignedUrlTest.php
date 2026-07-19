<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\SignedUrl;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Auth\SignedUrl
 */
final class SignedUrlTest extends TestCase
{
    private const SECRET = 'unit-test-signing-secret';

    /** @var array<string, string|false> Saved env to restore in tearDown. */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PHLIX_SIGNED_URL_SECRET', 'JWT_SECRET', 'PHLIX_SIGNED_URL_TTL'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
        SignedUrl::resetSharedForTesting();
    }

    public function testSignatureIsDeterministic(): void
    {
        $signer = new SignedUrl(self::SECRET);

        $this->assertSame(
            $signer->signature('/media/abc/stream', 1000),
            $signer->signature('/media/abc/stream', 1000),
        );
    }

    public function testSignatureVariesByPathExpiryAndSecret(): void
    {
        $a = new SignedUrl(self::SECRET);
        $b = new SignedUrl('a-different-secret');

        $base = $a->signature('/media/abc/stream', 1000);

        $this->assertNotSame($base, $a->signature('/media/xyz/stream', 1000), 'path must affect signature');
        $this->assertNotSame($base, $a->signature('/media/abc/stream', 1001), 'expiry must affect signature');
        $this->assertNotSame($base, $b->signature('/media/abc/stream', 1000), 'secret must affect signature');
    }

    public function testSignatureIsUrlSafeBase64(): void
    {
        $sig = (new SignedUrl(self::SECRET))->signature('/media/abc/stream', 1000);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $sig);
    }

    public function testMintAppendsExpAndSigWithQuerySeparator(): void
    {
        $url = (new SignedUrl(self::SECRET))->mint('/media/abc/stream', 3600, 1000);

        $this->assertStringStartsWith('/media/abc/stream?exp=4600&sig=', $url);
    }

    public function testMintPreservesExistingQueryString(): void
    {
        $signer = new SignedUrl(self::SECRET);
        $url = $signer->mint('/api/v1/photo/photos/p1/thumbnail?w=400&h=400&fit=cover', 3600, 1000);

        $this->assertStringContainsString('w=400&h=400&fit=cover&exp=4600&sig=', $url);

        // The signature must verify against the query-LESS path the middleware sees.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertTrue($signer->verify(
            '/api/v1/photo/photos/p1/thumbnail',
            (string) $q['exp'],
            (string) $q['sig'],
            1000,
        ));
    }

    public function testMintedUrlVerifies(): void
    {
        $signer = new SignedUrl(self::SECRET);
        $url = $signer->mint('/media/abc/stream', 3600, 1000);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */

        $this->assertTrue($signer->verify('/media/abc/stream', (string) $q['exp'], (string) $q['sig'], 1500));
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        $signer = new SignedUrl(self::SECRET);
        $sig = $signer->signature('/media/abc/stream', 1000);

        // now (1001) is past exp (1000).
        $this->assertFalse($signer->verify('/media/abc/stream', '1000', $sig, 1001));
        // exactly at exp is still valid.
        $this->assertTrue($signer->verify('/media/abc/stream', '1000', $sig, 1000));
    }

    public function testVerifyRejectsTamperedSignatureAndPath(): void
    {
        $signer = new SignedUrl(self::SECRET);
        $sig = $signer->signature('/media/abc/stream', 5000);

        $this->assertFalse($signer->verify('/media/abc/stream', '5000', $sig . 'x', 1000), 'tampered sig');
        $this->assertFalse($signer->verify('/media/OTHER/stream', '5000', $sig, 1000), 'tampered path');
        $this->assertFalse($signer->verify('/media/abc/stream', '6000', $sig, 1000), 'tampered exp');
    }

    public function testVerifyRejectsMissingOrNonNumericComponents(): void
    {
        $signer = new SignedUrl(self::SECRET);
        $sig = $signer->signature('/media/abc/stream', 5000);

        $this->assertFalse($signer->verify('/media/abc/stream', null, $sig, 1000));
        $this->assertFalse($signer->verify('/media/abc/stream', '5000', null, 1000));
        $this->assertFalse($signer->verify('/media/abc/stream', '5000', '', 1000));
        $this->assertFalse($signer->verify('/media/abc/stream', 'not-a-number', $sig, 1000));
        $this->assertFalse($signer->verify('/media/abc/stream', '50.0', $sig, 1000));
    }

    public function testHlsTokenIsPrefixScopedAcrossTheJobDirectory(): void
    {
        $signer = new SignedUrl(self::SECRET);
        $url = $signer->mint('/hls/job123/master.m3u8', 3600, 1000);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $exp = (string) $q['exp'];
        $sig = (string) $q['sig'];

        // One signature on the master URL authorises every sub-playlist/segment
        // under the same job directory...
        $this->assertTrue($signer->verify('/hls/job123/master.m3u8', $exp, $sig, 1000));
        $this->assertTrue($signer->verify('/hls/job123/stream_0.m3u8', $exp, $sig, 1000));
        $this->assertTrue($signer->verify('/hls/job123/segment_0_001.m4s', $exp, $sig, 1000));
        // ...but not a different job.
        $this->assertFalse($signer->verify('/hls/OTHER/segment_0_001.m4s', $exp, $sig, 1000));
    }

    public function testDashTokenIsPrefixScoped(): void
    {
        $signer = new SignedUrl(self::SECRET);
        $sig = $signer->signature('/dash/jobX/manifest.mpd', 5000);

        $this->assertTrue($signer->verify('/dash/jobX/0/segment_00001.m4s', '5000', $sig, 1000));
        $this->assertFalse($signer->verify('/dash/jobY/0/segment_00001.m4s', '5000', $sig, 1000));
    }

    public function testTimeshiftTokenIsPrefixScopedAcrossTheSessionDirectory(): void
    {
        // SV-3.1 f-c: the DVR timeshift buffer fans a signed playlist URL out into
        // seg_NNNNN.ts segment requests, so one signature on the playlist must
        // authorise every segment under the same session prefix (mirroring HLS).
        $signer = new SignedUrl(self::SECRET);
        $sig = $signer->signature('/livetv/timeshift/sess123/stream', 5000);

        $this->assertTrue($signer->verify('/livetv/timeshift/sess123/stream', '5000', $sig, 1000));
        $this->assertTrue($signer->verify('/livetv/timeshift/sess123/seg_00001.ts', '5000', $sig, 1000));
        // ...but not a different session, and not a DVR recording path (exact-bound).
        $this->assertFalse($signer->verify('/livetv/timeshift/OTHER/seg_00001.ts', '5000', $sig, 1000));
        $this->assertFalse($signer->verify('/livetv/recording/sess123/stream', '5000', $sig, 1000));
    }

    public function testCanonicalResourceStripsQueryAndScopesStreaming(): void
    {
        $signer = new SignedUrl(self::SECRET);

        $this->assertSame('/media/abc/stream', $signer->canonicalResource('/media/abc/stream?exp=1&sig=2'));
        $this->assertSame('/hls/job123', $signer->canonicalResource('/hls/job123/master.m3u8'));
        $this->assertSame('/dash/jobX', $signer->canonicalResource('/dash/jobX/0/segment_1.m4s'));
        // Timeshift collapses to the per-session prefix; recording stays exact.
        $this->assertSame(
            '/livetv/timeshift/sess123',
            $signer->canonicalResource('/livetv/timeshift/sess123/seg_00042.ts')
        );
        $this->assertSame(
            '/livetv/recording/rec1/stream',
            $signer->canonicalResource('/livetv/recording/rec1/stream')
        );
        $this->assertSame('/api/v1/books/b1/cover', $signer->canonicalResource('/api/v1/books/b1/cover'));
    }

    public function testFromEnvUsesExplicitSecretAndTtl(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        putenv('PHLIX_SIGNED_URL_TTL=100');
        SignedUrl::resetSharedForTesting();

        $signer = SignedUrl::fromEnv();
        $this->assertSame(100, $signer->defaultTtl());

        // Same secret as a hand-built signer → signatures match.
        $reference = new SignedUrl(self::SECRET);
        $this->assertSame($reference->signature('/media/abc/stream', 1000), $signer->signature('/media/abc/stream', 1000));
    }

    public function testFromEnvDerivesKeyFromJwtSecretWhenNoDedicatedSecret(): void
    {
        putenv('JWT_SECRET=the-jwt-secret');
        SignedUrl::resetSharedForTesting();

        $signer = SignedUrl::fromEnv();

        // The derived key is domain-separated from the raw JWT secret: a signer
        // built with the JWT secret verbatim must NOT match.
        $naive = new SignedUrl('the-jwt-secret');
        $this->assertNotSame(
            $naive->signature('/media/abc/stream', 1000),
            $signer->signature('/media/abc/stream', 1000),
        );
        // Default TTL applies when PHLIX_SIGNED_URL_TTL is unset.
        $this->assertSame(SignedUrl::DEFAULT_TTL, $signer->defaultTtl());
    }

    public function testRefreshArtworkUrlReSignsExpiredInternalUrl(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        // A signature that expired an hour ago.
        $expiredExp = time() - 3600;
        $expiredSig = $signer->signature('/api/v1/artwork/abc-123', $expiredExp);
        $stale = '/api/v1/artwork/abc-123?size=w500&exp=' . $expiredExp . '&sig=' . $expiredSig;

        // Sanity: the stale token no longer verifies.
        $this->assertFalse($signer->verify('/api/v1/artwork/abc-123', (string) $expiredExp, $expiredSig));

        $fresh = SignedUrl::refreshArtworkUrl($stale);
        $this->assertNotNull($fresh);
        parse_str((string) parse_url($fresh, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */

        // Fresh token: future expiry, verifies, size preserved, no stale/stray params.
        $this->assertGreaterThan(time(), (int) $q['exp']);
        $this->assertTrue($signer->verify('/api/v1/artwork/abc-123', $q['exp'], $q['sig']));
        $this->assertSame('w500', $q['size']);
        $this->assertSame(['size', 'exp', 'sig'], array_keys($q));
    }

    public function testRefreshArtworkUrlReSignsExpiredLogoUrl(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        // Title logos are cached locally and served at `?size=logo` (detail-only
        // MediaItemShaper::shapeDetail() field) — same expired-signature bug as
        // posters.
        $expiredExp = time() - 3600;
        $expiredSig = $signer->signature('/api/v1/artwork/abc-123', $expiredExp);
        $stale = '/api/v1/artwork/abc-123?size=logo&exp=' . $expiredExp . '&sig=' . $expiredSig;

        $fresh = SignedUrl::refreshArtworkUrl($stale);
        $this->assertNotNull($fresh);
        parse_str((string) parse_url($fresh, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertGreaterThan(time(), (int) $q['exp']);
        $this->assertTrue($signer->verify('/api/v1/artwork/abc-123', $q['exp'], $q['sig']));
        $this->assertSame('logo', $q['size']);
    }

    public function testRefreshArtworkUrlSignsUnsignedInternalUrl(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        // Stored poster_srcset entries carry NO exp/sig (built from relativePath()).
        $fresh = SignedUrl::refreshArtworkUrl('/api/v1/artwork/abc-123?size=w185');
        $this->assertNotNull($fresh);
        parse_str((string) parse_url($fresh, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertTrue($signer->verify('/api/v1/artwork/abc-123', $q['exp'], $q['sig']));
        $this->assertSame('w185', $q['size']);
    }

    public function testRefreshArtworkUrlPassesThroughExternalAndEmpty(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();

        $tmdb = 'https://image.tmdb.org/t/p/w500/abcDEF.jpg';
        $this->assertSame($tmdb, SignedUrl::refreshArtworkUrl($tmdb), 'external URLs are never signed');
        $this->assertNull(SignedUrl::refreshArtworkUrl(null));
        $this->assertSame('', SignedUrl::refreshArtworkUrl(''));
        // An internal path with NO size param is left untouched.
        $this->assertSame(
            '/api/v1/artwork/abc-123',
            SignedUrl::refreshArtworkUrl('/api/v1/artwork/abc-123')
        );
    }

    public function testRefreshArtworkSrcsetReSignsEachUrlKeepingDescriptors(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        $expiredExp = time() - 3600;
        $expiredSig = $signer->signature('/api/v1/artwork/abc-123', $expiredExp);
        // As stored: an unsigned w185 candidate + an expired w500 candidate.
        $srcset = '/api/v1/artwork/abc-123?size=w185 185w, '
            . '/api/v1/artwork/abc-123?size=w500&exp=' . $expiredExp . '&sig=' . $expiredSig . ' 500w';

        $fresh = SignedUrl::refreshArtworkSrcset($srcset);
        $this->assertNotNull($fresh);

        $descriptors = [];
        foreach (explode(', ', $fresh) as $candidate) {
            $sp = strrpos($candidate, ' ');
            $this->assertNotFalse($sp);
            $url = substr($candidate, 0, $sp);
            $descriptors[] = substr($candidate, $sp + 1);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $pq);
            /** @var array<string, string> $pq */
            $this->assertTrue(
                $signer->verify('/api/v1/artwork/abc-123', $pq['exp'] ?? null, $pq['sig'] ?? null),
                'each srcset URL re-verifies with a fresh signature'
            );
        }
        $this->assertSame(['185w', '500w'], $descriptors, 'width descriptors preserved');
    }

    public function testRefreshArtworkSrcsetPassesThroughExternalAndEmpty(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();

        $ext = 'https://image.tmdb.org/t/p/w185/x.jpg 185w, https://image.tmdb.org/t/p/w500/x.jpg 500w';
        $this->assertSame($ext, SignedUrl::refreshArtworkSrcset($ext));
        $this->assertNull(SignedUrl::refreshArtworkSrcset(null));
        $this->assertSame('', SignedUrl::refreshArtworkSrcset(''));
    }

    public function testFromEnvIsMemoisedUntilReset(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();

        $first = SignedUrl::fromEnv();
        $this->assertSame($first, SignedUrl::fromEnv());

        SignedUrl::resetSharedForTesting();
        $this->assertNotSame($first, SignedUrl::fromEnv());
    }
}
