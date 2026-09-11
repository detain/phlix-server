<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S474 — the server runtime images publish a DETERMINISTIC immutable tag.
 *
 * ## The defect this pins shut (measured, not imagined)
 *
 * Finding 5 of the S471 estate sweep: `docker.yml`'s runtime job published
 * ONLY the mutable matrix tags — `latest`, `intel`, `nvidia`. The
 * metadata-action step computed branch/semver/sha tags, but only its
 * `.labels` output was ever consumed; its `.tags` was wired to nothing. The
 * anonymous registry receipt confirmed it: `GET
 * /v2/detain/phlix-server/tags/list` returned exactly
 * `intel,nvidia,latest,buildcache-*` — zero sha/semver tags. Consumers had
 * nothing immutable to pin: the Helm chart's AppVersion fallback pointed at a
 * tag that had NEVER existed, and every example compose rode `:latest`.
 *
 * The fix wires a computed explicit tag list into the runtime build-push step
 * (the base job's "Compute base image tags" precedent in the same file — an
 * explicit comma-list, never a conditional line inside `tags: |`, so there is
 * never an empty entry whose handling depends on the action's input parsing):
 * each leg pushes `<registry>/<image>:<full-sha>-<variant>` next to its
 * mutable tag, so the three legs can never collide last-write-wins.
 *
 * ## What this guard is — and what it is NOT
 *
 * It is a WIRING change-detector over the workflow YAML. Deleting the
 * immutable half of the emitted list, inlining the tag back to the bare
 * matrix form, shortening the sha (which would make two commits that share a
 * short prefix collide), or quietly pointing the boot gate at registry tags
 * each turn RED here — in `vendor/bin/phpunit`, without waiting for a real
 * publish. It CANNOT prove the registry received the tags: that proof is the
 * post-merge anonymous tags/list receipt naming `<merge-sha>-{latest,intel,nvidia}`.
 *
 * If you intentionally change the tag scheme, update this test AND its
 * negative-control needles in the same diff — the mutations below are written
 * against the exact emitted lines, and a rewrite that moves them makes the
 * controls vacuous, which is the failure mode Law 3 exists to catch.
 */
final class ServerRuntimeImmutableTagsWiringTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/docker.yml';

    /** S474 survival token — code-resident class const, mirrored nowhere in markdown. */
    private const SURVIVAL_TOKEN = 'S474SHATAGX9N4';

    private const RUNTIMETAGS_STEP_ID = 'runtimetags';

    private const EXPECTED_CONSUMED_TAGS = '${{ steps.runtimetags.outputs.tags }}';

    private const EXPECTED_PUSH_GATE = "\${{ github.event_name != 'pull_request' }}";

    /**
     * Parse the workflow from an arbitrary path so mutants can be judged by
     * exactly the same code as the committed file.
     *
     * @return array<string, mixed>
     */
    private static function workflowFrom(string $path): array
    {
        $parsed = Yaml::parseFile($path);

        self::assertIsArray($parsed, "docker.yml did not parse to a mapping: {$path}");

        return $parsed;
    }

    /**
     * @param array<string, mixed> $workflow
     * @return array<string, mixed>|null
     */
    private static function jobOf(array $workflow, string $jobId): ?array
    {
        $job = $workflow['jobs'][$jobId] ?? null;

        return is_array($job) ? $job : null;
    }

    /**
     * The step with the given `id:` inside a job, or null.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>|null
     */
    private static function stepWithId(array $job, string $id): ?array
    {
        foreach (self::stepsOf($job) as $step) {
            if (($step['id'] ?? null) === $id) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The runtime publish step: the single `docker/build-push-action` inside
     * the `docker` job whose `with:` carries a `push` key. Scoped by JOB —
     * the boot-gate job also runs build-push-action (locally, `push: false`).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>|null
     */
    private static function runtimePublishStep(array $job): ?array
    {
        $matches = [];
        foreach (self::stepsOf($job) as $step) {
            $uses = (string) ($step['uses'] ?? '');
            $with = (array) ($step['with'] ?? []);
            if (str_starts_with($uses, 'docker/build-push-action@') && array_key_exists('push', $with)) {
                $matches[] = $step;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<string, mixed> $job
     * @return list<array<string, mixed>>
     */
    private static function stepsOf(array $job): array
    {
        $steps = [];
        foreach ((array) ($job['steps'] ?? []) as $step) {
            if (is_array($step)) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * Pure judge over an already-parsed workflow: every wiring invariant in
     * one place, so the negative control can apply THE SAME judgment the
     * committed file passes. Never asserts; never throws.
     *
     * @param array<string, mixed> $workflow
     */
    private static function wiringIsSound(array $workflow): bool
    {
        $job = self::jobOf($workflow, 'docker');
        if ($job === null) {
            return false;
        }

        $publish = self::runtimePublishStep($job);
        if ($publish === null) {
            return false;
        }

        $with = (array) ($publish['with'] ?? []);
        if (($with['tags'] ?? null) !== self::EXPECTED_CONSUMED_TAGS) {
            return false;
        }
        if (($with['push'] ?? null) !== self::EXPECTED_PUSH_GATE) {
            return false;
        }
        if (!str_contains((string) ($with['labels'] ?? ''), 'steps.meta.outputs.labels')) {
            return false;
        }

        $compute = self::stepWithId($job, self::RUNTIMETAGS_STEP_ID);
        if ($compute === null) {
            return false;
        }
        $env = (array) ($compute['env'] ?? []);
        if (($env['MATRIX_TAG'] ?? null) !== '${{ matrix.tag }}') {
            return false;
        }

        return self::runBodyEmitsBothTagForms((string) ($compute['run'] ?? ''));
    }

    /**
     * The compute step's shell body must build the mutable tag, build the
     * FULL-sha variant suffixed with the SAME per-leg matrix tag, and emit
     * both comma-joined, mutable first.
     */
    private static function runBodyEmitsBothTagForms(string $runBody): bool
    {
        $patterns = [
            '/MUTABLE="\$\{REGISTRY\}\/\$\{IMAGE_NAME\}:\$\{MATRIX_TAG\}"/',
            '/IMMUTABLE="\$\{REGISTRY\}\/\$\{IMAGE_NAME\}:\$\{GITHUB_SHA\}-\$\{MATRIX_TAG\}"/',
            '/echo "tags=\$\{MUTABLE\},\$\{IMMUTABLE\}" >> "\$GITHUB_OUTPUT"/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $runBody) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively collect every string in a YAML subtree (for the boot-gate
     * registry-consumption scan).
     *
     * @param mixed $node
     * @return list<string>
     */
    private static function stringsIn($node): array
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $out[] = $key;
                }
                $out = array_merge($out, self::stringsIn($value));
            }

            return $out;
        }

        return is_string($node) ? [$node] : [];
    }

    public function testTheGuardCarriesItsSurvivalTokenAsCode(): void
    {
        self::assertSame('S474SHATAGX9N4', self::SURVIVAL_TOKEN);

        $raw = (string) file_get_contents(__FILE__);
        self::assertSame(
            1,
            substr_count($raw, "private const SURVIVAL_TOKEN = '" . self::SURVIVAL_TOKEN . "';"),
            'the survival token must exist exactly once as the class-const declaration in this file'
        );
    }

    public function testTheWiringIsSoundOnTheCommittedWorkflow(): void
    {
        self::assertTrue(
            self::wiringIsSound(self::workflowFrom(self::WORKFLOW)),
            'the runtime publish wiring is not sound — see the granular assertions below for the leg'
        );
    }

    public function testTheRuntimePublishStepConsumesTheComputedTagList(): void
    {
        $job = self::jobOf(self::workflowFrom(self::WORKFLOW), 'docker');
        self::assertIsArray($job, 'docker job vanished from docker.yml');

        $publish = self::runtimePublishStep($job);
        self::assertIsArray(
            $publish,
            'the docker job must hold EXACTLY ONE build-push-action step with a push: key'
        );

        self::assertSame(
            self::EXPECTED_CONSUMED_TAGS,
            $publish['with']['tags'] ?? null,
            'the runtime build-push tags: must consume the runtimetags step output — an inline literal'
                . ' here is how the mutable-only publish crept back (S471 Finding 5)'
        );
    }

    public function testTheComputedListEmitsMutableThenFullShaVariant(): void
    {
        $job = self::jobOf(self::workflowFrom(self::WORKFLOW), 'docker');
        self::assertIsArray($job);

        $compute = self::stepWithId($job, self::RUNTIMETAGS_STEP_ID);
        self::assertIsArray($compute, 'the "Compute runtime image tags" step (id: runtimetags) vanished');

        self::assertArrayHasKey('MATRIX_TAG', (array) ($compute['env'] ?? []), 'MATRIX_TAG env vanished');
        self::assertSame('${{ matrix.tag }}', $compute['env']['MATRIX_TAG']);

        $run = (string) ($compute['run'] ?? '');
        self::assertMatchesRegularExpression(
            '/MUTABLE="\$\{REGISTRY\}\/\$\{IMAGE_NAME\}:\$\{MATRIX_TAG\}"/',
            $run,
            'the mutable tag form <registry>/<image>:<variant> is no longer constructed — it must keep'
                . ' publishing byte-identically next to the new immutable tag'
        );
        self::assertMatchesRegularExpression(
            '/IMMUTABLE="\$\{REGISTRY\}\/\$\{IMAGE_NAME\}:\$\{GITHUB_SHA\}-\$\{MATRIX_TAG\}"/',
            $run,
            'the immutable tag must be the FULL GITHUB_SHA suffixed with the per-variant matrix tag;'
                . ' shortening the sha invites two commits sharing a prefix to collide on one tag'
        );
        self::assertMatchesRegularExpression(
            '/echo "tags=\$\{MUTABLE\},\$\{IMMUTABLE\}" >> "\$GITHUB_OUTPUT"/',
            $run,
            'both tag forms must be emitted comma-joined, mutable first — dropping ${IMMUTABLE} here'
                . ' silently reverts the leg to the mutable-only defect'
        );
    }

    public function testThePublishGateAndLabelWiringAreUnchanged(): void
    {
        $job = self::jobOf(self::workflowFrom(self::WORKFLOW), 'docker');
        self::assertIsArray($job);

        $publish = self::runtimePublishStep($job);
        self::assertIsArray($publish);

        self::assertSame(
            self::EXPECTED_PUSH_GATE,
            $publish['with']['push'] ?? null,
            'PR runs must push NOTHING; the immutable tag changes what is published, never when'
        );
        self::assertStringContainsString(
            'steps.meta.outputs.labels',
            (string) ($publish['with']['labels'] ?? ''),
            'metadata labels stay wired to the meta step — switching them to meta .tags would'
                . ' publish the SAME bare sha from all three legs (last-write-wins collision)'
        );
    }

    public function testTheMutableMatrixLegsAreUnchanged(): void
    {
        $job = self::jobOf(self::workflowFrom(self::WORKFLOW), 'docker');
        self::assertIsArray($job);

        $legs = $job['strategy']['matrix']['include'] ?? null;
        self::assertIsArray($legs, 'the runtime matrix vanished');

        $byTag = [];
        foreach ($legs as $leg) {
            self::assertIsArray($leg);
            $byTag[(string) ($leg['tag'] ?? '')] = (string) ($leg['dockerfile'] ?? '');
        }
        ksort($byTag);

        self::assertSame(
            [
                'intel' => 'docker/Dockerfile.intel',
                'latest' => 'docker/Dockerfile',
                'nvidia' => 'docker/Dockerfile.nvidia',
            ],
            $byTag,
            'the mutable tag NAME SET and its dockerfiles are what every consumer pulls today; the'
                . ' immutable tags were added ALONGSIDE them, never in place of them'
        );
    }

    public function testTheBootGateStillConsumesNoRegistryTags(): void
    {
        $boot = self::jobOf(self::workflowFrom(self::WORKFLOW), 'docker-boot-gate');
        self::assertIsArray($boot, 'docker-boot-gate vanished from docker.yml');

        foreach (self::stepsOf($boot) as $step) {
            $with = (array) ($step['with'] ?? []);
            self::assertNotSame(
                true,
                $with['push'] ?? null,
                'the boot gate builds locally and boots what it built; a push:true there would'
                    . ' start shipping unvalidated artefacts to the registry'
            );
        }

        foreach (self::stringsIn($boot) as $string) {
            self::assertStringNotContainsString('env.REGISTRY', $string);
            self::assertStringNotContainsString('env.IMAGE_NAME', $string);
        }
    }

    public function testEveryPlantedRegressionIsCaughtByTheWiringJudge(): void
    {
        $raw = (string) file_get_contents(self::WORKFLOW);

        $base = self::workflowFrom(self::WORKFLOW);
        self::assertTrue(self::wiringIsSound($base), 'precondition: tip must judge sound');

        $mutants = [
            'immutable-half-stripped' => [
                'echo "tags=${MUTABLE},${IMMUTABLE}"',
                'echo "tags=${MUTABLE}"',
            ],
            'tags-reverted-to-inline-mutable' => [
                'tags: ${{ steps.runtimetags.outputs.tags }}',
                'tags: ${{ env.REGISTRY }}/${{ env.IMAGE_NAME }}:${{ matrix.tag }}',
            ],
            'sha-shortened-to-seven' => [
                '${GITHUB_SHA}-${MATRIX_TAG}',
                '${GITHUB_SHA::7}-${MATRIX_TAG}',
            ],
        ];

        $dir = sys_get_temp_dir() . '/s474-immutable-' . posix_getpid();
        if (!is_dir($dir)) {
            self::assertNotFalse(mkdir($dir, 0o700, true), "cannot create {$dir}");
        }

        try {
            foreach ($mutants as $name => [$needle, $replacement]) {
                self::assertSame(
                    1,
                    substr_count($raw, $needle),
                    "mutant '{$name}': needle not found exactly once in docker.yml — this control"
                        . ' went vacuous against a rewritten workflow, update the needles'
                );

                $file = $dir . "/{$name}.yml";
                self::assertNotFalse(
                    file_put_contents($file, str_replace($needle, $replacement, $raw)),
                    "cannot write mutant {$file}"
                );

                self::assertFalse(
                    self::wiringIsSound(self::workflowFrom($file)),
                    "mutant '{$name}' still judged sound — the guard cannot see the regression it names"
                );
            }
        } finally {
            foreach ((array) glob($dir . '/*.yml') as $f) {
                if (is_string($f)) {
                    unlink($f);
                }
            }
            @rmdir($dir);
        }
    }
}
