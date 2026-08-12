<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * S313 — `transcoding.segment_format` over the admin settings API.
 *
 * ## What was actually broken
 *
 * The key has existed in `config/transcoding.php` since S56, but it was
 * deliberately absent from `detain/phlix-shared`'s
 * `server-settings.schema.json` — and that absence WAS the refusal.
 * `AdminSettingsController::allowedKeys()` is derived from that schema, so an
 * undeclared key is an unknown key and `update()` 400s the whole request
 * without persisting anything. The only way to change the segment container
 * was to hand-edit a PHP file inside a running container, which is not a
 * rollback path for S60's flag flip.
 *
 * ## The shape of the proof
 *
 * Accept and reject are **each other's control**, which matters more here than
 * usual. A "rejected" assertion on its own is satisfied by a controller that
 * rejects everything — which is precisely the pre-S313 state this step exists
 * to change — and an "accepted" assertion on its own is satisfied by a
 * controller that has stopped validating. So every case below runs against a
 * repository mock that would FAIL the test if `set()` were called when it
 * should not be, and if it were not called when it should be.
 *
 * Auth is not re-tested here: `AdminMiddleware` gates the route upstream and
 * has its own tests.
 *
 * No coverage metadata here, per S141 / phpunit.xml: such a marker discards
 * every other file the test executes, and these cases deliberately drive
 * AdminSettingsController and EncodeSettings together.
 */
final class AdminSettingsSegmentFormatTest extends TestCase
{
    private const KEY = EncodeSettings::SEGMENT_FORMAT_KEY;

    /**
     * @param array<string, mixed> $settings
     */
    private function put(SettingsRepository $repo, array $settings): Response
    {
        $request = new Request();
        $request->body = ['settings' => $settings];

        return (new AdminSettingsController($repo))->update($request, []);
    }

    /**
     * A repository that MUST be written to exactly once, with these arguments.
     */
    private function repoExpectingWrite(string $key, mixed $value, string $type): SettingsRepository
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with($key, $value, $type);
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => [$key => $value],
            'overridden' => [$key],
        ]);

        return $repo;
    }

    /**
     * A repository that must NEVER be written to.
     */
    private function repoExpectingNoWrite(): SettingsRepository
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        return $repo;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = json_decode($response->body, true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * The per-key `errors` map from a 400 body.
     *
     * @return array<string, mixed>
     */
    private function errorsOf(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors, 'A validation failure must carry a per-key errors map.');

        /** @var array<string, mixed> $errors */
        return $errors;
    }

    // ─────────────────────────────────────────────────────────────────
    // the key is writable at all
    // ─────────────────────────────────────────────────────────────────

    /**
     * The precondition for everything else: the key reaches the allow-list.
     *
     * Before S313 this was absent, and its absence is what produced "Unknown
     * setting key." for every value.
     */
    public function test_the_key_is_in_the_schema_derived_writable_allow_list(): void
    {
        $allowed = AdminSettingsController::allowedKeys();

        self::assertArrayHasKey(
            self::KEY,
            $allowed,
            'transcoding.segment_format must be writable; if this fails the vendored '
            . 'detain/phlix-shared schema is older than 0.49.0 or vendor/ is stale '
            . 'against composer.lock.'
        );
        self::assertSame('string', $allowed[self::KEY]);

        // Denominator + control: the allow-list is a real, populated map, not an
        // empty one that would make assertArrayHasKey the only thing measured.
        self::assertGreaterThan(50, count($allowed));
        self::assertArrayHasKey('transcoding.preset', $allowed);
    }

    /**
     * The acceptance criterion's UI half: the key renders in the admin settings
     * form, under `transcoding`.
     *
     * The SPA builds that form from the `meta` block `index()` returns, keyed
     * by `group`, so this is the actual rendering contract rather than a proxy
     * for it.
     */
    public function test_the_key_appears_in_the_settings_payload_under_the_transcoding_group(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => [self::KEY => 'mpegts'],
            'overridden' => [],
        ]);

        $response = (new AdminSettingsController($repo))->index(new Request(), []);
        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response);
        self::assertIsArray($body['data']);
        /** @var array<string, mixed> $data */
        $data = $body['data'];

        self::assertIsArray($data['meta']);
        self::assertArrayHasKey(self::KEY, $data['meta']);
        /** @var array<string, mixed> $meta */
        $meta = $data['meta'][self::KEY];

        self::assertSame('transcoding', $meta['group']);
        self::assertSame(EncodeSettings::SEGMENT_FORMATS, $meta['enum']);
        // ⚠ S60: this is the SCHEMA's `default`, which lives in
        // detain/phlix-shared and still reads `mpegts` while phlix-server's
        // shipped default is `fmp4`. It is annotation only — the EFFECTIVE value
        // in the `values` block comes from config/transcoding.php via
        // SettingsRepository::getDefault(), and there is no reset endpoint that
        // would write this. The divergence is pinned, explained and given a
        // closing procedure in SegmentFormatSchemaEnumDriftTest.
        self::assertSame('mpegts', $meta['default']);
        self::assertNotSame(EncodeSettings::DEFAULT_SEGMENT_FORMAT, $meta['default']);
        self::assertIsString($meta['label']);
        self::assertNotSame('', $meta['label']);
        self::assertIsString($meta['helpText']);
        self::assertNotSame('', $meta['helpText']);
        self::assertFalse($meta['restart']);
        self::assertFalse($meta['secret']);

        // It is also in the `types` map the SPA uses to pick a control.
        self::assertIsArray($data['types']);
        self::assertSame('string', $data['types'][self::KEY]);
    }

    // ─────────────────────────────────────────────────────────────────
    // accept — and its control lives in the next section
    // ─────────────────────────────────────────────────────────────────

    /**
     * THE acceptance criterion: `PUT transcoding.segment_format=fmp4` is
     * accepted and persisted.
     */
    public function test_put_fmp4_is_accepted_and_persisted(): void
    {
        $repo = $this->repoExpectingWrite(self::KEY, 'fmp4', 'string');

        $response = $this->put($repo, [self::KEY => 'fmp4']);

        self::assertSame(200, $response->statusCode);
        $body = $this->decode($response);
        self::assertTrue($body['success']);
        self::assertArrayNotHasKey('errors', $body);
    }

    /**
     * The rollback direction, which is the entire reason S313 lands before S60:
     * once S60 flips the default, an operator has to be able to put it BACK.
     */
    public function test_put_mpegts_is_accepted_and_persisted_so_the_flip_can_be_rolled_back(): void
    {
        $repo = $this->repoExpectingWrite(self::KEY, 'mpegts', 'string');

        $response = $this->put($repo, [self::KEY => 'mpegts']);

        self::assertSame(200, $response->statusCode);
        self::assertTrue($this->decode($response)['success']);
    }

    /**
     * Every declared enum member is accepted — driven from the schema's own
     * enum so a future member cannot be added without this proving it works.
     */
    public function test_every_segment_format_member_is_accepted(): void
    {
        $checked = 0;
        foreach (EncodeSettings::SEGMENT_FORMATS as $member) {
            $repo = $this->repoExpectingWrite(self::KEY, $member, 'string');
            $response = $this->put($repo, [self::KEY => $member]);

            self::assertSame(
                200,
                $response->statusCode,
                sprintf('"%s" is a declared segment format and must be accepted.', $member)
            );
            $checked++;
        }

        self::assertSame(count(EncodeSettings::SEGMENT_FORMATS), $checked);
        self::assertGreaterThan(1, $checked, 'A loop over one value is not coverage.');
    }

    // ─────────────────────────────────────────────────────────────────
    // reject — the control for the section above
    // ─────────────────────────────────────────────────────────────────

    /**
     * THE other half of the acceptance criterion: `cmaf` is rejected.
     *
     * `cmaf` is the interesting rejection rather than a random string: it is a
     * real container name, it is what a reader of the fMP4 documentation might
     * plausibly type, and `EncodeSettings::segmentFormat()` would silently
     * degrade it to `mpegts` — so accepting it would store a value the UI then
     * displays as current while the encoder ignores it.
     */
    public function test_put_cmaf_is_rejected_and_nothing_is_persisted(): void
    {
        self::assertNotContains(
            'cmaf',
            EncodeSettings::SEGMENT_FORMATS,
            'The control value must genuinely be outside the enum.'
        );

        $repo = $this->repoExpectingNoWrite();

        $response = $this->put($repo, [self::KEY => 'cmaf']);

        self::assertSame(400, $response->statusCode);
        $body = $this->decode($response);
        self::assertFalse($body['success']);
        self::assertSame('Validation failed', $body['error']);

        $errors = $this->errorsOf($response);
        self::assertArrayHasKey(self::KEY, $errors);
        $message = $errors[self::KEY];
        self::assertIsString($message);
        self::assertNotSame('', $message);

        // Specifically an ENUM rejection, not "Unknown setting key." — those
        // are different failures and only one of them means the enum is being
        // enforced. Before S313 this same request produced the other one.
        self::assertStringNotContainsStringIgnoringCase(
            'unknown setting key',
            $message,
            'A 400 here must come from the enum constraint, not from the key being unknown.'
        );
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'a real but unsupported container' => ['cmaf'];
        yield 'the empty string' => [''];
        yield 'right value, wrong case' => ['FMP4'];
        yield 'right value with trailing text' => ['fmp4 segments'];
        yield 'a near miss' => ['fmp'];
    }

    /**
     * @dataProvider rejectedValues
     */
    public function test_values_outside_the_enum_are_rejected(mixed $value): void
    {
        $repo = $this->repoExpectingNoWrite();

        $response = $this->put($repo, [self::KEY => $value]);

        self::assertSame(400, $response->statusCode);
        self::assertArrayHasKey(self::KEY, $this->errorsOf($response));
    }

    /**
     * `FMP4` deserves its own note: `EncodeSettings::segmentFormat()` lowercases
     * and trims before comparing, so the ENCODER would honour it. The API does
     * not, because the schema enum is exact.
     *
     * That is deliberate and worth pinning rather than leaving as an accident:
     * the stored value is what the admin UI renders back as the current
     * selection, and a select whose options are `mpegts`/`fmp4` cannot render a
     * stored `FMP4`. Canonical-only storage keeps the control honest. An admin
     * who wants fMP4 picks it from the list.
     */
    public function test_a_non_canonical_case_is_rejected_even_though_the_encoder_would_accept_it(): void
    {
        // The encoder half, stated as a measurement rather than a claim.
        $encoderRepo = $this->createMock(SettingsRepository::class);
        $encoderRepo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === self::KEY ? 'FMP4' : null
        );
        self::assertSame('fmp4', (new EncodeSettings($encoderRepo))->segmentFormat());

        // The API half.
        $response = $this->put($this->repoExpectingNoWrite(), [self::KEY => 'FMP4']);
        self::assertSame(400, $response->statusCode);
    }

    /**
     * A non-string value is rejected by the type gate before the enum is even
     * consulted — a different arm of `update()` from the one above.
     */
    public function test_a_non_string_value_is_rejected_by_the_type_gate(): void
    {
        $response = $this->put($this->repoExpectingNoWrite(), [self::KEY => 4]);

        self::assertSame(400, $response->statusCode);
        self::assertSame(
            'Expected type string.',
            $this->errorsOf($response)[self::KEY]
        );
    }

    /**
     * One bad key rejects the WHOLE request: a batch save carrying a valid
     * `fmp4` alongside an invalid `cmaf` persists neither.
     *
     * This is the case where "accept" and "reject" meet, and it is the one that
     * would break first if `update()` ever started persisting as it validated.
     */
    public function test_a_batch_containing_one_bad_value_persists_nothing(): void
    {
        $repo = $this->repoExpectingNoWrite();

        $response = $this->put($repo, [
            'transcoding.preset' => 'veryfast',
            self::KEY            => 'cmaf',
        ]);

        self::assertSame(400, $response->statusCode);
        $errors = $this->errorsOf($response);
        self::assertArrayHasKey(self::KEY, $errors);
        self::assertArrayNotHasKey('transcoding.preset', $errors);
    }

    /**
     * The same batch with a VALID segment format persists BOTH keys.
     *
     * This is the succeeding control that sits beside the failing batch above:
     * without it, "nothing was persisted" is equally consistent with a
     * controller that never persists anything.
     */
    public function test_the_same_batch_with_a_valid_value_persists_both_keys(): void
    {
        $written = [];
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(
                static function (string $key, mixed $value, string $type) use (&$written): void {
                    $written[$key] = $value;
                }
            );
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $response = $this->put($repo, [
            'transcoding.preset' => 'veryfast',
            self::KEY            => 'fmp4',
        ]);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['transcoding.preset' => 'veryfast', self::KEY => 'fmp4'], $written);
    }

    // ─────────────────────────────────────────────────────────────────
    // S313 is a no-op for anyone who does not call this API
    // ─────────────────────────────────────────────────────────────────

    /**
     * With no override row, the effective value is the SHIPPED DEFAULT and the
     * transcode job fingerprint is `''`.
     *
     * The fingerprint is the load-bearing half. It is folded into
     * `TranscodeManager`'s job key, so a fingerprint that moved would
     * invalidate every cached encode on every installation on upgrade — a
     * fleet-wide re-encode triggered by a schema addition nobody asked for.
     * `''` is the value that keeps the key byte-identical to the pre-settings
     * one, and it is what S313 (a pure schema addition) had to preserve.
     *
     * ⚠ S60 changed the expected VALUE here from `mpegts` to `fmp4` — the
     * default moved — but not the claim, and specifically not the `''`. S60 is
     * the one deploy that DOES invalidate every install's cache, and it does so
     * through `TranscodeManager::JOB_KEY_VERSION` (`v9` → `v10`), never through
     * this method: `fingerprint()` is empty at the shipped default whatever that
     * default is, which is exactly why the bump was required. See
     * {@see \Phlix\Tests\Unit\Media\Transcoding\TranscodeManagerTest::testEnsureHlsJobReuseKeyCarriesFormatVersion()}.
     */
    public function test_declaring_the_key_changes_nothing_for_an_install_that_never_calls_the_api(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturn(null);

        $settings = new EncodeSettings($repo);

        self::assertSame('fmp4', $settings->segmentFormat());
        self::assertSame('', $settings->fingerprint());

        // ... and with no settings repository at all (the DI-degraded path).
        self::assertSame('fmp4', (new EncodeSettings())->segmentFormat());
        self::assertSame('', (new EncodeSettings())->fingerprint());

        // The control that stops the two assertions above passing vacuously:
        // an override DOES move the fingerprint, so `''` is a measurement of
        // "unchanged", not of "fingerprint() always returns empty". ⚠ S60
        // reversed which member is the moving one: `fmp4` is now the free
        // default, so `mpegts` is what has to be overridden to see a hash.
        $overridden = $this->createMock(SettingsRepository::class);
        $overridden->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === self::KEY ? 'mpegts' : null
        );
        self::assertNotSame('', (new EncodeSettings($overridden))->fingerprint());
    }
}
