<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Shared\Schema\SchemaPaths;
use PHPUnit\Framework\TestCase;

/**
 * S313 — pin the `transcoding.segment_format` schema property against the
 * constant that actually decides what the encoder does.
 *
 * ## Why a whole test file for one equality
 *
 * The setting has two halves that live in two different repositories and can
 * move independently:
 *
 *  - `EncodeSettings::SEGMENT_FORMATS` / {@see EncodeSettings::segmentFormat()}
 *    in THIS repository, which is what the encode path honours. `segmentFormat()`
 *    degrades an unrecognised value to the shipped default rather than passing
 *    it through.
 *  - The `enum` of the `transcoding.segment_format` property in
 *    `detain/phlix-shared`'s `server-settings.schema.json`, which is what
 *    `AdminSettingsController` enforces on PUT and what the admin SPA renders
 *    as a select.
 *
 * Drift is silent in BOTH directions, and neither direction is caught anywhere
 * else:
 *
 *  - **Schema wider than the constant** — a member the constant does not know
 *    is accepted by the API, stored, displayed as the current value, and then
 *    silently ignored by `segmentFormat()`. That is a control that appears to
 *    work and does nothing, i.e. a fail-open.
 *  - **Constant wider than the schema** — a container the encoder supports is
 *    rejected on PUT with "does not match the expected pattern", and the option
 *    never appears in the UI. That is the exact condition S313 exists to remove
 *    (before S313 the schema declared the key at all, so EVERY value was
 *    rejected).
 *
 * ## Why it is not derived from its subject
 *
 * A schema whose values are read off the thing it validates cannot fail. This
 * test therefore does NOT compute one side from the other: it reads the enum
 * out of the vendored JSON, reads the constant out of the class, and compares.
 * Either side moving alone is a failure; moving both together is a deliberate,
 * reviewable change that has to touch two repositories.
 *
 * The independent literal in
 * {@see EncodeSettingsSegmentFormatTest::test_the_shipped_default_is_fmp4()}
 * is the third anchor: it pins the constant itself, so "change both together"
 * cannot quietly become "change all three by regenerating".
 *
 * S60 opened one deliberate divergence between the two repos — the `default`
 * field — because the schema lives in the other repository and could not move in
 * the same commit. **S318 CLOSED it** (phlix-shared v0.49.1, `composer.lock`
 * re-pinned here), so `default` is back under the same plain equality as
 * everything else: see
 * {@see self::test_the_schema_default_is_the_shipped_default_in_both_repositories()}.
 * Nothing in this file tolerates a difference any more, in either direction.
 *
 * No coverage metadata here, per S141 / phpunit.xml: such a marker discards
 * every other file the test executes, and these cases deliberately drive
 * EncodeSettings, the vendored schema and config/transcoding.php together.
 */
final class SegmentFormatSchemaEnumDriftTest extends TestCase
{
    /**
     * The property key under test, taken from the constant rather than typed.
     */
    private const KEY = EncodeSettings::SEGMENT_FORMAT_KEY;

    /**
     * The `transcoding.segment_format` property block from the vendored schema.
     *
     * @return array<string, mixed>
     */
    private function property(): array
    {
        $path = SchemaPaths::serverSettings();
        self::assertFileExists(
            $path,
            'The vendored detain/phlix-shared server-settings schema is missing; '
            . 'nothing below can be measured.'
        );

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'server-settings.schema.json must decode to an object.');
        self::assertArrayHasKey('properties', $decoded);
        self::assertIsArray($decoded['properties']);

        self::assertArrayHasKey(
            self::KEY,
            $decoded['properties'],
            sprintf(
                'The vendored schema does not declare "%s". Either the composer.lock re-pin to '
                . 'detain/phlix-shared >= 0.49.0 is missing, or vendor/ is stale against the '
                . 'locked reference. Until it is declared, AdminSettingsController refuses the '
                . 'key on PUT and S60 has no rollback path.',
                self::KEY
            )
        );

        $property = $decoded['properties'][self::KEY];
        self::assertIsArray($property);

        /** @var array<string, mixed> $property */
        return $property;
    }

    /**
     * THE drift assertion: the schema enum IS the constant, member for member
     * and in the same order.
     */
    public function test_schema_enum_equals_encode_settings_segment_formats(): void
    {
        $property = $this->property();

        self::assertArrayHasKey(
            'enum',
            $property,
            sprintf(
                'Property "%s" must declare an enum. Without one AdminSettingsController accepts '
                . 'ANY string for it — including one segmentFormat() silently ignores.',
                self::KEY
            )
        );

        self::assertSame(
            EncodeSettings::SEGMENT_FORMATS,
            $property['enum'],
            sprintf(
                'The "%s" schema enum and EncodeSettings::SEGMENT_FORMATS have diverged. '
                . 'A member only the schema knows is accepted over the admin API and then '
                . 'ignored by the encoder (a fail-open); a member only the constant knows is '
                . 'rejected on PUT and invisible in the admin UI. Change both, in the same PR, '
                . 'across both repositories.',
                self::KEY
            )
        );

        // Not vacuous: the comparison above is only meaningful against a
        // non-empty, string-membered list. An empty enum would satisfy an
        // equality against an empty constant while accepting nothing at all.
        self::assertNotSame([], EncodeSettings::SEGMENT_FORMATS);
        self::assertContainsOnly('string', EncodeSettings::SEGMENT_FORMATS);
    }

    /**
     * Every enum member must be a value `segmentFormat()` actually returns.
     *
     * The equality above compares two lists; this drives the live method with
     * each declared member and proves the round trip, so a schema member that
     * degrades to the default cannot pass as supported.
     */
    public function test_every_declared_enum_member_survives_segment_format_round_trip(): void
    {
        $enum = $this->property()['enum'];
        self::assertIsArray($enum);

        $checked = 0;
        foreach ($enum as $member) {
            self::assertIsString($member);

            $repo = $this->createMock(\Phlix\Admin\SettingsRepository::class);
            $repo->method('getEffective')->willReturnCallback(
                /** @return mixed */
                static fn (string $key) => $key === self::KEY ? $member : null
            );

            self::assertSame(
                $member,
                (new EncodeSettings($repo))->segmentFormat(),
                sprintf(
                    'The schema offers "%s" for %s, but segmentFormat() does not return it — '
                    . 'an admin selecting that option would silently get the default instead.',
                    $member,
                    self::KEY
                )
            );
            $checked++;
        }

        // Print the denominator: an enum that had somehow become empty would
        // make the loop above a no-op that reads as a pass.
        self::assertSame(
            count(EncodeSettings::SEGMENT_FORMATS),
            $checked,
            'Every SEGMENT_FORMATS member must have been round-tripped.'
        );
        self::assertGreaterThan(1, $checked, 'A single-member enum is not a choice.');
    }

    /**
     * The CONTROL for the round trip above: a value outside the enum must NOT
     * survive it.
     *
     * Without this, `test_every_declared_enum_member_survives_...` would still
     * pass against a `segmentFormat()` rewritten to echo its input, which would
     * make the enum the only guard and this whole file decorative.
     */
    public function test_a_value_outside_the_enum_does_not_survive_the_round_trip(): void
    {
        $outsider = 'cmaf';
        self::assertNotContains(
            $outsider,
            EncodeSettings::SEGMENT_FORMATS,
            'The control value must genuinely be outside the enum.'
        );

        $repo = $this->createMock(\Phlix\Admin\SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === self::KEY ? $outsider : null
        );

        self::assertSame(
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            (new EncodeSettings($repo))->segmentFormat(),
            'segmentFormat() must degrade an unknown container to the shipped default.'
        );
    }

    /**
     * THE THREE-WAY DEFAULT PIN: the constant the encoder falls back to, the
     * config literal the admin API reports, and the schema `default` the admin
     * SPA renders must all be the same string.
     *
     * ## History, because the shape of this case has changed twice
     *
     * S313 shipped it as a plain equality. S60 flipped the phlix-server default
     * to `fmp4` while the schema — which lives in **`detain/phlix-shared`**, a
     * different repository — still said `mpegts`, so for the length of that gap
     * this case INVERTED: it asserted the two DISAGREED, deliberately, so that
     * the release which caught phlix-shared up would red it and could not be
     * forgotten. **S318 is that release** (phlix-shared v0.49.1, `composer.lock`
     * re-pinned in the same commit as this edit), and the gap is closed. The
     * plain equality is back, and there is no tolerance left anywhere in this
     * file for the two sides differing.
     *
     * ## Why the `default` field is worth a gate at all
     *
     * It is **documentation-grade, not functional**, and that was checked rather
     * than assumed:
     *
     *  - the EFFECTIVE value comes from `config/transcoding.php` via
     *    `SettingsRepository::getDefault()` → `resolveDefault()`, which reads the
     *    config files and never opens the schema. So the encoder and the
     *    `GET /api/v1/admin/settings` `values` block both say `fmp4`.
     *  - the schema `default` reaches only `AdminSettingsController`'s `meta`
     *    block (`loadSchemaMeta()`, `'default' => $def['default']`), which the
     *    SPA renders as annotation. **There is no reset-to-default endpoint** —
     *    the route table is `GET` + `PUT` only — so nothing WRITES the schema
     *    default anywhere.
     *
     * That is precisely why it needs pinning rather than ignoring: nothing
     * BREAKS when it drifts, so nothing else would ever notice. What it costs is
     * an operator reading an annotation that contradicts the effective value
     * beside it and "correcting" a default nobody told them had moved — which is
     * the incident S318 removed.
     *
     * ⚠ The S313 docblock this case grew out of stated that
     * "`AdminSettingsController::index()` reports the schema default as the
     * effective value for any key with no override row". That is **not correct**
     * — `index()` reports `getEffectiveMany()`, which is config-sourced. The
     * claim is corrected here rather than repeated.
     *
     * ## What a red here means now
     *
     * One of the three moved without the other two. Fix whichever is wrong; do
     * NOT relax the comparison. Moving all three together is a legitimate,
     * reviewable change that has to touch two repositories and a release — and
     * the two `'fmp4'` literals below are what force it to be explicit rather
     * than something a regeneration could do quietly.
     *
     * The peer pin on the same field, reached through the CONTROLLER rather than
     * by reading the JSON, is
     * `AdminSettingsSegmentFormatTest::test_the_key_appears_in_the_settings_payload_under_the_transcoding_group()`.
     */
    public function test_the_schema_default_is_the_shipped_default_in_both_repositories(): void
    {
        $property = $this->property();

        // The phlix-server side of the pair, by literal. This is the value that
        // actually decides what the encoder does.
        self::assertSame('fmp4', EncodeSettings::DEFAULT_SEGMENT_FORMAT);

        // The two halves that MUST agree, because both are phlix-server's and
        // both feed the same answer: the constant the encode path falls back to,
        // and the config literal `SettingsRepository::getDefault()` reads.
        $config = require dirname(__DIR__, 4) . '/config/transcoding.php';
        self::assertIsArray($config);
        self::assertSame(
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            $config['segment_format'] ?? null,
            'config/transcoding.php and EncodeSettings::DEFAULT_SEGMENT_FORMAT must never drift: '
            . 'the config file is what SettingsRepository::getDefault() reports as the effective '
            . 'value, the constant is what the encoder falls back to.'
        );
        self::assertSame('fmp4', $config['segment_format'] ?? null);

        // ⚠ THE CROSS-REPO ASSERTION. Two independently-authored operands: a
        // constant in THIS repository, and a field decoded out of the vendored
        // `detain/phlix-shared` JSON. Neither is computed from the other, so
        // either side moving alone reds this — the schema rolling back to
        // `mpegts`, the constant rolling back to `mpegts`, or either going to
        // some third value.
        self::assertSame(
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            $property['default'] ?? null,
            'The vendored phlix-shared schema default and EncodeSettings::DEFAULT_SEGMENT_FORMAT '
            . 'have diverged. The schema default is what the admin SPA renders as the annotation '
            . 'beside the control; the constant is what the encoder falls back to. An operator '
            . 'reading one while the server obeys the other will "correct" a default nobody told '
            . 'them had moved. Change both, in the same PR, across both repositories — and note '
            . 'that a phlix-shared change needs a release and a composer.lock re-pin here.'
        );

        // The same equality restated against the literal, so that "change both
        // together" cannot become "regenerate one from the other and stay
        // green": a regeneration that moved BOTH sides to `mpegts` would satisfy
        // the assertion above and fails here.
        self::assertSame('fmp4', $property['default'] ?? null);

        // Whatever the schema advertises must also be a container the encoder
        // understands, or the annotation names something unselectable.
        self::assertContains($property['default'] ?? null, EncodeSettings::SEGMENT_FORMATS);
    }

    /**
     * The rendering contract the acceptance criterion names: the key has to
     * appear in the admin settings UI, under `transcoding`.
     *
     * The SPA builds the settings form from the `meta` block
     * `AdminSettingsController::index()` returns, grouping by `group`, so a
     * property with the wrong group renders in the wrong section and one with
     * no label/helpText renders an unexplained control.
     */
    public function test_property_carries_the_metadata_the_admin_ui_renders(): void
    {
        $property = $this->property();

        self::assertSame('transcoding', $property['group'] ?? null);
        self::assertSame('string', $property['type'] ?? null);

        foreach (['label', 'helpText', 'description'] as $field) {
            self::assertIsString($property[$field] ?? null, sprintf('"%s" must be a string.', $field));
            self::assertNotSame('', $property[$field]);
        }

        // Every enum member needs a label and an option help string, or the
        // select renders a raw slug the admin has to guess at.
        foreach (['enumLabels', 'optionHelp'] as $map) {
            self::assertIsArray($property[$map] ?? null, sprintf('"%s" must be an object.', $map));
            foreach (EncodeSettings::SEGMENT_FORMATS as $member) {
                self::assertArrayHasKey($member, $property[$map]);
                self::assertNotSame('', $property[$map][$member]);
            }
            self::assertCount(
                count(EncodeSettings::SEGMENT_FORMATS),
                $property[$map],
                sprintf('"%s" must cover exactly the enum members — no stale entries.', $map)
            );
        }

        // The cost of changing the setting is the whole reason the helpText is
        // long: a fresh job id, a fresh job directory, and a re-encode of
        // anything played afterwards. An admin who is not told that will read
        // the control as free.
        $helpText = (string) $property['helpText'];
        foreach (['job id', 'job directory', 're-encoded'] as $phrase) {
            self::assertStringContainsString(
                $phrase,
                $helpText,
                sprintf(
                    'helpText must state the cost of changing this setting; "%s" is missing.',
                    $phrase
                )
            );
        }
    }

    /**
     * `restart: false` is a promise, and it has to be true.
     *
     * `EncodeSettings::read()` calls `SettingsRepository::getEffective()` at
     * encode time, not at worker boot, so the next transcode picks the value
     * up. Declaring `restart: true` would tell the admin to bounce the service
     * for no reason; the reverse — declaring `false` for a boot-captured value
     * — is the false advertising the settings programme exists to remove.
     */
    public function test_property_declares_restart_false_because_the_value_is_read_at_encode_time(): void
    {
        self::assertFalse($this->property()['restart'] ?? null);

        // Proof rather than assertion: a repository whose answer changes
        // between calls changes the answer segmentFormat() gives, with no
        // restart and no new EncodeSettings instance in between.
        $answers = [EncodeSettings::FORMAT_MPEGTS, EncodeSettings::FORMAT_FMP4];
        $repo = $this->createMock(\Phlix\Admin\SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static function (string $key) use (&$answers) {
                if ($key !== self::KEY) {
                    return null;
                }

                return array_shift($answers) ?? EncodeSettings::FORMAT_FMP4;
            }
        );

        $settings = new EncodeSettings($repo);
        self::assertSame(EncodeSettings::FORMAT_MPEGTS, $settings->segmentFormat());
        self::assertSame(EncodeSettings::FORMAT_FMP4, $settings->segmentFormat());
    }
}
