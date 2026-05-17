<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins;

use Phlex\Plugins\Exception\InvalidManifestException;
use Phlex\Plugins\Manifest;
use Phlex\Plugins\ManifestType;
use Phlex\Plugins\ManifestValidationError;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Plugins\Manifest
 * @covers \Phlex\Plugins\ManifestValidationError
 * @covers \Phlex\Plugins\Exception\InvalidManifestException
 */
final class ManifestTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/Plugins';

    public function test_fromJson_parses_valid_lastfm_fixture(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('valid-lastfm.json'));

        $this->assertSame('phlex-plugin-lastfm', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('0.10.0', $manifest->phlexMinServerVersion);
        $this->assertSame('scrobbler', $manifest->type);
        $this->assertSame(ManifestType::Scrobbler, $manifest->manifestType());
        $this->assertSame('Phlex\\Plugins\\Lastfm\\Plugin', $manifest->entry);
        $this->assertSame(
            ['phlex.playback.started', 'phlex.playback.stopped'],
            $manifest->events,
        );
        $this->assertArrayHasKey('api_key', $manifest->settings);
        $this->assertSame('string', $manifest->settings['api_key']['type']);
        $this->assertTrue($manifest->settings['api_key']['required']);
        $this->assertTrue($manifest->settings['api_key']['secret']);
        $this->assertSame(
            'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            $manifest->signature,
        );
    }

    public function test_fromJson_parses_valid_oidc_fixture(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('valid-oidc.json'));

        $this->assertSame('phlex-plugin-oidc-google', $manifest->name);
        $this->assertSame(ManifestType::AuthProvider, $manifest->manifestType());
        $this->assertNull($manifest->signature);
        $this->assertTrue($manifest->settings['client_secret']['secret']);
        $this->assertArrayHasKey('default', $manifest->settings['discovery_url']);
    }

    public function test_fromJson_throws_on_malformed_json(): void
    {
        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('not valid JSON');

        Manifest::fromJson('{not valid json');
    }

    public function test_fromJson_throws_when_root_is_not_object(): void
    {
        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('root must be a JSON object');

        Manifest::fromJson('"a string"');
    }

    public function test_validate_returns_empty_on_valid_manifest(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('valid-lastfm.json'));

        $this->assertSame([], $manifest->validate());
    }

    public function test_validate_returns_empty_on_valid_oidc_manifest(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('valid-oidc.json'));

        $this->assertSame([], $manifest->validate());
    }

    public function test_validate_returns_error_for_missing_name(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('invalid-missing-name.json'));
        $errors = $manifest->validate();

        $this->assertNotEmpty($errors);
        $nameErrors = $this->errorsForField($errors, 'name');
        $this->assertCount(1, $nameErrors);
        $this->assertSame('required', $nameErrors[0]->code);
    }

    public function test_validate_returns_error_for_bad_type(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('invalid-bad-type.json'));
        $errors = $manifest->validate();

        $typeErrors = $this->errorsForField($errors, 'type');
        $this->assertNotEmpty($typeErrors);
        $this->assertSame('enum', $typeErrors[0]->code);
    }

    public function test_validate_returns_error_for_bad_version(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('invalid-bad-version.json'));
        $errors = $manifest->validate();

        $versionErrors = $this->errorsForField($errors, 'version');
        $this->assertNotEmpty($versionErrors);
        $this->assertSame('pattern', $versionErrors[0]->code);
    }

    public function test_signature_can_be_null(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('valid-oidc.json'));

        $this->assertNull($manifest->signature);
        $this->assertSame([], $manifest->validate());
    }

    public function test_signature_must_match_sha256_pattern(): void
    {
        $payload = [
            'name' => 'phlex-plugin-bad-sig',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Plugins\\BadSig\\Plugin',
            'signature' => 'not-a-real-signature',
        ];

        $manifest = Manifest::fromJson((string) json_encode($payload));
        $errors = $manifest->validate();

        $sigErrors = $this->errorsForField($errors, 'signature');
        $this->assertNotEmpty($sigErrors, 'Expected a validation error on the signature field.');
    }

    public function test_unknown_fields_recorded_as_warnings(): void
    {
        $payload = [
            'name' => 'phlex-plugin-extra',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Plugins\\Extra\\Plugin',
            'description' => 'A plugin with an unknown top-level field.',
        ];

        $manifest = Manifest::fromJson((string) json_encode($payload));
        $errors = $manifest->validate();

        $unknownErrors = array_values(array_filter(
            $errors,
            static fn (ManifestValidationError $e): bool => $e->code === 'unknown_field',
        ));
        $this->assertCount(1, $unknownErrors);
        $this->assertSame('description', $unknownErrors[0]->field);
    }

    public function test_toArray_round_trips_the_original_payload(): void
    {
        $raw = $this->loadFixture('valid-lastfm.json');
        $manifest = Manifest::fromJson($raw);

        $this->assertSame(json_decode($raw, true), $manifest->toArray());
    }

    public function test_manifestType_returns_null_for_unknown_type(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('invalid-bad-type.json'));

        $this->assertNull($manifest->manifestType());
    }

    public function test_manifestType_returns_null_when_type_is_missing(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-no-type',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'entry' => 'Phlex\\Plugins\\NoType\\Plugin',
        ]);

        $this->assertNull($manifest->manifestType());
        $this->assertSame('', $manifest->type);

        $errors = $manifest->validate();
        $typeErrors = $this->errorsForField($errors, 'type');
        $this->assertNotEmpty($typeErrors);
    }

    public function test_validate_reports_pattern_error_for_bad_name(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'not-prefixed-correctly',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'scrobbler',
            'entry' => 'Phlex\\Plugins\\Bad\\Plugin',
        ]);

        $errors = $manifest->validate();
        $nameErrors = $this->errorsForField($errors, 'name');
        $this->assertNotEmpty($nameErrors);
        $this->assertSame('pattern', $nameErrors[0]->code);
    }

    public function test_validate_reports_pattern_error_for_bad_entry(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-bad-entry',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'scrobbler',
            'entry' => 'not\\a\\fqcn',
        ]);

        $errors = $manifest->validate();
        $entryErrors = $this->errorsForField($errors, 'entry');
        $this->assertNotEmpty($entryErrors);
        $this->assertSame('pattern', $entryErrors[0]->code);
    }

    public function test_validate_rejects_event_alias_with_bad_format(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-bad-event',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'scrobbler',
            'entry' => 'Phlex\\Plugins\\BadEvent\\Plugin',
            'events' => ['NotAnAlias'],
        ]);

        $errors = $manifest->validate();
        $this->assertNotEmpty($errors);
    }

    public function test_validate_accepts_settings_with_default_values(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-with-defaults',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Plugins\\Defaults\\Plugin',
            'settings' => [
                'retries' => ['type' => 'int', 'required' => false, 'default' => 3],
                'flag' => ['type' => 'bool', 'required' => false, 'default' => true],
            ],
        ]);

        $this->assertSame([], $manifest->validate());
    }

    public function test_settings_with_non_string_keys_are_dropped(): void
    {
        // PHP arrays with numeric string keys round-trip through json
        // as objects; force a malformed shape directly through fromArray.
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-weird-settings',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Plugins\\Weird\\Plugin',
            'settings' => ['ok' => ['type' => 'string'], 'bad' => 'not-an-object'],
        ]);

        $this->assertArrayHasKey('ok', $manifest->settings);
        $this->assertArrayNotHasKey('bad', $manifest->settings);
    }

    public function test_events_with_non_string_entries_are_dropped(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlex-plugin-weird-events',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Plugins\\WeirdEvents\\Plugin',
            'events' => ['phlex.playback.started', 42, null],
        ]);

        $this->assertSame(['phlex.playback.started'], $manifest->events);
    }

    public function test_fromArray_accepts_a_decoded_payload(): void
    {
        $data = [
            'name' => 'phlex-plugin-direct',
            'version' => '2.1.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'metadata-provider',
            'entry' => 'Phlex\\Plugins\\Direct\\Plugin',
        ];

        $manifest = Manifest::fromArray($data);

        $this->assertSame('phlex-plugin-direct', $manifest->name);
        $this->assertSame(ManifestType::MetadataProvider, $manifest->manifestType());
        $this->assertSame([], $manifest->validate());
    }

    public function test_validation_error_to_array(): void
    {
        $error = new ManifestValidationError('signature', 'pattern', 'bad');

        $this->assertSame(
            ['field' => 'signature', 'code' => 'pattern', 'message' => 'bad'],
            $error->toArray(),
        );
    }

    private function loadFixture(string $name): string
    {
        $path = self::FIXTURE_DIR . '/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail(sprintf('Fixture %s could not be read.', $path));
        }

        return $contents;
    }

    /**
     * @param list<ManifestValidationError> $errors
     * @return list<ManifestValidationError>
     */
    private function errorsForField(array $errors, string $field): array
    {
        return array_values(array_filter(
            $errors,
            static fn (ManifestValidationError $e): bool => $e->field === $field,
        ));
    }
}
