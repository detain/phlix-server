<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\SignedUrl;
use Phlix\Media\Library\MediaItemShaper;
use PHPUnit\Framework\TestCase;

final class SignedUrlTest extends TestCase
{
    private const SECRET = 'unit-test-signing-secret';

    /**
     * Merge-gate sentinel: this constant's VALUE must stay resident in the test
     * file's CODE (it is a live string operand of an executed assertion in
     * testS449RawBytesGuardRemainsCodeResidentNotJustComments()), never merely
     * in a comment or a docblock.
     */
    private const SURVIVAL_TOKEN = 'S449REMINTX7Q2';

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

    /**
     * S449 — sizes whose RAW bytes are NOT their own urldecoded form: percent-
     * encoded breakout characters (`%22` `%27` `%3C` `%3E`), encoded delimiters
     * (`%26` `%3D`), encoded spaces (`%20`), and the brief's verbatim payload.
     * Before S449 these were DECODED by parse_str() and the decoded bytes were
     * re-emitted RAW into a freshly SIGNED URL — the guard-before-decode bypass.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function decodeDriftSizeProvider(): iterable
    {
        yield 'encoded double quote' => [
            '/api/v1/artwork/v1?size=%22onmouseover%3Dalert(1)',
            '%22 decodes to a literal " — the shaper guard only ever saw it encoded',
        ];
        yield 'encoded single quote pair' => [
            '/api/v1/artwork/v2?size=%27evil%27',
            '%27…%27 decodes to a literal \'…\' attribute breakout',
        ];
        yield 'encoded angle brackets' => [
            '/api/v1/artwork/v3?size=%3Cscript%3E',
            '%3C/%3E decode to <script> angle brackets',
        ];
        yield 'encoded ampersand injects a raw pair' => [
            '/api/v1/artwork/v4?size=a%26b%3Dc&exp=1000000000&sig=stale',
            '%26b%3Dc decodes to `&b=c` — re-emitted raw it injects a NEW param into the signed URL',
        ];
        yield 'brief payload verbatim' => [
            '/api/v1/artwork/x?size=%22%20onmouseover=%27evil',
            'the stored breakout from the S449 brief, byte for byte',
        ];
    }

    /**
     * AC (hostile → rejected-as-passthrough): every decode-drift payload leaves
     * refreshArtworkUrl() EXACTLY as it entered — zero changed bytes, nothing
     * decoded, nothing re-minted. A stale signature on such a URL is inert and
     * the serving gate 401s it; a valid payload is never smuggled out signed.
     *
     * @dataProvider decodeDriftSizeProvider
     */
    public function testS449RefreshPassesHostileEncodedSizesThroughUntouched(string $stored, string $why): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();

        $this->assertSame(
            $stored,
            SignedUrl::refreshArtworkUrl($stored),
            $why . ' — the URL must pass through byte-identical'
        );
    }

    /**
     * AC (`+` → passthrough): `+` is a legal URL byte that urldecode() turns into
     * a SPACE — the pre-S449 code silently corrupted `size=a+b` into `size=a b`
     * on every re-mint. Byte identity refuses it; the stored bytes survive.
     */
    public function testS449RefreshPassesPlusBearingSizeThroughUntouched(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();

        $stale = '/api/v1/artwork/plus?size=a+b&exp=1000000000&sig=stale';
        $this->assertSame(
            $stale,
            SignedUrl::refreshArtworkUrl($stale),
            'a `+`-bearing raw size decodes to a space — decode drift must refuse the re-mint'
        );
    }

    /**
     * AC (double-encoded → passthrough): `%2522` decodes to `%22`, which decodes
     * again to `"`. One drift level per re-mint pass is still a decode→re-emit;
     * the first-level difference alone must trip the identity guard.
     */
    public function testS449RefreshPassesDoubleEncodedSizeThroughUntouched(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();

        $stale = '/api/v1/artwork/dbl?size=%2522&exp=1000000000&sig=stale';
        $this->assertSame(
            $stale,
            SignedUrl::refreshArtworkUrl($stale),
            'double-encoding drifts one level per pass — the URL must pass through byte-identical'
        );
    }

    /**
     * AC (behavioral consequence): a passthrough URL carries NO fresh signature.
     * Storing a correctly-signed-but-EXPIRED token under a hostile size, the
     * output keeps exactly the one stale pair — verify() against it still fails,
     * so the request dies at the gate with 401 instead of shipping signed
     * attacker-chosen query bytes.
     */
    public function testS449PassthroughOfHostileSizeKeepsOnlyTheInertStaleSignature(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        $staleExp = time() - 3600;
        $staleSig = $signer->signature('/api/v1/artwork/victim', $staleExp);
        $stale = '/api/v1/artwork/victim?size=%22x&exp=' . $staleExp . '&sig=' . $staleSig;

        $out = SignedUrl::refreshArtworkUrl($stale);
        $this->assertSame($stale, $out, 'the whole URL passes through unchanged');
        $this->assertSame(1, substr_count((string) $out, 'exp='), 'no second (fresh) token pair was minted');
        $this->assertFalse(
            $signer->verify('/api/v1/artwork/victim', (string) $staleExp, $staleSig),
            'and the carried token is expired — serving this URL 401s'
        );
    }

    /**
     * AC (regression pin for legit traffic): plain sizes keep re-minting over the
     * canonical `{path}?size={size}` with fresh exp/sig, stray params stripped,
     * and the size bytes in the RAW output identical to the RAW input — verified
     * both leading and trailing in the query, both without decode round-tripping.
     */
    public function testS449RefreshReMintsPlainSizesByteIdenticallyAndVerifies(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        $expiredExp = time() - 3600;
        $expiredSig = $signer->signature('/api/v1/artwork/abc-7', $expiredExp);
        $stale = '/api/v1/artwork/abc-7?size=w342&foo=bar&exp=' . $expiredExp . '&sig=' . $expiredSig;

        $fresh = SignedUrl::refreshArtworkUrl($stale);
        $this->assertIsString($fresh);
        // RAW-byte contract on the shipped URL (no parse_str — that decodes).
        $this->assertMatchesRegularExpression(
            '#^/api/v1/artwork/abc-7\?size=w342&exp=\d+&sig=[A-Za-z0-9_-]+$#',
            $fresh,
            'canonical plain size survives the re-mint verbatim, in first position, strays stripped'
        );
        parse_str((string) parse_url($fresh, PHP_URL_QUERY), $q);
        /** @var array<string, string> $q */
        $this->assertGreaterThan(time(), (int) $q['exp'], 'the token is fresh');
        $this->assertTrue($signer->verify('/api/v1/artwork/abc-7', $q['exp'], $q['sig']));

        // Same contract with the size LAST in the stored query.
        $expiredSig9 = $signer->signature('/api/v1/artwork/abc-9', $expiredExp);
        $trailing = SignedUrl::refreshArtworkUrl(
            '/api/v1/artwork/abc-9?exp=' . $expiredExp . '&sig=' . $expiredSig9 . '&size=logo'
        );
        $this->assertMatchesRegularExpression(
            '#^/api/v1/artwork/abc-9\?size=logo&exp=\d+&sig=[A-Za-z0-9_-]+$#',
            (string) $trailing,
            'a trailing plain size is extracted from raw bytes and re-minted canonically'
        );
    }

    /**
     * AC (benign encoded-but-decoded==raw): the guard is BYTE IDENTITY, not a
     * character blacklist — `%` sequences that decode() leaves alone (invalid
     * escapes, trailing `%`) are the stored bytes already and keep re-minting
     * with those exact bytes in the output.
     */
    public function testS449RefreshReMintsWhenDecodingIsAByteNoOp(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();
        $signer = SignedUrl::fromEnv();

        foreach (['invalid escape' => 'x%zz', 'trailing percent' => '100%'] as $label => $size) {
            $expiredExp = time() - 3600;
            $expiredSig = $signer->signature('/api/v1/artwork/noop', $expiredExp);
            $stale = '/api/v1/artwork/noop?size=' . $size . '&exp=' . $expiredExp . '&sig=' . $expiredSig;

            $fresh = SignedUrl::refreshArtworkUrl($stale);
            $this->assertIsString($fresh);
            $this->assertStringStartsWith(
                '/api/v1/artwork/noop?size=' . $size . '&exp=',
                $fresh,
                $label . ': urldecode() does not change these bytes, so the plain path re-mints them intact'
            );
            parse_str((string) parse_url($fresh, PHP_URL_QUERY), $q);
            /** @var array<string, string> $q */
            $this->assertTrue($signer->verify('/api/v1/artwork/noop', $q['exp'], $q['sig']));
        }
    }

    /**
     * AC (composition, end to end): the exact stored payload sails through the
     * REAL chain — MediaItemShaper::safeImageUrl (literal-char blacklist; the
     * %-encoded bytes pass it) → SignedUrl::refreshArtworkUrl. Before S449 the
     * re-mint DECODED `%22`/`%20`/`%27` and shipped a validly signed URL with a
     * literal quote breakout as `poster_url`. Now the emitted bytes are the
     * stored bytes: the guard is no longer defeated by a decode.
     */
    public function testS449ShaperChainLeavesEncodedBreakoutBytesUnchangedEndToEnd(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SECRET);
        SignedUrl::resetSharedForTesting();

        $stored = '/api/v1/artwork/x?size=%22%20onmouseover=%27evil';
        $shaped = MediaItemShaper::shape([
            'id' => 's449', 'name' => 'S449', 'type' => 'movie',
            'metadata' => ['poster_url' => $stored],
        ]);

        $this->assertSame(
            $stored,
            $shaped['poster_url'],
            'the shaper-emitted poster_url is the stored URL byte-for-byte — nothing decoded'
        );
        $this->assertStringNotContainsString(
            '"',
            (string) $shaped['poster_url'],
            'no literal double quote may leave the factory in a shaped URL'
        );
        $this->assertStringNotContainsString(
            "'",
            (string) $shaped['poster_url'],
            'no literal single quote may leave the factory in a shaped URL'
        );
    }

    /**
     * Merge gate: the raw-bytes extraction and the byte-identity refusal must
     * remain CODE-resident in SignedUrl::refreshArtworkUrl() — verified
     * structurally on the token stream with comments stripped, so a guard that
     * survives only inside a docblock cannot pass. The failure message carries
     * the survival token, making it a live string operand of an executed
     * assertion on every green run too.
     */
    public function testS449RawBytesGuardRemainsCodeResidentNotJustComments(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Auth/SignedUrl.php');
        $this->assertIsString($source, self::SURVIVAL_TOKEN . ': SignedUrl source must be readable');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        foreach (
            [
                "preg_match('/(?:^|&)size=([^&]*)/'",
                'urldecode($m[1])',
            ] as $guardFragment
        ) {
            $this->assertStringContainsString(
                $guardFragment,
                $code,
                self::SURVIVAL_TOKEN . ': comment-stripped code lost the raw-bytes guard fragment ' . $guardFragment
            );
        }

        // parse_str() re-entering this file would re-open the decode→re-emit bug.
        $this->assertStringNotContainsString(
            'parse_str(',
            $code,
            self::SURVIVAL_TOKEN . ': parse_str() must stay out of SignedUrl — its decode fed the re-mint breakout'
        );

        $this->assertSame(self::SURVIVAL_TOKEN, 'S449' . 'REMINT' . 'X7Q2');
    }
}
