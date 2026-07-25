<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use Phlix\Shared\Plugin\ManifestType;
use PHPUnit\Framework\TestCase;

/**
 * S48 review r1 Finding 13 — every bundled auth-provider plugin ships a
 * `plugin.json`. Nothing loads these manifests at runtime (the bundled providers
 * are enabled through AuthProviderBootstrapper), but they are the only
 * machine-readable declaration of which settings are SECRET, so GitHub must not
 * be the odd one out.
 */
final class BundledAuthProviderManifestTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function bundledProviders(): array
    {
        return [
            'oidc' => ['Oidc', 'phlix-plugin-oidc', 'Phlix\\Plugins\\Oidc\\Plugin'],
            'ldap' => ['Ldap', 'phlix-plugin-ldap', 'Phlix\\Plugins\\Ldap\\Plugin'],
            'github' => ['Github', 'phlix-plugin-github', 'Phlix\\Plugins\\Github\\Plugin'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $dir): array
    {
        $path = \dirname(__DIR__, 3) . '/src/Plugins/' . $dir . '/plugin.json';
        $this->assertFileExists($path);

        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * @dataProvider bundledProviders
     */
    public function test_manifest_matches_the_bundled_provider_schema(
        string $dir,
        string $name,
        string $entry,
    ): void {
        $manifest = $this->manifest($dir);

        $this->assertSame($name, $manifest['name'] ?? null);
        $this->assertSame($entry, $manifest['entry'] ?? null);
        $this->assertIsString($manifest['version'] ?? null);
        $this->assertIsString($manifest['phlix_min_server_version'] ?? null);
        $this->assertSame([], $manifest['events'] ?? null);

        // `type` must be a real ManifestType enum value.
        $type = is_string($manifest['type'] ?? null) ? $manifest['type'] : '';
        $this->assertNotNull(ManifestType::tryFrom($type), 'plugin type must be a valid enum value');
        $this->assertSame(ManifestType::AuthProvider->value, $type);

        // Every declared setting states its type; secrets are marked as such.
        $settings = is_array($manifest['settings'] ?? null) ? $manifest['settings'] : [];
        $this->assertNotSame([], $settings);
        foreach ($settings as $key => $spec) {
            $this->assertIsString($key);
            $this->assertIsArray($spec);
            $this->assertIsString($spec['type'] ?? null, "setting {$key} must declare a type");
            $this->assertIsBool($spec['required'] ?? null, "setting {$key} must declare required");
        }
    }

    public function test_github_manifest_marks_the_client_secret_as_secret(): void
    {
        $manifest = $this->manifest('Github');
        /** @var array<string, mixed> $settings */
        $settings = is_array($manifest['settings'] ?? null) ? $manifest['settings'] : [];

        /** @var array<string, mixed> $secret */
        $secret = is_array($settings['client_secret'] ?? null) ? $settings['client_secret'] : [];
        $this->assertTrue($secret['secret'] ?? null);
        $this->assertTrue($secret['required'] ?? null);

        /** @var array<string, mixed> $clientId */
        $clientId = is_array($settings['client_id'] ?? null) ? $settings['client_id'] : [];
        $this->assertFalse($clientId['secret'] ?? null);

        // The absolute callback URL (review r1 Finding 1) is declared too.
        $this->assertArrayHasKey('redirect_uri', $settings);
    }

    /**
     * The OIDC manifest gained the same `redirect_uri` key when the relative
     * callback was fixed, so the two stay in step.
     */
    public function test_oidc_manifest_declares_redirect_uri(): void
    {
        $manifest = $this->manifest('Oidc');
        /** @var array<string, mixed> $settings */
        $settings = is_array($manifest['settings'] ?? null) ? $manifest['settings'] : [];

        $this->assertArrayHasKey('redirect_uri', $settings);
    }
}
