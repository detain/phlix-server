<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Guard: the Helm charts and example composes must LOUDLY demand an explicit
 * immutable image tag — never a silent `:latest`, never a never-published
 * AppVersion fallback.
 *
 * ## The defect (findings 4 + 5 of the S471 image-pin sweep)
 *
 * Both charts shipped `image.tag` defaults that could not produce a working
 * pod. `phlix/values.yaml` said `tag: "latest"` (mutable: every `docker.yml`
 * push silently re-pointed the deployment at different bytes — ImagePullBackOff
 * you cannot bisect), while `templates/deployment.yaml` silently fell back to
 * `.Chart.AppVersion` — `1.2.3`, a tag that was NEVER published to ghcr (the
 * workflow only ever pushes `<full-sha>-<latest|intel|nvidia>` forms). One
 * default drifted, the other did not exist; both failed silently, one at
 * upgrade time, the other at first pull. The three example composes under
 * `docker/examples/` pinned bare `ghcr.io/...:latest`, so a "reproducible"
 * example was reproducible only until the next push.
 *
 * ## The fix this file holds in place
 *
 * Loud over silent: `values.yaml` ships `tag: ""` and `deployment.yaml` wraps
 * it in `{{ required '…form: <immutable shape>…' .Values.image.tag }}`, so
 * `helm template`/`helm install` aborts with a message naming the deterministic
 * tag form. The examples interpolate `${PHLIX_SERVER_IMAGE:?…}` /
 * `${PHLIX_HUB_IMAGE:?…}`, so `docker compose config` exits non-zero naming the
 * variable and the required form. This test keeps it that way: a walk over
 * `k8s/` and `docker/examples/` rejects ADJACENT `ghcr.io/<path>:latest`
 * literals in every file beneath those roots (census leg — catches NEW files,
 * not just edits to old ones), then per-file legs assert the
 * values/template/compose shapes that make the failure loud. The census regex
 * needs the repository and `:latest` next to each other, so split-form
 * references — `repository:` on one line, `tag: latest` on another — are
 * invisible to it; in YAML those are caught by the chart values legs, which
 * hold EVERY file in the closed glob enumeration over `k8s/helm/` (each
 * chart's `values.yaml` and `values.example.yaml`) to a ghcr.io/detain/phlix
 * `image.repository` plus an empty, therefore required, `tag`. The claim
 * honestly covers a split-form reference in any current or future chart values
 * file that matches that enumeration — a future chart is policed only once it
 * is enumerated ON PURPOSE, which the set assertion forces (an un-enumerated
 * new chart reddens before anyone can copy from it). Split-form references in
 * Markdown prose (e.g. `k8s/README.md`, until its docs are updated) remain a
 * disclosed KNOWN LIMIT policed by review, not by this guard.
 *
 * ## Anti-vacuity (S345 law 3)
 *
 * {@see self::testThePredicatesRejectEveryHistoricalDriftShape()} feeds the
 * predicates the exact pre-S475 shapes — bare `:latest` compose value, mutable
 * `tag: "latest"`, the never-published `tag: "v1.0.0"` default the example
 * values files shipped, the old `| default .Chart.AppVersion` template line, a
 * `required` line missing the immutable form string, a `${VAR}` interpolation
 * missing the `:?` clause, a `:?` message whose body lost the immutable form,
 * a hub service pinned to the server variable, and the silent fallback in its
 * parenthesized Go call form `(default "1.2.3" .Values.image.tag)` — and asserts
 * each is judged DRIFT. Mirrored positive controls (both shipped deployment
 * templates, the shipped compose pins, and a `required` line that merely spells
 * the word "default" inside its message prose) assert the predicates do not
 * over-reject. Any shape read the wrong way fails this test loudly by name.
 *
 * ## Known limits (honest scope)
 *
 * This guard covers the `k8s/` + `docker/examples/` consumer surface only.
 * The local-build alias `phlix-base:latest` (`docker/docker-compose.yml`) and
 * the `Dockerfile` base-image ARG are build-time references, deliberately out
 * of scope: they resolve on the developer's machine, not from a registry, so
 * "immutable" does not apply to them the same way.
 *
 * Split-form `latest` references in Markdown prose are a second disclosed
 * limit: `k8s/README.md` still pairs `repository: ghcr.io/detain/phlix-server`
 * with `tag: latest` on the next line and lists `latest` as the `image.tag`
 * default in its values table. The adjacency census cannot see a reference
 * split across lines, and the YAML values legs do not read Markdown. The prose
 * is policed by review until it is updated — not silently by this guard.
 *
 * @see tests/Unit/Support/ThirdPartyClonePinGuardTest.php (house style for pin guards)
 * @see tests/Unit/Support/WorkflowToolGateTest.php (house style for text-shape guards)
 */
final class ImagePinDriftGuardTest extends TestCase
{
    /**
     * Step marker token — lives ONLY in code (never in markdown). Proves this
     * exact file version is what CI ran (the merge ritual greps for it).
     */
    private const STEP_MARK = 'S475PINLUDEX9N5';

    /** Repository root, resolved relative to this file. */
    private const ROOT = __DIR__ . '/../../..';

    /** The only deterministic, immutable tag form the server workflow publishes. */
    private const SERVER_IMMUTABLE_FORM = 'ghcr.io/detain/phlix-server:<full-sha>-<latest|intel|nvidia>';

    /** The only immutable tag form the hub workflow publishes (short SHA). */
    private const HUB_IMMUTABLE_FORM = 'ghcr.io/detain/phlix-hub:<sha12>';

    private const SERVER_IMAGE_VAR = 'PHLIX_SERVER_IMAGE';

    private const HUB_IMAGE_VAR = 'PHLIX_HUB_IMAGE';

    /** Bare-registry-reference drift: `ghcr.io/<path>:latest` anywhere in a file. */
    private const DRIFT_PATTERN = '/ghcr\.io\/[A-Za-z0-9._\-\/]+:latest\b/';

    /** A compose image value that loudly demands a pinned env var. */
    private const COMPOSE_PIN_PATTERN = '/^\$\{(PHLIX_SERVER_IMAGE|PHLIX_HUB_IMAGE):\?(.+)\}$/';

    /**
     * Loose, enumeration-free shape of a loud compose pin — used by the
     * file-discovered pin census to count pin sites straight off the parsed
     * files, independent of the COMPOSE_PINS list below.
     */
    private const COMPOSE_PIN_DISCOVERY_PATTERN = '/^\$\{PHLIX_(?:SERVER|HUB)_IMAGE:\?.*\}$/';

    /** The one container-image line of a Helm deployment template. */
    private const IMAGE_LINE_PATTERN = '/^[ \t]*image:[ \t]*["\x27].*\.Values\.image\.repository.*["\x27][ \t]*$/m';

    /**
     * Silent-fallback veto #1, anchored on Go-template SYNTAX: a `| default`
     * pipe. Prose inside the `required` message may legally spell the word
     * "default" (e.g. "there is no default"); only the pipe is a fallback path.
     */
    private const TEMPLATE_DEFAULT_PIPE_PATTERN = '/\|\s*default\b/';

    /**
     * Silent-fallback veto #1b — the same `default` action in its OTHER Go
     * syntax: the parenthesized call form `(default "…" .Values.image.tag)`.
     * The pipe veto cannot see it, yet it falls back just as silently. Anchored
     * on `(` before the word, so prose spelling the bare word "default" stays
     * legal; a message gloss written as "(default …)" would be judged drift
     * (conservative, disclosed).
     */
    private const TEMPLATE_DEFAULT_CALL_PATTERN = '/\(\s*default\b/';

    /** Silent-fallback veto #2: any reference to `.Chart.AppVersion` (never-published tags). */
    private const TEMPLATE_APP_VERSION_PATTERN = '/\.Chart\.AppVersion\b/';

    /**
     * The consumer files this guard is responsible for (closed set).
     *
     * @var list<string>
     */
    private const TRACKED_FILES = [
        'k8s/helm/phlix/values.yaml',
        'k8s/helm/phlix/templates/deployment.yaml',
        'k8s/helm/phlix-hub/values.yaml',
        'k8s/helm/phlix-hub/templates/deployment.yaml',
        'docker/examples/server-only/docker-compose.yml',
        'docker/examples/server-hub/docker-compose.yml',
        'docker/examples/full-stack/docker-compose.yml',
    ];

    /**
     * Closed set of chart values files the glob values leg must discover — each
     * chart dir under `k8s/helm` contributing its shipped defaults and its
     * annotated example (sorted; a NEW chart appearing is a deliberate
     * enumeration change to this list, never an accident the guard shrugs off).
     *
     * @var list<string>
     */
    private const EXPECTED_CHART_VALUES_FILES = [
        'k8s/helm/phlix-hub/values.example.yaml',
        'k8s/helm/phlix-hub/values.yaml',
        'k8s/helm/phlix/values.example.yaml',
        'k8s/helm/phlix/values.yaml',
    ];

    /**
     * Directories walked in full by the census leg.
     *
     * @var list<string>
     */
    private const DRIFT_SCAN_ROOTS = [
        'k8s',
        'docker/examples',
    ];

    /**
     * Per-chart expectations: values path, template path, exact repository,
     * immutable tag form.
     *
     * @var array<string, array{values: string, template: string, repository: string, form: string}>
     */
    private const CHARTS = [
        'phlix' => [
            'values' => 'k8s/helm/phlix/values.yaml',
            'template' => 'k8s/helm/phlix/templates/deployment.yaml',
            'repository' => 'ghcr.io/detain/phlix-server',
            'form' => self::SERVER_IMMUTABLE_FORM,
        ],
        'phlix-hub' => [
            'values' => 'k8s/helm/phlix-hub/values.yaml',
            'template' => 'k8s/helm/phlix-hub/templates/deployment.yaml',
            'repository' => 'ghcr.io/detain/phlix-hub',
            'form' => self::HUB_IMMUTABLE_FORM,
        ],
    ];

    /**
     * Every compose service that consumes a ghcr image, with the env variable
     * and immutable form its `:?` message must name (hub services → HUB var,
     * server services → SERVER var — pinned anti-swap).
     *
     * @var array<string, list<array{service: string, variable: string, form: string}>>
     */
    private const COMPOSE_PINS = [
        'docker/examples/server-only/docker-compose.yml' => [
            ['service' => 'phlix', 'variable' => self::SERVER_IMAGE_VAR, 'form' => self::SERVER_IMMUTABLE_FORM],
        ],
        'docker/examples/server-hub/docker-compose.yml' => [
            ['service' => 'phlix-hub', 'variable' => self::HUB_IMAGE_VAR, 'form' => self::HUB_IMMUTABLE_FORM],
            ['service' => 'phlix-server', 'variable' => self::SERVER_IMAGE_VAR, 'form' => self::SERVER_IMMUTABLE_FORM],
        ],
        'docker/examples/full-stack/docker-compose.yml' => [
            ['service' => 'phlix-hub', 'variable' => self::HUB_IMAGE_VAR, 'form' => self::HUB_IMMUTABLE_FORM],
            ['service' => 'phlix-server', 'variable' => self::SERVER_IMAGE_VAR, 'form' => self::SERVER_IMMUTABLE_FORM],
        ],
    ];

    /** Total loud-pin sites expected across the three example composes. */
    private const EXPECTED_PIN_SITES = 5;

    // ------------------------------------------------------------------
    // Existence leg: the tracked consumer files are all present
    // ------------------------------------------------------------------

    public function testEveryTrackedConsumerFileStillExists(): void
    {
        foreach (self::TRACKED_FILES as $rel) {
            self::assertFileExists(
                self::ROOT . '/' . $rel,
                "Tracked image-pin consumer file vanished: {$rel}. Update " . self::class
                . '::TRACKED_FILES deliberately, never by accident. [' . self::STEP_MARK . ']'
            );
        }
    }

    // ------------------------------------------------------------------
    // Census leg: no bare ghcr `:latest` ANYWHERE under the consumer surface
    // ------------------------------------------------------------------

    public function testNoBareLatestDriftLiteralSurvivesAnywhereInTheConsumerSurface(): void
    {
        $violations = [];

        foreach (self::DRIFT_SCAN_ROOTS as $relRoot) {
            foreach (self::driftViolationsBeneath($relRoot) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame(
            [],
            $violations,
            'A bare ghcr.io/...:latest reference reappeared (mutable tags drift; the charts and '
            . 'examples must demand immutable pins loudly): ' . implode(', ', $violations)
            . ' [' . self::STEP_MARK . ']'
        );
    }

    // ------------------------------------------------------------------
    // Values legs: both charts ship an empty (therefore REQUIRED) tag
    // ------------------------------------------------------------------

    public function testChartValuesShipAnEmptyTagWithThePinnedRepositoryAndPullPolicy(): void
    {
        foreach (self::CHARTS as $name => $chart) {
            $document = self::parseYamlDocument($chart['values']);
            $image = self::imageBlock($document, $chart['values']);

            self::assertSame(
                $chart['repository'],
                self::stringKey($image, 'repository', $chart['values']),
                "image.repository drifted in {$chart['values']}. [" . self::STEP_MARK . ']'
            );
            self::assertSame(
                'IfNotPresent',
                self::stringKey($image, 'pullPolicy', $chart['values']),
                "image.pullPolicy drifted in {$chart['values']}. [" . self::STEP_MARK . ']'
            );
            self::assertTrue(
                self::chartTagIsForcedExplicit($image['tag'] ?? null),
                "image.tag in {$chart['values']} must be exactly \"\" so the template's required "
                . 'gate fires loudly — any non-empty default is either a mutable :latest or a '
                . 'never-published version tag. [' . self::STEP_MARK . ']'
            );
        }
    }

    /**
     * Glob-scoped values leg: EVERY chart values file under `k8s/helm` —
     * shipped `values.yaml` and annotated `values.example.yaml` alike — must
     * carry an `image:` mapping whose `repository` is a ghcr.io/detain/phlix
     * string and whose `tag` is empty. Round 1's blind spot was exactly the
     * example files: no leg read them, so they carried a never-published
     * `tag: "v1.0.0"` an operator would copy straight into a failing
     * `helm install`. The discovery glob closes that hole AND refuses silent
     * chart growth: a new chart appearing under `k8s/helm` reddens the set
     * assertion until it is enumerated on purpose. Because the enumerated files
     * are all ghcr consumers BY DEFINITION, a missing or malformed
     * `image.repository` here is not a skip condition but a failure: it is
     * precisely how a split-form pin (a tag with no repository to make it
     * adjacent) would dodge both this leg and the adjacency census.
     */
    public function testEveryChartValuesFileUnderK8sShipsAnEmptyTag(): void
    {
        $discovered = self::chartValuesFilesUnderK8s();
        $expected = self::EXPECTED_CHART_VALUES_FILES;
        sort($expected);

        self::assertSame(
            $expected,
            $discovered,
            'The chart values files discoverable under k8s/helm (values.yaml + values.example.yaml '
            . 'per chart) no longer match the closed enumeration. A new chart must be added to '
            . self::class . '::EXPECTED_CHART_VALUES_FILES deliberately — and its values files then '
            . 'held to the same empty-required-tag law. [' . self::STEP_MARK . ']'
        );

        foreach ($discovered as $rel) {
            $document = self::parseYamlDocument($rel);
            $image = self::imageBlock($document, $rel);
            $repository = self::stringKey($image, 'repository', $rel);

            if (!str_starts_with($repository, 'ghcr.io/detain/phlix')) {
                throw new RuntimeException(
                    'Image-pin guard: ' . $rel . ' is an enumerated ghcr consumer, so `image.repository` '
                    . 'must be a string starting ghcr.io/detain/phlix — found: ' . var_export($repository, true)
                    . '. A repository this leg cannot read is how a tag-only (split-form) pin escapes '
                    . 'both this law and the adjacency census.'
                );
            }

            self::assertTrue(
                self::chartTagIsForcedExplicit($image['tag'] ?? null),
                "image.tag in {$rel} must be exactly \"\" so the template's required gate fires "
                . 'loudly — a pre-filled example tag promises a value that was never published '
                . '(the v1.0.0-style semver tags never reached ghcr). Offending value: '
                . var_export($image['tag'] ?? null, true) . ' [' . self::STEP_MARK . ']'
            );
        }
    }

    // ------------------------------------------------------------------
    // Template legs: the image line fails loudly and names the form
    // ------------------------------------------------------------------

    public function testChartDeploymentTemplatesFailLoudlyWithoutAnExplicitTag(): void
    {
        foreach (self::CHARTS as $name => $chart) {
            $line = self::extractDeploymentImageLine(
                self::readRequiredFile($chart['template']),
                $chart['template']
            );

            self::assertTrue(
                self::deploymentImageLineIsLoud($line, $chart['form']),
                "The image line in {$chart['template']} must use `{{ required '…' .Values.image.tag }}` "
                . 'naming the immutable form `' . $chart['form'] . '`, and must not contain '
                . 'a `| default` pipe fallback or a `.Chart.AppVersion` reference — a silent '
                . 'fallback re-introduces the S471 never-published-tag defect. Offending line: '
                . $line . ' [' . self::STEP_MARK . ']'
            );
        }
    }

    // ------------------------------------------------------------------
    // Compose legs: every ghcr consumer interpolates a REQUIRED env var
    // ------------------------------------------------------------------

    public function testExampleComposesInterpolateRequiredImmutablePins(): void
    {
        $pinSites = 0;

        foreach (self::COMPOSE_PINS as $rel => $pins) {
            $document = self::parseYamlDocument($rel);
            $images = self::serviceImageStrings($document, $rel);

            foreach ($pins as $pin) {
                $service = $pin['service'];
                self::assertArrayHasKey(
                    $service,
                    $images,
                    "Example compose {$rel} no longer declares service `{$service}` — the pin "
                    . 'enumeration drifted from the file. [' . self::STEP_MARK . ']'
                );

                self::assertTrue(
                    self::composeImageValueIsPinned($images[$service], $pin['variable'], $pin['form']),
                    "Service `{$service}` in {$rel} must interpolate \${{$pin['variable']}:?…} with the "
                    . 'immutable form `' . $pin['form'] . '` inside the error message — got: '
                    . $images[$service] . ' [' . self::STEP_MARK . ']'
                );

                $pinSites++;
            }

            // No service may fall back to a literal registry reference. The
            // forms appear only INSIDE `:?` guidance text of a checked pin,
            // never as (or starting) a scalar value.
            $allowedBodies = [];
            foreach ($pins as $pin) {
                $allowedBodies[] = $images[$pin['service']];
            }

            foreach (self::collectScalarStrings($document) as $scalar) {
                self::assertFalse(
                    str_starts_with($scalar, 'ghcr.io/'),
                    "Parsed compose {$rel} still carries a bare ghcr.io literal image reference; "
                    . 'every ghcr consumer must interpolate a required env pin. [' . self::STEP_MARK . ']'
                );
                self::assertTrue(
                    !str_contains($scalar, 'ghcr.io') || in_array($scalar, $allowedBodies, true),
                    "Parsed compose {$rel} mentions ghcr.io outside the checked \${VAR:?…} pin bodies. ["
                    . self::STEP_MARK . ']'
                );
            }
        }

        self::assertSame(
            self::EXPECTED_PIN_SITES,
            $pinSites,
            'The loud-pin census expects exactly ' . self::EXPECTED_PIN_SITES . ' interpolation sites '
            . 'across the example composes. [' . self::STEP_MARK . ']'
        );

        // Second, INDEPENDENT census: count every parsed service image across
        // every example compose that looks like a loud `${PHLIX_*_IMAGE:?…}`
        // pin — discovered from the files themselves, never from the
        // COMPOSE_PINS enumeration above. A sixth pin site nobody enumerated
        // (or one silently deleted from the enumeration) reddens here, where
        // the enumeration loop alone would keep nodding along.
        $discoveredPinSites = 0;

        foreach (self::globRequired(self::ROOT . '/docker/examples/*/docker-compose.yml') as $absolute) {
            $composeRel = substr($absolute, strlen(self::ROOT) + 1);

            foreach (self::serviceImageStrings(self::parseYamlDocument($composeRel), $composeRel) as $image) {
                if (preg_match(self::COMPOSE_PIN_DISCOVERY_PATTERN, $image) === 1) {
                    $discoveredPinSites++;
                }
            }
        }

        self::assertSame(
            self::EXPECTED_PIN_SITES,
            $discoveredPinSites,
            'The file-discovered loud-pin census expects exactly ' . self::EXPECTED_PIN_SITES
            . ' `${PHLIX_(SERVER|HUB)_IMAGE:?…}` interpolations across the example composes. This count '
            . 'comes from the parsed files, not the COMPOSE_PINS list — a sixth un-enumerated pin site '
            . 'or a silently deleted one reddens here. [' . self::STEP_MARK . ']'
        );
    }

    // ------------------------------------------------------------------
    // Anti-vacuity leg (S345 law 3) — historical shapes MUST be judged drift
    // ------------------------------------------------------------------

    /**
     * Feed each predicate the exact pre-S475 shapes it exists to reject, plus
     * near-misses (required without the form, a :? body stripped of the form,
     * the never-published semver default the example values shipped) and the
     * variable-swap hazard. Any shape that slips through — in either direction,
     * drift-accepted or loud-rejected — names itself in the failure.
     */
    public function testThePredicatesRejectEveryHistoricalDriftShape(): void
    {
        $slipped = [];

        // 1. The pre-S475 compose value: a bare mutable ghcr reference.
        $bareLatestImage = 'ghcr.io/detain/phlix-server:latest';
        if (self::composeImageValueIsPinned($bareLatestImage, self::SERVER_IMAGE_VAR, self::SERVER_IMMUTABLE_FORM)) {
            $slipped[] = 'bare `ghcr.io/detain/phlix-server:latest` compose value accepted as pinned';
        }

        // 2. The pre-S475 chart default: a mutable tag value.
        if (self::chartTagIsForcedExplicit('latest')) {
            $slipped[] = 'mutable values tag `latest` accepted as forced-explicit';
        }

        // 2b. A never-published version tag (the old AppVersion fallback value).
        if (self::chartTagIsForcedExplicit('1.2.3')) {
            $slipped[] = 'never-published values tag `1.2.3` accepted as forced-explicit';
        }

        // 2c. A missing tag key (null) is not the same as an explicit empty one.
        if (self::chartTagIsForcedExplicit(null)) {
            $slipped[] = 'absent values tag (null) accepted as forced-explicit';
        }

        // 2d. The never-published semver default the values.example files shipped.
        if (self::chartTagIsForcedExplicit('v1.0.0')) {
            $slipped[] = 'never-published example-values tag `v1.0.0` accepted as forced-explicit';
        }

        // 3. The pre-S475 template line: silent `default .Chart.AppVersion` fallback.
        $oldTemplateLine = '          image: "{{ .Values.image.repository }}:'
            . '{{ .Values.image.tag | default .Chart.AppVersion }}"';
        if (self::deploymentImageLineIsLoud($oldTemplateLine, self::SERVER_IMMUTABLE_FORM)) {
            $slipped[] = 'old `| default .Chart.AppVersion` template line accepted as loud';
        }

        // 4. Near-miss template: required gate present but the immutable form is missing.
        $formlessTemplateLine = '          image: "{{ .Values.image.repository }}:'
            . "{{ required 'set image.tag please' .Values.image.tag }}\"";
        if (self::deploymentImageLineIsLoud($formlessTemplateLine, self::SERVER_IMMUTABLE_FORM)) {
            $slipped[] = 'required-without-immutable-form template line accepted as loud';
        }

        // 5. Interpolation without the `:?` error clause: resolves to empty, silently.
        $silentInterpolation = '${PHLIX_SERVER_IMAGE}';
        $silent = self::composeImageValueIsPinned(
            $silentInterpolation,
            self::SERVER_IMAGE_VAR,
            self::SERVER_IMMUTABLE_FORM
        );
        if ($silent) {
            $slipped[] = '`${PHLIX_SERVER_IMAGE}` interpolation missing the :? clause accepted as pinned';
        }

        // 6. Variable swap: a server-shaped pin serving a hub service.
        $serverShapedPin = '${PHLIX_SERVER_IMAGE:?PHLIX_SERVER_IMAGE must be set to an immutable pin, form '
            . self::SERVER_IMMUTABLE_FORM . '}';
        if (self::composeImageValueIsPinned($serverShapedPin, self::HUB_IMAGE_VAR, self::HUB_IMMUTABLE_FORM)) {
            $slipped[] = 'server-variable pin accepted for a hub service (variable swap)';
        }

        // 7. Correct variable, but the immutable form DELETED from the `:?`
        //    message body — the pin still fails loudly yet no longer names the
        //    only value that would satisfy it.
        $formlessComposePin = '${PHLIX_SERVER_IMAGE:?PHLIX_SERVER_IMAGE must be set}';
        if (self::composeImageValueIsPinned($formlessComposePin, self::SERVER_IMAGE_VAR, self::SERVER_IMMUTABLE_FORM)) {
            $slipped[] = 'compose pin whose :? message body lost the immutable form accepted as pinned';
        }

        // 8. Positive control for the syntax-anchored fallback veto: a required
        //    line whose MESSAGE merely spells the word "default" (no `| default`
        //    pipe, no `.Chart.AppVersion` reference) is still loud. A veto that
        //    matches prose instead of syntax would reject the very fix it
        //    guards, so this shape must be accepted.
        $proseDefaultTemplateLine = '          image: "{{ .Values.image.repository }}:'
            . '{{ required "there is no default; form: ghcr.io/detain/phlix-server:'
            . '<full-sha>-<latest|intel|nvidia>" .Values.image.tag }}"';
        if (!self::deploymentImageLineIsLoud($proseDefaultTemplateLine, self::SERVER_IMMUTABLE_FORM)) {
            $slipped[] = 'required line spelling "default" only inside its message prose judged as drift '
                . '(fallback veto collided with prose instead of anchoring on `| default` pipe / '
                . '`.Chart.AppVersion` syntax)';
        }

        // 9. The silent fallback in its OTHER Go syntax: the parenthesized call
        //    form `(default "1.2.3" .Values.image.tag)` wrapped around a
        //    `required` line that carries every other loud marker. A veto
        //    anchored only on the `| default` pipe accepts this line as loud
        //    while the chart still resolves a never-published tag.
        $callFormDefaultTemplateLine = '          image: "{{ .Values.image.repository }}:'
            . '{{ required "form: ghcr.io/detain/phlix-server:<full-sha>-<latest|intel|nvidia>" '
            . '(default "1.2.3" .Values.image.tag) }}"';
        if (self::deploymentImageLineIsLoud($callFormDefaultTemplateLine, self::SERVER_IMMUTABLE_FORM)) {
            $slipped[] = 'parenthesized `(default …)` call fallback inside a required line accepted as loud';
        }

        self::assertSame(
            [],
            $slipped,
            'ANTI-VACUITY: historical/pre-fix drift shapes slipped past the predicates, so the guard '
            . 'would run vacuously today: ' . implode('; ', $slipped) . ' [' . self::STEP_MARK . ']'
        );

        // Positive controls: the shipped files satisfy the same predicates — both
        // deployment templates, not just the one the defect story is told around.
        foreach (self::CHARTS as $chart) {
            $shippedLine = self::extractDeploymentImageLine(
                self::readRequiredFile($chart['template']),
                $chart['template']
            );
            self::assertTrue(
                self::deploymentImageLineIsLoud($shippedLine, $chart['form']),
                'ANTI-VACUITY: the shipped image line in ' . $chart['template'] . ' fails its own '
                . 'predicate. [' . self::STEP_MARK . ']'
            );
        }

        $shippedComposeImage = self::serviceImageStrings(
            self::parseYamlDocument('docker/examples/server-hub/docker-compose.yml'),
            'docker/examples/server-hub/docker-compose.yml'
        )['phlix-hub'];
        self::assertTrue(
            self::composeImageValueIsPinned($shippedComposeImage, self::HUB_IMAGE_VAR, self::HUB_IMMUTABLE_FORM),
            'ANTI-VACUITY: the shipped hub compose pin fails its own predicate. [' . self::STEP_MARK . ']'
        );
    }

    // ------------------------------------------------------------------
    // Predicates (small, reusable, side-effect-free)
    // ------------------------------------------------------------------

    /**
     * The chart-tag law: `image.tag` is the empty string — explicit that there
     * is no value, so the template's `required` gate is the only way forward.
     */
    private static function chartTagIsForcedExplicit(mixed $tag): bool
    {
        return is_string($tag) && $tag === '';
    }

    /**
     * The template law: a `{{ required … }}` gate on `.Values.image.tag` whose
     * message names the immutable form — and NO silent fallback PATH: neither a
     * `| default` pipe, a `(default …)` call, nor a `.Chart.AppVersion`
     * reference may appear. Every veto anchors on Go-template SYNTAX, never on a
     * bare word: a required message is free to explain "there is no default" in
     * prose (plant 8) while both real fallback spellings — pipe (plant 3) and
     * parenthesized call (plant 9) — are rejected.
     */
    private static function deploymentImageLineIsLoud(string $line, string $immutableForm): bool
    {
        $fallsBackThroughPipe = preg_match(self::TEMPLATE_DEFAULT_PIPE_PATTERN, $line) === 1;
        $fallsBackThroughCall = preg_match(self::TEMPLATE_DEFAULT_CALL_PATTERN, $line) === 1;
        $fallsBackToAppVersion = preg_match(self::TEMPLATE_APP_VERSION_PATTERN, $line) === 1;

        if ($fallsBackThroughPipe || $fallsBackThroughCall || $fallsBackToAppVersion) {
            return false;
        }

        return str_contains($line, '{{ required ')
            && str_contains($line, '.Values.image.tag')
            && str_contains($line, $immutableForm);
    }

    /**
     * The compose law: `${VAR:?message}` where VAR is the service's own image
     * variable and the message body carries the chart's immutable form string.
     */
    private static function composeImageValueIsPinned(
        string $rawImage,
        string $expectedVariable,
        string $immutableForm
    ): bool {
        if (preg_match(self::COMPOSE_PIN_PATTERN, $rawImage, $matches) !== 1) {
            return false;
        }

        if ($matches[1] !== $expectedVariable) {
            return false;
        }

        return str_contains($matches[2], $immutableForm);
    }

    // ------------------------------------------------------------------
    // Parsers / extractors (fail fast, always name the offending file)
    // ------------------------------------------------------------------

    private static function readRequiredFile(string $rel): string
    {
        $contents = is_file(self::ROOT . '/' . $rel) ? file_get_contents(self::ROOT . '/' . $rel) : false;

        if (!is_string($contents)) {
            throw new RuntimeException('Image-pin guard cannot read tracked consumer file: ' . $rel);
        }

        return $contents;
    }

    /**
     * Sorted repo-relative paths of every chart values file discoverable under
     * `k8s/helm` — each chart's shipped `values.yaml` and annotated
     * `values.example.yaml`. Globbed, never hardcoded from the CHARTS map, so a
     * new chart surfaces here before it can slip an unpoliced tag into a
     * production copy-paste.
     *
     * @return list<string>
     */
    private static function chartValuesFilesUnderK8s(): array
    {
        $defaults = self::globRequired(self::ROOT . '/k8s/helm/*/values.yaml');
        $examples = self::globRequired(self::ROOT . '/k8s/helm/*/values.example.yaml');

        $discovered = [];

        foreach (array_merge($defaults, $examples) as $absolute) {
            $discovered[] = substr($absolute, strlen(self::ROOT) + 1);
        }

        sort($discovered);

        return $discovered;
    }

    /**
     * glob() that fails fast instead of yielding false to a foreach.
     *
     * @return list<string>
     */
    private static function globRequired(string $pattern): array
    {
        $matches = glob($pattern);

        if (!is_array($matches)) {
            throw new RuntimeException('Image-pin guard: filesystem glob failed for pattern ' . $pattern);
        }

        return $matches;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function parseYamlDocument(string $rel): array
    {
        $parsed = Yaml::parse(self::readRequiredFile($rel));

        if (!is_array($parsed)) {
            throw new RuntimeException('Image-pin guard: ' . $rel . ' did not parse to a YAML mapping');
        }

        return $parsed;
    }

    /**
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    private static function imageBlock(array $document, string $rel): array
    {
        $image = $document['image'] ?? null;

        if (!is_array($image)) {
            throw new RuntimeException('Image-pin guard: ' . $rel . ' has no top-level `image:` mapping');
        }

        return $image;
    }

    /**
     * @param array<array-key, mixed> $map
     */
    private static function stringKey(array $map, string $key, string $rel): string
    {
        $value = $map[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException("Image-pin guard: {$rel} key `{$key}` is missing or not a string");
        }

        return $value;
    }

    /**
     * The one container-image line of a Helm deployment template: the quoted
     * `image:` value interpolating `.Values.image.repository`.
     */
    private static function extractDeploymentImageLine(string $contents, string $rel): string
    {
        $found = preg_match(self::IMAGE_LINE_PATTERN, $contents, $matches);

        if ($found !== 1) {
            throw new RuntimeException(
                'Image-pin guard: ' . $rel . ' no longer contains a quoted `image:` line'
                . ' interpolating .Values.image.repository — the template was restructured; '
                . 're-check the loud-required-tag law still holds before re-pinning this guard.'
            );
        }

        return $matches[0];
    }

    /**
     * Every declared `image` value of every service, keyed by service name.
     *
     * @param array<array-key, mixed> $document
     * @return array<string, string>
     */
    private static function serviceImageStrings(array $document, string $rel): array
    {
        $services = $document['services'] ?? null;

        if (!is_array($services)) {
            throw new RuntimeException('Image-pin guard: ' . $rel . ' has no `services:` mapping');
        }

        $images = [];

        foreach ($services as $name => $service) {
            if (!is_string($name) || !is_array($service)) {
                throw new RuntimeException("Image-pin guard: {$rel} has a malformed service entry `{$name}`");
            }

            $image = $service['image'] ?? null;

            if ($image === null) {
                continue;
            }

            if (!is_string($image)) {
                throw new RuntimeException("Image-pin guard: {$rel} service `{$name}` image is not a string");
            }

            $images[$name] = $image;
        }

        return $images;
    }

    /**
     * Every string scalar in a parsed YAML document (any depth).
     *
     * @param array<array-key, mixed> $node
     * @return list<string>
     */
    private static function collectScalarStrings(array $node): array
    {
        $strings = [];

        foreach ($node as $value) {
            if (is_array($value)) {
                foreach (self::collectScalarStrings($value) as $nested) {
                    $strings[] = $nested;
                }
                continue;
            }

            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * Census walk: relative paths of every file beneath $relRoot holding a bare
     * `ghcr.io/...:latest` drift literal.
     *
     * @return list<string>
     */
    private static function driftViolationsBeneath(string $relRoot): array
    {
        $root = self::ROOT . '/' . $relRoot;

        if (!is_dir($root)) {
            throw new RuntimeException('Image-pin guard: census root vanished: ' . $relRoot);
        }

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $violations = [];

        foreach ($walk as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (!is_string($contents)) {
                throw new RuntimeException('Image-pin guard: unreadable file under census root: ' . $relRoot);
            }

            $hits = preg_match_all(self::DRIFT_PATTERN, $contents);

            if ($hits === false) {
                throw new RuntimeException('Image-pin guard: drift pattern failed on ' . $file->getPathname());
            }

            if ($hits > 0) {
                $violations[] = substr($file->getPathname(), strlen(self::ROOT) + 1);
            }
        }

        return $violations;
    }
}
