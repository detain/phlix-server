<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Manifest;

use Phlix\Plugins\Manifest\ManifestSchema;
use Phlix\Shared\Plugin\Manifest;
use Phlix\Shared\Plugin\ManifestValidationError;
use PHPUnit\Framework\TestCase;

/**
 * Validator tests for the JSON-Schema gate extracted from
 * `Phlix\Plugins\Manifest::validate()` during Step B.3.
 *
 * @covers \Phlix\Plugins\Manifest\ManifestSchema
 */
final class ManifestSchemaTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../../Fixtures/Plugins';

    private ManifestSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new ManifestSchema();
    }

    public function test_validate_returns_empty_on_valid_lastfm_fixture(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('valid-lastfm.json'));
        $this->assertSame([], $this->schema->validate($manifest));
    }

    public function test_validate_returns_empty_on_valid_oidc_fixture(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('valid-oidc.json'));
        $this->assertSame([], $this->schema->validate($manifest));
    }

    public function test_validate_reports_missing_name(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('invalid-missing-name.json'));
        $errors = $this->schema->validate($manifest);

        $nameErrors = $this->errorsForField($errors, 'name');
        $this->assertCount(1, $nameErrors);
        $this->assertSame('required', $nameErrors[0]->code);
    }

    public function test_validate_reports_bad_type(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('invalid-bad-type.json'));
        $errors = $this->schema->validate($manifest);

        $typeErrors = $this->errorsForField($errors, 'type');
        $this->assertNotEmpty($typeErrors);
        $this->assertSame('enum', $typeErrors[0]->code);
    }

    public function test_validate_reports_bad_version(): void
    {
        $manifest = Manifest::fromJson($this->loadFixture('invalid-bad-version.json'));
        $errors = $this->schema->validate($manifest);

        $versionErrors = $this->errorsForField($errors, 'version');
        $this->assertNotEmpty($versionErrors);
        $this->assertSame('pattern', $versionErrors[0]->code);
    }

    public function test_validate_reports_unknown_top_level_field(): void
    {
        $payload = [
            'name' => 'phlix-plugin-extra',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlix\\Plugins\\Extra\\Plugin',
            'description' => 'Not part of the schema.',
        ];

        $manifest = Manifest::fromArray($payload);
        $errors = $this->schema->validate($manifest);

        $unknownErrors = array_values(array_filter(
            $errors,
            static fn (ManifestValidationError $e): bool => $e->code === 'unknown_field',
        ));
        $this->assertCount(1, $unknownErrors);
        $this->assertSame('description', $unknownErrors[0]->field);
    }

    public function test_validate_reports_signature_pattern_violation(): void
    {
        $payload = [
            'name' => 'phlix-plugin-bad-sig',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlix\\Plugins\\BadSig\\Plugin',
            'signature' => 'not-a-real-signature',
        ];

        $manifest = Manifest::fromArray($payload);
        $errors = $this->schema->validate($manifest);

        $sigErrors = $this->errorsForField($errors, 'signature');
        $this->assertNotEmpty($sigErrors);
    }

    public function test_validate_accepts_settings_with_defaults(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-with-defaults',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlix\\Plugins\\Defaults\\Plugin',
            'settings' => [
                'retries' => ['type' => 'int', 'required' => false, 'default' => 3],
                'flag' => ['type' => 'bool', 'required' => false, 'default' => true],
            ],
        ]);

        $this->assertSame([], $this->schema->validate($manifest));
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
