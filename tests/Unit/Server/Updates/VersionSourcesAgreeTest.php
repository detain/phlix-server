<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Updates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Version;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins EVERY version source in this repository to one another.
 *
 * ## Why this suite exists — it is not hypothetical
 *
 * On 2026-07-12 `c17bb9ec` ("Release v1.2.3") bumped `k8s/helm/phlix/Chart.yaml`
 * to 1.2.3, added a `"version": "1.2.3"` key to `composer.json`, and pushed the
 * annotated tag `v1.2.3` — but it never touched
 * {@see \Phlix\Common\Version::STRING}, which stayed at 1.2.2. Twenty-one
 * minutes later `c9f1f26c` ("update") reset the composer key to 1.0.0, and on
 * 2026-07-18 `f5375f7a` deleted the key outright because
 * `composer validate --strict` fails on it. The result was a repository whose
 * Helm chart said 1.2.3, whose running server reported 1.2.2, and whose release
 * script read a version out of a key that no longer existed.
 *
 * Nothing was red for three weeks. S74's `VersionMarkerFileTest` closed exactly
 * two of the sources (`VERSION` and the constant); this suite closes the rest,
 * so a repeat of `c17bb9ec` is a failing test rather than a silent divergence.
 *
 * ## The rule
 *
 * `src/Common/Version.php::STRING` is AUTHORITATIVE — it is what the API
 * reports, what `PluginLoader` enforces `phlix_min_server_version` against, and
 * what the S74 update check compares the published marker to. Every other
 * source is a mirror written by `scripts/release.sh`.
 *
 * `composer.json` is the one deliberate NON-source: it must carry no `version`
 * field at all, because the `composer-validate` CI job runs
 * `composer validate --strict --no-check-publish`, which exits non-zero when
 * the field is present.
 *
 * @package Phlix\Tests\Unit\Server\Updates
 */
final class VersionSourcesAgreeTest extends TestCase
{
    /**
     * The authoritative source, relative to the repository root.
     */
    private const AUTHORITATIVE_FILE = 'src/Common/Version.php';

    /**
     * Every file that mirrors the authoritative constant and is therefore
     * rewritten by `scripts/release.sh`, relative to the repository root.
     *
     * This list is asserted to match the script's own list — see
     * {@see self::testTheReleaseScriptManagesExactlyTheseSources()} — so a new
     * version source cannot be added to one and forgotten in the other.
     *
     * @var list<string>
     */
    private const MIRROR_FILES = [
        'VERSION',
        'k8s/helm/phlix/Chart.yaml',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::repoRoot() . '/' . $relative);
        self::assertIsString($contents, $relative . ' must be readable');

        return $contents;
    }

    /**
     * Every mirrored version value in the repository, keyed by a label naming
     * the file and field it came from.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function mirroredVersionProvider(): array
    {
        $root = dirname(__DIR__, 4);

        /** @var mixed $chart */
        $chart = Yaml::parseFile($root . '/k8s/helm/phlix/Chart.yaml');
        self::assertIsArray($chart, 'Chart.yaml must parse as a YAML mapping');

        return [
            'VERSION (the published update marker)' => [
                'VERSION',
                trim((string) file_get_contents($root . '/VERSION')),
            ],
            'k8s/helm/phlix/Chart.yaml -> version' => [
                'k8s/helm/phlix/Chart.yaml (version:)',
                $chart['version'] ?? null,
            ],
            'k8s/helm/phlix/Chart.yaml -> appVersion' => [
                'k8s/helm/phlix/Chart.yaml (appVersion:)',
                $chart['appVersion'] ?? null,
            ],
        ];
    }

    /**
     * Each mirror must be the exact string held by the authoritative constant.
     *
     * `assertIsString` is load-bearing: `version: 1.2` in Chart.yaml parses as
     * a YAML float, and a float that stringifies close enough would otherwise
     * slip past an equality check.
     */
    #[DataProvider('mirroredVersionProvider')]
    public function testEveryMirroredSourceEqualsTheAuthoritativeConstant(string $label, mixed $actual): void
    {
        self::assertIsString(
            $actual,
            $label . ' must hold a quoted semver STRING, not a number or null',
        );

        self::assertSame(
            Version::STRING,
            $actual,
            $label . " is '" . $actual . "' but " . self::AUTHORITATIVE_FILE . "::STRING is '"
            . Version::STRING . "'. Every version source is bumped together by scripts/release.sh; "
            . 'a hand edit to one of them is how Chart.yaml sat at 1.2.3 for three weeks while the '
            . 'running server reported 1.2.2.',
        );
    }

    /**
     * The authoritative constant itself must be plain MAJOR.MINOR.PATCH —
     * `scripts/release.sh` parses it with an identical regex and refuses to
     * release anything it cannot parse.
     */
    public function testTheAuthoritativeConstantIsPlainSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            Version::STRING,
            self::AUTHORITATIVE_FILE . '::STRING must be MAJOR.MINOR.PATCH',
        );
    }

    /**
     * The constant in the FILE must be the constant PHP loaded.
     *
     * Reading `Version::STRING` alone cannot tell the difference between "the
     * file was bumped" and "an autoloaded stub was". `scripts/release.sh` edits
     * the text, so the text is what has to be pinned.
     */
    public function testTheConstantInTheFileMatchesTheLoadedConstant(): void
    {
        $source = self::read(self::AUTHORITATIVE_FILE);

        self::assertSame(
            1,
            preg_match("/public const STRING = '([^']*)';/", $source, $matches),
            self::AUTHORITATIVE_FILE . " must declare exactly one `public const STRING = '...';`",
        );

        self::assertSame(Version::STRING, $matches[1]);
    }

    /**
     * composer.json must declare NO `version` field.
     *
     * Measured on 2026-08-05 with composer 2.10.1:
     * `composer validate --strict --no-check-publish --no-check-lock` exits 1
     * with the field present and 0 without it, so re-adding it turns the
     * `composer-validate` CI job red. This is the trap the old release script
     * fell into from both sides — it read a key that did not exist, and its
     * `sed` would have written one back if it had.
     */
    public function testComposerJsonDeclaresNoVersionField(): void
    {
        /** @var mixed $decoded */
        $decoded = json_decode(self::read('composer.json'), true);
        self::assertIsArray($decoded, 'composer.json must be valid JSON');

        self::assertArrayNotHasKey(
            'version',
            $decoded,
            'composer.json must not declare a version field — `composer validate --strict` '
            . '(the composer-validate CI job) fails when it is present. The app version lives in '
            . self::AUTHORITATIVE_FILE . '::STRING.',
        );
    }

    /**
     * The release script's own list of managed files must equal this suite's.
     *
     * Without this, adding a fifth version source to the script (or to this
     * suite) would leave the other one blind, which is precisely the failure
     * mode being fixed.
     */
    public function testTheReleaseScriptManagesExactlyTheseSources(): void
    {
        $script = self::read('scripts/release.sh');

        preg_match_all('/^(?:VERSION_PHP|VERSION_MARKER|CHART)="([^"]+)"$/m', $script, $matches);
        $managed = $matches[1];
        sort($managed);

        $expected = array_merge([self::AUTHORITATIVE_FILE], self::MIRROR_FILES);
        sort($expected);

        self::assertSame(
            $expected,
            $managed,
            'scripts/release.sh must rewrite exactly the version sources this suite pins.',
        );
    }

    /**
     * Every managed file must actually be written AND committed by the script.
     *
     * Declaring a path in a variable proves nothing; the old script declared
     * `k8s/helm/phlix/Chart.yaml` and still never touched Version.php or
     * VERSION.
     */
    public function testTheReleaseScriptWritesAndCommitsEveryManagedSource(): void
    {
        $script = self::read('scripts/release.sh');

        self::assertStringContainsString(
            'sed -i "s/public const STRING = \'$VERSION\';/public const STRING = \'$NEW_VERSION\';/" "$VERSION_PHP"',
            $script,
            'the authoritative constant must be rewritten',
        );
        self::assertStringContainsString(
            'printf \'%s\n\' "$NEW_VERSION" > "$VERSION_MARKER"',
            $script,
            'the published VERSION marker must be rewritten',
        );
        self::assertStringContainsString('sed -i "s/^version:.*/version: $NEW_VERSION/" "$CHART"', $script);
        self::assertStringContainsString(
            'sed -i "s/^appVersion:.*/appVersion: \\"$NEW_VERSION\\"/" "$CHART"',
            $script,
        );
        self::assertStringContainsString(
            'git add "$VERSION_PHP" "$VERSION_MARKER" "$CHART"',
            $script,
            'every rewritten file must land in the release commit',
        );
    }

    /**
     * The script must read its current version from the authoritative source,
     * and must never write a composer.json version field.
     */
    public function testTheReleaseScriptReadsTheAuthoritativeSourceAndLeavesComposerAlone(): void
    {
        $script = self::read('scripts/release.sh');

        self::assertStringContainsString(
            'VERSION="$(sed -n "s/^[[:space:]]*public const STRING = \'\\([^\']*\\)\';.*/\\1/p" "$VERSION_PHP")"',
            $script,
            'the current version must be read from ' . self::AUTHORITATIVE_FILE,
        );

        self::assertStringNotContainsString(
            'sed -i "s/\\"version\\": ',
            $script,
            'the script must never write a version field into composer.json — '
            . '`composer validate --strict` fails on it',
        );
    }
}
