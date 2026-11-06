<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Installer;

use Phlix\Plugins\Installer\HttpInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins for the ONE controlled exception to the R20-02 redirect rejection:
 * {@see HttpInstaller::vettedGitHubArtifactRedirect()}, the pure header
 * predicate that recognises GitHub's canonical archive→codeload hop so
 * dev-channel catalog installs stop dying with "installing theme plugins from
 * the dev channel fails with HTTP redirects are not allowed (received
 * HTTP/1.1 302 ...)".
 *
 * PROVENANCE — real-network shapes measured at diagnosis (curl -sSI against
 * the live hosts, and the SAME single-302 chain surfaced by PHP's own wrapper
 * under streamFetchOptions()' exact options):
 *   https://github.com/detain/phlix-plugin-phantom-violet-theme/archive/HEAD.tar.gz
 *     -> HTTP/1.1 302 Found, Location: https://codeload.github.com/detain/phlix-plugin-phantom-violet-theme/tar.gz/c4d47ab53865c936dc8646c32e62c3fa276d7de7
 *   .../archive/master.zip
 *     -> HTTP/1.1 302 Found, Location: https://codeload.github.com/detain/phlix-plugin-phantom-violet-theme/zip/refs/heads/master
 *   codeload answers 200-direct; following the canonical Location yields
 *   sha256 d4dc2bb3e7b212fa3e33c2ac1e867d34dd30292c4b1f16a6e3a83797f112cb4f —
 *   the exact bytes the catalog artifactSha256 pins are computed over
 *   (CatalogEntry), re-verified through the SHIPPED logic at ship time.
 *
 * WHY NO LIVE-HOP E2E EXISTS IN THIS SUITE: downloadToTemp's hop only fires for
 * an ENTRY host of github.com/www.github.com, which no loopback `php -S`
 * fixture can answer (the estate's redirect e2e deliberately never touches
 * real network), and standing a test hook into the host check to fake one is
 * forbidden. The hop WIRING is therefore pinned three ways instead: this
 * accept/reject table against the real predicate, the unchanged rejection paths
 * in HttpInstallerRedirectE2eTest (which the mutation proofs show the hop
 * cannot dislodge), and the mutation matrix — removing the hop call or making
 * the predicate always-null reddens the accept rows (documented counts in
 * .reviews/2026-11-05/DEVHOP-SERVER.md), and the live-network proof ran
 * against the shipped code at ship gate with the digest assertion above.
 */
final class HttpInstallerGitHubHopTest extends TestCase
{
    private const GITHUB_SHA = 'c4d47ab53865c936dc8646c32e62c3fa276d7de7';

    /**
     * Run the private predicate the same reflection seam the sibling suites
     * use for downloadToTemp/assertNoRedirectResponses.
     */
    private function hop(array $responseHeaders, string $sourceUrl): ?string
    {
        $installer = new HttpInstaller(sys_get_temp_dir() . '/phlix_hop_' . uniqid('', true));
        $method = new \ReflectionMethod(HttpInstaller::class, 'vettedGitHubArtifactRedirect');
        $method->setAccessible(true);

        /** @var string|null $result */
        $result = $method->invoke($installer, $responseHeaders, $sourceUrl);

        return $result;
    }

    private static function entry(string $owner, string $repo, string $ref, string $kind, string $host = 'github.com'): string
    {
        return sprintf('https://%s/%s/%s/archive/%s.%s', $host, $owner, $repo, $ref, $kind);
    }

    private static function codeload(string $owner, string $repo, string $form, string $ref): string
    {
        return sprintf('https://codeload.github.com/%s/%s/%s/%s', $owner, $repo, $form, $ref);
    }

    /** @return array<string, array{0: list<string>, 1: string, 2: string}> */
    public static function acceptCases(): array
    {
        $themeRepo = 'phlix-plugin-phantom-violet-theme';

        return [
            // (a) the canonical HEAD shape: short ref -> 40-hex sha target.
            'canonical head 302' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA)],
                self::entry('detain', $themeRepo, 'HEAD', 'tar.gz'),
                self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA),
            ],
            // (b) branch ref with the leading-slash form GitHub uses.
            'refs/heads branch ref' => [
                ['HTTP/2 302 ', 'Location: ' . self::codeload('detain', $themeRepo, 'tar.gz', 'refs/heads/master')],
                self::entry('detain', $themeRepo, 'master', 'tar.gz'),
                self::codeload('detain', $themeRepo, 'tar.gz', 'refs/heads/master'),
            ],
            // (c) tag ref.
            'refs/tags tag ref' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload('detain', $themeRepo, 'tar.gz', 'refs/tags/v1.0.1')],
                self::entry('detain', $themeRepo, 'v1.0.1', 'tar.gz'),
                self::codeload('detain', $themeRepo, 'tar.gz', 'refs/tags/v1.0.1'),
            ],
            // (d) zip form: archive/master.zip -> /zip/refs/heads/master.
            'zip kind' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload('detain', $themeRepo, 'zip', 'refs/heads/master')],
                self::entry('detain', $themeRepo, 'master', 'zip'),
                self::codeload('detain', $themeRepo, 'zip', 'refs/heads/master'),
            ],
            // (e) www.github.com entry host.
            'www entry host' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA)],
                self::entry('detain', $themeRepo, 'HEAD', 'tar.gz', 'www.github.com'),
                self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA),
            ],
            // (11a) mixed-case owner in the Location vs lowercase entry.
            'mixed-case location owner' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload('DeTaIn', $themeRepo, 'tar.gz', self::GITHUB_SHA)],
                self::entry('detain', $themeRepo, 'HEAD', 'tar.gz'),
                self::codeload('DeTaIn', $themeRepo, 'tar.gz', self::GITHUB_SHA),
            ],
            // (11b) mixed-case from the entry side.
            'mixed-case entry owner' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA)],
                self::entry('DeTaIn', $themeRepo, 'HEAD', 'tar.gz'),
                self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA),
            ],
            // Location header case as some intermediaries fold it.
            'lowercase location header name' => [
                ['HTTP/2 302 ', 'location: ' . self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA)],
                self::entry('detain', $themeRepo, 'HEAD', 'tar.gz'),
                self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA),
            ],
            // Host compare is case-insensitive: a case-folded codeload host.
            // Also pins we return the RAW Location, never a reassembled URL.
            'case-folded codeload host' => [
                ['HTTP/1.1 302 Found', 'Location: https://CodeLoad.GitHub.Com/detain/' . $themeRepo . '/tar.gz/' . self::GITHUB_SHA],
                self::entry('detain', $themeRepo, 'HEAD', 'tar.gz'),
                'https://CodeLoad.GitHub.Com/detain/' . $themeRepo . '/tar.gz/' . self::GITHUB_SHA,
            ],
            // Case-folded ENTRY host likewise.
            'case-folded entry host' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA)],
                'https://GitHub.COM/detain/' . $themeRepo . '/archive/HEAD.tar.gz',
                self::codeload('detain', $themeRepo, 'tar.gz', self::GITHUB_SHA),
            ],
        ];
    }

    #[DataProvider('acceptCases')]
    public function testCanonicalGitHubHopsAreVetted(array $headers, string $entryUrl, string $expected): void
    {
        $this->assertSame($expected, $this->hop($headers, $entryUrl));
    }

    /**
     * Every un-vetted shape must return null so the UNCHANGED byte-identical
     * rejection in assertNoRedirectResponses() runs instead.
     *
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function rejectCases(): array
    {
        $owner = 'detain';
        $repo = 'phlix-plugin-phantom-violet-theme';
        $entry = self::entry($owner, $repo, 'HEAD', 'tar.gz');
        $good = self::codeload($owner, $repo, 'tar.gz', self::GITHUB_SHA);

        return [
            // (1) codeload host, different repo path.
            'other repo path' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload($owner, 'some-other-repo', 'tar.gz', self::GITHUB_SHA)],
                $entry,
            ],
            // (2a) foreign host.
            'evil host' => [
                ['HTTP/1.1 302 Found', 'Location: https://evil.example/' . $owner . '/' . $repo . '/tar.gz/' . self::GITHUB_SHA],
                $entry,
            ],
            // (2b) link-local metadata host — the exact SSRF target R20-02 exists for.
            'link-local metadata host' => [
                ['HTTP/1.1 302 Found', 'Location: http://169.254.169.254/latest/meta-data/'],
                $entry,
            ],
            // (3a) query smuggled onto the Location.
            'query present' => [['HTTP/1.1 302 Found', 'Location: ' . $good . '?a=1'], $entry],
            // (3b) fragment smuggled onto the Location.
            'fragment present' => [['HTTP/1.1 302 Found', 'Location: ' . $good . '#x'], $entry],
            // (4) TWO 3xx status lines — the wrapper followed something; not canonical.
            'two status lines in chain' => [
                ['HTTP/1.1 302 Found', 'HTTP/1.1 302 Found', 'Location: ' . $good],
                $entry,
            ],
            // (5) non-3xx status line carrying a Location.
            'non-3xx with location' => [['HTTP/1.1 200 OK', 'Location: ' . $good], $entry],
            // (6a) entry host not github.com.
            'entry on gitlab' => [
                ['HTTP/1.1 302 Found', 'Location: ' . $good],
                'https://gitlab.com/detain/phlix-plugin-phantom-violet-theme/-/archive/HEAD/phlix-plugin-phantom-violet-theme-HEAD.tar.gz',
            ],
            // (6b) entry on loopback (the redirect-e2e venue).
            'entry on loopback' => [
                ['HTTP/1.1 302 Found', 'Location: ' . $good],
                'https://127.0.0.1:8099/detain/phlix-plugin-phantom-violet-theme/archive/HEAD.tar.gz',
            ],
            // (7) entry not https.
            'entry over http' => [
                ['HTTP/1.1 302 Found', 'Location: ' . $good],
                'http://github.com/detain/phlix-plugin-phantom-violet-theme/archive/HEAD.tar.gz',
            ],
            // (8) entry path not archive-shaped.
            'entry is a release download' => [
                ['HTTP/1.1 302 Found', 'Location: ' . $good],
                'https://github.com/detain/phlix-plugin-phantom-violet-theme/releases/download/v1.0.1/pkg.tar.gz',
            ],
            // (9) empty ref remainder after the vetted prefix.
            'empty ref remainder' => [
                ['HTTP/1.1 302 Found', 'Location: https://codeload.github.com/' . $owner . '/' . $repo . '/tar.gz/'],
                $entry,
            ],
            // (10) Location scheme downgraded to http.
            'location over http' => [
                ['HTTP/1.1 302 Found', 'Location: http://codeload.github.com/' . $owner . '/' . $repo . '/tar.gz/' . self::GITHUB_SHA],
                $entry,
            ],
            // (12a) entry asks tar.gz, Location answers zip.
            'kind swap to zip' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload($owner, $repo, 'zip', self::GITHUB_SHA)],
                $entry,
            ],
            // (12b) entry asks zip, Location answers tar.gz.
            'kind swap to tar.gz' => [
                ['HTTP/1.1 302 Found', 'Location: ' . $good],
                self::entry($owner, $repo, 'master', 'zip'),
            ],
            // (13) two conflicting Location headers.
            'duplicate locations' => [
                ['HTTP/1.1 302 Found', 'Location: ' . $good, 'Location: ' . self::codeload($owner, $repo, 'tar.gz', 'refs/heads/evil')],
                $entry,
            ],
            // two IDENTICAL Locations — still ambiguous, exactly-one is the law.
            'duplicate identical locations' => [
                ['HTTP/1.1 302 Found', 'Location: ' . $good, 'Location: ' . $good],
                $entry,
            ],
            // userinfo on the Location host.
            'userinfo location' => [
                ['HTTP/1.1 302 Found', 'Location: https://p@evil.example/' . $owner . '/' . $repo . '/tar.gz/' . self::GITHUB_SHA],
                $entry,
            ],
            // port on the Location host.
            'ported location' => [
                ['HTTP/1.1 302 Found', 'Location: https://codeload.github.com:8443/' . $owner . '/' . $repo . '/tar.gz/' . self::GITHUB_SHA],
                $entry,
            ],
            // suffix-less zip path form (kind=zip entries answer /zip/...).
            'zip entry answered by tar.gz-style prefix' => [
                ['HTTP/1.1 302 Found', 'Location: ' . self::codeload($owner, $repo, 'tar.gz', 'refs/heads/master')],
                self::entry($owner, $repo, 'master', 'zip'),
            ],
            // the exact header arrays HttpInstallerTest feeds the UNCHANGED
            // rejection gate: the predicate must never steal them.
            'r20-02 rejection sample 302' => [
                ['HTTP/1.1 302 Found', 'Location: http://169.254.169.254/'],
                'https://plugin.test/pkg.zip',
            ],
            'r20-02 rejection sample chain' => [
                ['HTTP/1.1 301 Moved Permanently', 'HTTP/1.1 200 OK'],
                'https://plugin.test/pkg.zip',
            ],
            // no status lines at all (file:// transports).
            'empty chain' => [[], $entry],
        ];
    }

    #[DataProvider('rejectCases')]
    public function testUnvettedShapesNeverHop(array $headers, string $entryUrl): void
    {
        $this->assertNull($this->hop($headers, $entryUrl));
    }
}
