<?php

/**
 * Phlix media server component: Common tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The executable half of the E-13 security-token policy: the raw pair
 * bin2hex(random_bytes(N)) may be CALLED from exactly one first-party
 * production file — src/Common/Token.php — plus a small, each-entry-
 * justified allowlist of NON-security-state mints (temp-file collision
 * suffixes, wire-vocabulary shapes). A new ad-hoc size-by-accident token
 * anywhere in src/ reddens this census, which is what stops the
 * twenty-eight-scattered-call-site history from re-forming after the
 * migration.
 *
 * Scope is src/ only, by design: tests mint adversarial fixtures directly,
 * and the census itself quotes these function names. Quoted strings and
 * comments never register — the scan works on PHP tokens, where those are
 * T_CONSTANT_ENCAPSED_STRING / T_COMMENT / T_DOC_COMMENT, not the bare
 * function-call token shape. (That is why the prose mentions in
 * TranscodeManager and the Dlna headers are correctly invisible here.)
 *
 * The walk pins the PAIR, not random_bytes() alone: an unwrapped
 * random_bytes() that never becomes a hex wire token is out of scope for
 * this law (it would belong to an entropy-source census, which
 * UuidOrderSeedFuzzTest already covers for the id family).
 */
final class TokenMintCensusTest extends TestCase
{
    /** The single policy class that owns the raw hex-token mint. */
    private const POLICY_CLASS_RELATIVE_PATH = 'Common/Token.php';

    /**
     * src/-relative files deliberately kept OUTSIDE Token, with the law that
     * justifies the exception. Every entry pays rent: if the site migrates
     * or disappears, test 3 reddens until the entry is removed, so the list
     * can never rot into a hiding place for a future ad-hoc mint.
     *
     * @var array<string, string>
     */
    private const ALLOWED_RAW_MINT_FILES = [
        'Auth/JwtHandler.php' => 'jti mint frozen to review-loop changes (SRV-1); shape-identical Token::jti() swap is the banked follow-up',
        'Dlna/ContentDirectory.php' => '6-byte DLNA object-id wire shape, not security state (CSPRNG already ratified by SRV-2.2)',
        'Dlna/DlnaDevice.php' => '6-byte DLNA device-serial wire shape, not security state (CSPRNG already ratified by SRV-2.2)',
        'Plugins/Installer/HttpInstaller.php' => 'temp extraction-dir collision suffix, not security state',
        'Media/Transcoding/EncodeSlotLedger.php' => 'slot temp-file collision suffixes, not security state (E-12 lane owns adoption to Token::tmpSuffix())',
        'Media/Transcoding/ProbeCache.php' => 'atomic-write temp suffix, not security state (E-14 Atomic lane territory)',
        'Media/Transcoding/FfmpegRunner.php' => 'temp-file collision suffixes, not security state (E-14 Atomic lane territory)',
        'Media/MediaAsset/MediaAssetJobStore.php' => 'atomic-write temp suffix, not security state (E-14 Atomic lane territory)',
        'Media/Storage/ImageResizer.php' => 'atomic-write temp suffix, not security state (E-14 Atomic lane territory)',
        'Media/Storage/AvatarStorage.php' => 'atomic-write temp suffix, not security state (E-14 Atomic lane territory)',
        'Media/Storage/ArtworkStorage.php' => 'atomic-write temp suffix, not security state (E-14 Atomic lane territory)',
        'Media/Metadata/ThemeMusic/ThemeMusicResolver.php' => 'atomic-write temp suffix, not security state (E-14 Atomic lane territory)',
        'Session/SyncPlay/SyncPlayBridge.php' => 'per-boot bridge tripwire secret; security state but outside this lane (16B) — named-generator follow-up row',
        'Server/Runtime/BootIdentity.php' => '8-byte process-label boot identity, observability only, gates nothing',
    ];

    /**
     * Files migrated to Token by E-13. Re-carrying a raw mint is a law
     * breach even if the file also holds an allowlisted exception (none do
     * today) — this list is the lane's permanent zero-residue promise.
     *
     * @var list<string>
     */
    private const MIGRATED_FILES = [
        'Admin/NewsletterSender.php',
        'Console/Commands/UserResetPasswordCommand.php',
        'Plugins/Github/Controller/GithubCallbackController.php',
        'Plugins/OAuth2/Pkce.php',
        'Plugins/OAuth2/StateCorrelation.php',
        'Plugins/Oidc/Controller/OidcCallbackController.php',
        'Plugins/Oidc/OidcProvider.php',
        'Server/Http/Controllers/Admin/AdminUserController.php',
        'Server/Http/Controllers/Admin/LastfmController.php',
        'Server/Http/Controllers/TraktOAuthController.php',
        'Session/SyncPlay/SyncPlayManager.php',
    ];

    /** Whether a token is skipped when looking for the significant neighbour. */
    private function isInsignificant(array|string $token): bool
    {
        return \is_array($token)
            && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private function significantBefore(array $tokens, int $index, int $skip): array|string|null
    {
        $seen = 0;
        for ($j = $index - 1; $j >= 0; $j--) {
            if ($this->isInsignificant($tokens[$j])) {
                continue;
            }
            if ($seen === $skip) {
                return $tokens[$j];
            }
            $seen++;
        }

        return null;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private function significantAfter(array $tokens, int $index): array|string|null
    {
        $count = \count($tokens);
        for ($k = $index + 1; $k < $count; $k++) {
            if (!$this->isInsignificant($tokens[$k])) {
                return $tokens[$k];
            }
        }

        return null;
    }

    /**
     * @return array<string, list<int>> map of src-relative path => random_bytes line numbers
     */
    private function collectRawHexMints(): array
    {
        $srcRoot = \dirname(__DIR__, 3) . '/src';
        $this->assertDirectoryExists($srcRoot, 'The census must scan the real production tree.');

        $hits = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            /** @var list<array{0:int,1:string,2:int}|string> $tokens */
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            $relative = \substr($file->getPathname(), \strlen($srcRoot) + 1);

            for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
                $token = $tokens[$i];
                $isInnerMint = \is_array($token) && $token[0] === T_STRING && $token[1] === 'random_bytes';
                if (!$isInnerMint) {
                    continue;
                }
                if ($this->significantAfter($tokens, $i) !== '(') {
                    continue;
                }
                // The call must be the direct argument of bin2hex():
                // random_bytes preceded by '(' preceded by T_STRING 'bin2hex'
                // (a leading \ on either name only changes the token class of
                // the outer word, and bin2hex is still the significant word).
                if ($this->significantBefore($tokens, $i, 0) !== '(') {
                    continue;
                }
                $outer = $this->significantBefore($tokens, $i, 1);
                $isHexWrapped = \is_array($outer)
                    && \in_array($outer[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && \substr($outer[1], -\strlen('bin2hex')) === 'bin2hex';
                if (!$isHexWrapped) {
                    continue;
                }

                $hits[$relative][] = $token[2];
            }
        }

        ksort($hits);

        return $hits;
    }

    public function test_raw_hex_token_mints_outside_the_policy_class_are_all_allowlisted(): void
    {
        $hits = $this->collectRawHexMints();

        $offenders = [];
        foreach ($hits as $relative => $lines) {
            if ($relative === self::POLICY_CLASS_RELATIVE_PATH) {
                continue;
            }
            if (isset(self::ALLOWED_RAW_MINT_FILES[$relative])) {
                continue;
            }
            $offenders[] = $relative . ':' . \implode(',', $lines);
        }

        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            'E-13 token-policy violation: bin2hex(random_bytes(...)) was minted outside '
                . 'src/' . self::POLICY_CLASS_RELATIVE_PATH . ' and outside the justified allowlist. '
                . 'Security state and wire tokens get a NAMED, size-pinned generator on Token '
                . '(state/pkceVerifier/resetToken/inviteToken/correlation/prefixed/jti/tmpSuffix); '
                . 'a genuinely non-security mint needs a one-line allowlist rationale like its neighbours.'
        );
    }

    public function test_the_policy_class_holds_the_raw_mint_it_pins_away(): void
    {
        $hits = $this->collectRawHexMints();

        $this->assertArrayHasKey(
            self::POLICY_CLASS_RELATIVE_PATH,
            $hits,
            'The scan must actually find Token\'s own hex() mint — an empty census here '
                . 'means the token walk broke, not that the law holds.'
        );
        $this->assertCount(
            1,
            $hits[self::POLICY_CLASS_RELATIVE_PATH],
            'Token centralises the entropy through exactly one private hex() mint; a '
                . 'different count means the class shape grew a second raw call.'
        );
    }

    public function test_every_allowlisted_exception_still_pays_rent(): void
    {
        $hits = $this->collectRawHexMints();

        $stale = [];
        foreach (\array_keys(self::ALLOWED_RAW_MINT_FILES) as $relative) {
            if (!isset($hits[$relative])) {
                $stale[] = $relative;
            }
        }

        sort($stale);
        $this->assertSame(
            [],
            $stale,
            'E-13 allowlist rot: these files no longer mint a raw hex token, so their '
                . 'allowlist rationale must be deleted (never left as a hiding place).'
        );
    }

    public function test_the_e13_migrated_sites_carry_no_raw_mint(): void
    {
        $hits = $this->collectRawHexMints();

        $regressions = [];
        foreach (self::MIGRATED_FILES as $relative) {
            if (isset($hits[$relative])) {
                $regressions[] = $relative . ':' . \implode(',', $hits[$relative]);
            }
        }

        $this->assertSame(
            [],
            $regressions,
            'E-13 zero-residue breach: these call sites were migrated to named Token '
                . 'generators and must never regain a raw bin2hex(random_bytes(...)) mint.'
        );
    }
}
